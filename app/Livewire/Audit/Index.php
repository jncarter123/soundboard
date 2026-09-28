<?php

namespace App\Livewire\Audit;

use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only view of the audit log. Nothing here edits or deletes entries;
 * old ones are removed only by the scheduled `activitylog:clean`.
 *
 * With audit.read it shows everything. Team owners see only their teams'
 * entries (their apps and memberships), without clients' IPs and browsers.
 */
class Index extends Component
{
    use WithPagination;

    /** Event filters, grouped as they appear in the dropdown. */
    public const EVENTS = [
        'Sign-in' => ['auth.login' => 'Signed in', 'auth.failed' => 'Failed sign-in', 'auth.logout' => 'Signed out'],
        'Passkeys' => [
            'passkey.added' => 'Added passkey',
            'passkey.removed' => 'Removed passkey',
            'auth.email_code_sent' => 'Sent email code',
            'auth.email_code_failed' => 'Too many wrong email codes',
            'auth.setup_link_created' => 'Created setup link (CLI)',
            'auth.setup_link_used' => 'Opened setup link',
        ],
        'Apps' => ['credentials.viewed' => 'Viewed credentials', 'credentials.regenerated' => 'Regenerated credentials'],
        'Users & roles' => [
            'user.roles_changed' => 'Changed user roles',
            'role.permissions_changed' => 'Changed role permissions',
            'account.password_changed' => 'Changed own password',
            'user.password_changed' => "Changed another user's password",
            'user.password_reset' => 'Reset password (CLI)',
        ],
        'Teams' => [
            'team.member_added' => 'Added team member',
            'team.member_role_changed' => 'Changed team member role',
            'team.member_removed' => 'Removed team member',
            'team.webhook_secret_viewed' => 'Viewed team webhook secret',
            'team.webhook_secret_regenerated' => 'Regenerated team webhook secret',
        ],
        'API tokens' => ['token.created' => 'Created token', 'token.revoked' => 'Revoked token'],
        'Records' => ['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted'],
    ];

    /** The event groups that can have a team: what team owners can filter by. */
    public const TEAM_EVENT_GROUPS = ['Apps', 'Teams', 'Records'];

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $search = '';

    public function updatingEvent(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function subjectLabel(Activity $entry): ?string
    {
        $subject = $entry->subject;

        return match (true) {
            $entry->subject_type === null => null,
            $subject instanceof ReverbApp => "{$subject->name} ({$subject->app_id})",
            $subject instanceof User => "{$subject->name} <{$subject->email}>",
            $subject instanceof Role => "Role {$subject->name}",
            $subject instanceof Team => "Team {$subject->name}",
            // Deleted since: the entry outlives the record.
            default => class_basename($entry->subject_type)." #{$entry->subject_id} (deleted)",
        };
    }

    public static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => $value === [] ? '[]' : implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)),
            default => (string) $value,
        };
    }

    public function render()
    {
        $user = auth()->user();
        $everything = $user->can('audit.read');

        $entries = Activity::query()
            ->with(['causer', 'subject'])
            ->unless($everything, fn ($q) => $q->whereIn('team_id', $user->ownedTeamIds()))
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->search !== '', function ($q) use ($everything) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($q) => $q
                    ->where('description', 'like', $term)
                    // Properties hold clients' IPs, which team owners don't see.
                    ->when($everything, fn ($q) => $q->orWhere('properties', 'like', $term))
                    ->orWhereHasMorph('causer', [User::class], fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHasMorph('subject', [ReverbApp::class, User::class, Role::class, Team::class], fn ($q) => $q->where('name', 'like', $term)));
            })
            ->latest('id')
            ->paginate(50);

        return view('livewire.audit.index', [
            'entries' => $entries,
            'events' => $everything ? self::EVENTS : array_intersect_key(self::EVENTS, array_flip(self::TEAM_EVENT_GROUPS)),
            'showClient' => $everything,
            'scoped' => ! $everything,
        ])->layout('components.layouts.app');
    }
}
