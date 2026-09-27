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
 * assigning or removing a role requires holding those permissions.
 */
class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingTeamId = null;

    public string $teamName = '';

    /** The team whose members are shown. */
    #[Locked]
    public ?int $membersTeamId = null;

    public string $newMemberId = '';

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
        $this->authorize('teams.read');
        $this->membersTeamId = Team::findOrFail($teamId)->id;
        $this->reset(['newMemberId', 'newMemberRole']);
        $this->resetValidation();
    }

    public function closeMembers(): void
    {
        $this->membersTeamId = null;
        $this->reset(['newMemberId', 'newMemberRole']);
        $this->resetValidation();
    }

    public function addMember(): void
    {
        $this->authorize('teams.manage');
        $team = Team::findOrFail($this->membersTeamId);

        $this->validate([
            'newMemberId' => ['required', 'integer', Rule::exists('users', 'id'), Rule::unique('team_user', 'user_id')->where('team_id', $team->id)],
            'newMemberRole' => ['required', Rule::enum(TeamRole::class)],
        ], [
            'newMemberId.required' => 'Choose a user.',
            'newMemberId.unique' => 'That user is already a member.',
        ]);

        $role = TeamRole::from($this->newMemberRole);

        if (! $this->holdsRole($role)) {
            $this->addError('newMemberRole', 'You cannot assign a role with permissions you do not hold.');

            return;
        }

        $user = User::findOrFail($this->newMemberId);
        $team->members()->attach($user, ['role' => $role->value]);
        Audit::log('team.member_added', 'Added team member', $team, ['user' => $user->email, 'role' => $role->value]);

        $this->reset(['newMemberId', 'newMemberRole']);
    }

    public function changeMemberRole(int $userId, string $role): void
    {
        $this->authorize('teams.manage');
        $team = Team::findOrFail($this->membersTeamId);
        $member = $team->members()->findOrFail($userId);
        $from = TeamRole::from($member->pivot->role);
        $to = TeamRole::tryFrom($role) ?? throw new AuthorizationException('Unknown team role.');

        if ($from === $to) {
            return;
        }

        if (! $this->holdsRole($from) || ! $this->holdsRole($to)) {
            $this->addError('membership', 'You cannot assign or remove a role with permissions you do not hold.');

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
        $this->authorize('teams.manage');
        $team = Team::findOrFail($this->membersTeamId);
        $member = $team->members()->findOrFail($userId);
        $role = TeamRole::from($member->pivot->role);

        if (! $this->holdsRole($role)) {
            $this->addError('membership', 'You cannot remove a role with permissions you do not hold.');

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

    public function render()
    {
        $teams = Team::withCount(['members', 'apps'])->orderBy('name')->get();
        $membersTeam = $this->membersTeamId
            ? Team::with(['members' => fn ($q) => $q->orderBy('name'), 'apps' => fn ($q) => $q->orderBy('name')])->find($this->membersTeamId)
            : null;

        return view('livewire.teams.index', [
            'teams' => $teams,
            'membersTeam' => $membersTeam,
            'candidates' => $membersTeam
                ? User::whereNotIn('id', $membersTeam->members->modelKeys())->orderBy('name')->get(['id', 'name', 'email'])
                : collect(),
            'roles' => TeamRole::cases(),
        ])->layout('components.layouts.app');
    }
}
