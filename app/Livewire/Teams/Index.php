<?php

namespace App\Livewire\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Teams and their members. A team role gives its members, on the team's
 * apps, the access the matching global permissions give on every app, so
 * with teams.manage, assigning or removing a role requires holding those
 * permissions. A team's owners manage its members too; see mayAssign().
 */
class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingTeamId = null;

    public string $teamName = '';

    /** The team whose members are shown. */
    #[Locked]
    public ?int $membersTeamId = null;

    public string $newMemberEmail = '';

    public string $newMemberRole = 'viewer';

    public function newTeam(): void
    {
        $this->authorize('teams.manage');
        $this->editingTeamId = null;
        $this->teamName = '';
        $this->showForm = true;
    }

    public function editTeam(int $teamId): void
    {
        $this->authorize('teams.manage');
        $team = Team::findOrFail($teamId);
        $this->editingTeamId = $team->id;
        $this->teamName = $team->name;
        $this->showForm = true;
    }

    public function saveTeam(): void
    {
        $this->authorize('teams.manage');
        $this->validate([
            'teamName' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->ignore($this->editingTeamId)],
        ]);

        if ($this->editingTeamId) {
            Team::findOrFail($this->editingTeamId)->update(['name' => $this->teamName]);
        } else {
            Team::create(['name' => $this->teamName]);
        }

        $this->cancelForm();
    }

    /**
     * Deleting a team removes every member's access, so it's checked like
     * removing each of them. A team that still owns apps can't be deleted:
     * they would silently become server-wide apps.
     */
    public function deleteTeam(int $teamId): void
    {
        $this->authorize('teams.manage');
        $team = Team::withCount('apps')->with('members')->findOrFail($teamId);

        if ($team->apps_count > 0) {
            throw new AuthorizationException('Move or delete the team\'s apps before deleting it.');
        }

        foreach ($team->members as $member) {
            if (! $this->holdsRole(TeamRole::from($member->pivot->role))) {
                throw new AuthorizationException('You cannot delete a team whose roles have permissions you do not hold.');
            }
        }

        $team->delete();

        if ($this->membersTeamId === $teamId) {
            $this->closeMembers();
        }
    }

    public function showMembers(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $this->authorize('view', $team);
        $this->membersTeamId = $team->id;
        $this->reset(['newMemberEmail', 'newMemberRole']);
        $this->resetValidation();
    }

    public function closeMembers(): void
    {
        $this->membersTeamId = null;
        $this->reset(['newMemberEmail', 'newMemberRole']);
        $this->resetValidation();
    }

    public function addMember(): void
    {
        $team = Team::findOrFail($this->membersTeamId);
        $this->authorize('manageMembers', $team);

        $this->validate([
            'newMemberEmail' => ['required', 'email', Rule::exists('users', 'email')],
            'newMemberRole' => ['required', Rule::enum(TeamRole::class)],
        ], [
            'newMemberEmail.exists' => 'No user has that email address.',
        ]);

        $user = User::where('email', $this->newMemberEmail)->firstOrFail();

        if ($team->members()->whereKey($user->id)->exists()) {
            $this->addError('newMemberEmail', 'That user is already a member.');

            return;
        }

        $role = TeamRole::from($this->newMemberRole);

        if (! $this->mayAssign($team, $role)) {
            $this->addError('newMemberRole', 'You cannot assign a role with permissions you do not hold.');

            return;
        }

        $team->members()->attach($user, ['role' => $role->value]);
        Audit::log('team.member_added', 'Added team member', $team, ['user' => $user->email, 'role' => $role->value]);

        $this->reset(['newMemberEmail', 'newMemberRole']);
    }

    public function changeMemberRole(int $userId, string $role): void
    {
        $team = Team::findOrFail($this->membersTeamId);
        $this->authorize('manageMembers', $team);
        $member = $team->members()->findOrFail($userId);
        $from = TeamRole::from($member->pivot->role);
        $to = TeamRole::tryFrom($role) ?? throw new AuthorizationException('Unknown team role.');

        if ($from === $to) {
            return;
        }

        if (! $this->mayAssign($team, $from) || ! $this->mayAssign($team, $to)) {
            $this->addError('membership', 'You cannot assign or remove a role with permissions you do not hold.');

            return;
        }

        if ($this->removesLastOwner($team, $from)) {
            return;
        }

        $team->members()->updateExistingPivot($userId, ['role' => $to->value]);
        Audit::log('team.member_role_changed', 'Changed team member role', $team, [
            'user' => $member->email,
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    public function removeMember(int $userId): void
    {
        $team = Team::findOrFail($this->membersTeamId);
        $this->authorize('manageMembers', $team);
        $member = $team->members()->findOrFail($userId);
        $role = TeamRole::from($member->pivot->role);

        if (! $this->mayAssign($team, $role)) {
            $this->addError('membership', 'You cannot remove a role with permissions you do not hold.');

            return;
        }

        if ($this->removesLastOwner($team, $role)) {
            return;
        }

        $team->members()->detach($userId);
        Audit::log('team.member_removed', 'Removed team member', $team, ['user' => $member->email, 'role' => $role->value]);
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingTeamId = null;
        $this->teamName = '';
        $this->resetValidation();
    }

    private function holdsRole(TeamRole $role): bool
    {
        return auth()->user()->holdsAllPermissions($role->permissions());
    }

    /**
     * Whether the current user may give or take away this role in the team.
     * With teams.manage, only roles whose permissions they hold. A team's
     * owners may assign any role in it: a team role reaches only the team's
     * apps, which owners already fully control.
     */
    private function mayAssign(Team $team, TeamRole $role): bool
    {
        $user = auth()->user();

        return ($user->can('teams.manage') && $this->holdsRole($role))
            || $user->teamRole($team->id) === TeamRole::Owner;
    }

    /**
     * A team keeps at least one owner unless someone with teams.manage, who
     * can add one back, says otherwise. Adds a form error and returns true
     * if the change would leave it with none.
     */
    private function removesLastOwner(Team $team, TeamRole $from): bool
    {
        if ($from !== TeamRole::Owner || auth()->user()->can('teams.manage')) {
            return false;
        }

        if ($team->members()->wherePivot('role', TeamRole::Owner->value)->count() > 1) {
            return false;
        }

        $this->addError('membership', 'A team must keep at least one owner.');

        return true;
    }

    public function render()
    {
        $user = auth()->user();

        // Owners without teams.read see just the teams they own.
        $teams = Team::withCount(['members', 'apps'])
            ->unless($user->can('teams.read'), fn ($q) => $q->whereIn('id', $user->ownedTeamIds()))
            ->orderBy('name')
            ->get();
        $membersTeam = $this->membersTeamId
            ? Team::with(['members' => fn ($q) => $q->orderBy('name'), 'apps' => fn ($q) => $q->orderBy('name')])->find($this->membersTeamId)
            : null;

        if ($membersTeam && ! $user->can('view', $membersTeam)) {
            $membersTeam = null;
        }

        return view('livewire.teams.index', [
            'teams' => $teams,
            'membersTeam' => $membersTeam,
            'roles' => TeamRole::cases(),
        ])->layout('components.layouts.app');
    }
}
