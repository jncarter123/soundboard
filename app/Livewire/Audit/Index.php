<?php

namespace App\Livewire\Audit;

use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only view of the audit log. Nothing here edits or deletes entries;
 * old ones are removed only by the scheduled `activitylog:clean`.
 */
class Index extends Component
{
    use WithPagination;

    /** Event filters, grouped as they appear in the dropdown. */
    public const EVENTS = [
        'Sign-in' => ['auth.login' => 'Signed in', 'auth.failed' => 'Failed sign-in', 'auth.logout' => 'Signed out'],
        'Apps' => ['credentials.viewed' => 'Viewed credentials', 'credentials.regenerated' => 'Regenerated credentials'],
        'Users & roles' => [
            'user.roles_changed' => 'Changed user roles',
            'role.permissions_changed' => 'Changed role permissions',
            'account.password_changed' => 'Changed own password',
            'user.password_changed' => "Changed another user's password",
            'user.password_reset' => 'Reset password (CLI)',
        ],
        'API tokens' => ['token.created' => 'Created token', 'token.revoked' => 'Revoked token'],
        'Records' => ['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted'],
    ];

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
        $entries = Activity::query()
            ->with(['causer', 'subject'])
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($q) => $q
                    ->where('description', 'like', $term)
                    ->orWhere('properties', 'like', $term)
                    ->orWhereHasMorph('causer', [User::class], fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHasMorph('subject', [ReverbApp::class, User::class, Role::class], fn ($q) => $q->where('name', 'like', $term)));
            })
            ->latest('id')
            ->paginate(50);

        return view('livewire.audit.index', ['entries' => $entries, 'events' => self::EVENTS])
            ->layout('components.layouts.app');
    }
}
