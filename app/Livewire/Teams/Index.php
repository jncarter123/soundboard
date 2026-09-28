<?php

namespace App\Livewire\Teams;

use App\Alerts\AlertMonitor;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\PublicUrlGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
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

    /** The team whose alert destinations are shown. */
    #[Locked]
    public ?int $alertsTeamId = null;

    public string $alertMailTo = '';

    public string $alertWebhookUrl = '';

    /**
     * Whether to show the webhook secret. Only this flag lives in component
     * state; the secret is loaded in render() so it never ends up in the
     * serialized Livewire snapshot.
     */
    #[Locked]
    public bool $revealSecret = false;

    /** @var list<array{label: string, target: string, error: string|null}> */
    #[Locked]
    public array $testResults = [];

    public bool $alertsSaved = false;

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

    public function showAlerts(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $this->authorize('manageAlerts', $team);
        $this->closeAlerts();
        $this->alertsTeamId = $team->id;
        $this->alertMailTo = implode(', ', $team->alert_mail_to ?? []);
        $this->alertWebhookUrl = (string) $team->alert_webhook_url;
    }

    public function closeAlerts(): void
    {
        $this->reset(['alertsTeamId', 'alertMailTo', 'alertWebhookUrl', 'revealSecret', 'testResults', 'alertsSaved']);
        $this->resetValidation();
    }

    public function saveAlerts(): void
    {
        $team = Team::findOrFail($this->alertsTeamId);
        $this->authorize('manageAlerts', $team);
        $this->reset(['testResults', 'alertsSaved']);
        $this->resetValidation();

        $emails = $this->parseEmails();
        $url = trim($this->alertWebhookUrl) === '' ? null : trim($this->alertWebhookUrl);

        $validator = validator(
            ['emails' => $emails, 'url' => $url],
            ['emails' => ['array', 'max:10'], 'emails.*' => ['email'], 'url' => ['nullable', 'url', 'max:2048']],
            ['emails.max' => 'Up to 10 email addresses.', 'emails.*.email' => ':input is not an email address.', 'url.url' => 'Enter a full URL, starting with https://.'],
        )->after(function ($validator) use ($url) {
            if ($url !== null && ! config('alerts.team_webhooks_allow_private')
                && $problem = app(PublicUrlGuard::class)->problem($url)) {
                $validator->errors()->add('url', $problem);
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                $this->addError(str_starts_with($key, 'emails') ? 'alertMailTo' : 'alertWebhookUrl', $messages[0]);
            }

            return;
        }

        $team->update([
            'alert_mail_to' => $emails === [] ? null : $emails,
            'alert_webhook_url' => $url,
            // A webhook gets its own secret when first set, and loses it when removed.
            'alert_webhook_secret' => $url === null ? null : ($team->alert_webhook_secret ?? Team::generateWebhookSecret()),
        ]);

        $this->alertMailTo = implode(', ', $emails);
        $this->alertWebhookUrl = (string) $url;
        $this->alertsSaved = true;
    }

    public function revealWebhookSecret(): void
    {
        $team = Team::findOrFail($this->alertsTeamId);
        $this->authorize('manageAlerts', $team);

        if ($team->alert_webhook_secret !== null) {
            Audit::log('team.webhook_secret_viewed', 'Viewed team webhook secret', $team);
            $this->revealSecret = true;
        }
    }

    public function regenerateWebhookSecret(): void
    {
        $team = Team::findOrFail($this->alertsTeamId);
        $this->authorize('manageAlerts', $team);

        if ($team->alert_webhook_url === null) {
            return;
        }

        $team->update(['alert_webhook_secret' => Team::generateWebhookSecret()]);
        Audit::log('team.webhook_secret_regenerated', 'Regenerated team webhook secret', $team);
        $this->revealSecret = true;
    }

    /**
     * Send a test alert to the team's saved destinations. Rate limited, so
     * a team can't use Soundboard to flood an inbox or endpoint.
     */
    public function sendTestAlert(): void
    {
        $team = Team::findOrFail($this->alertsTeamId);
        $this->authorize('manageAlerts', $team);
        $this->reset(['testResults', 'alertsSaved']);
        $this->resetValidation();

        $destinations = AlertMonitor::destinations($team, includeServer: false);

        if ($destinations === []) {
            $this->addError('alertTest', 'Save an email address or webhook first.');

            return;
        }

        $key = "team-test-alert:{$team->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('alertTest', 'Too many test alerts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');

            return;
        }

        RateLimiter::hit($key, 600);
        $this->testResults = AlertMonitor::sendTest($destinations, $team);
    }

    /**
     * @return list<string>
     */
    private function parseEmails(): array
    {
        return array_values(array_unique(array_filter(preg_split('/[\s,;]+/', strtolower($this->alertMailTo)))));
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

        $alertsTeam = $this->alertsTeamId ? Team::find($this->alertsTeamId) : null;

        if ($alertsTeam && ! $user->can('manageAlerts', $alertsTeam)) {
            $alertsTeam = null;
        }

        return view('livewire.teams.index', [
            'teams' => $teams,
            'membersTeam' => $membersTeam,
            'alertsTeam' => $alertsTeam,
            'webhookSecret' => $alertsTeam && $this->revealSecret ? $alertsTeam->alert_webhook_secret : null,
            'serverGetsTeamAlerts' => (bool) config('alerts.server_gets_team_alerts'),
            'roles' => TeamRole::cases(),
        ])->layout('components.layouts.app');
    }
}
