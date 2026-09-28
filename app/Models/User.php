<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, InteractsWithPasskeys, LogsActivity, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether this user must sign in with a passkey: they're in one of
     * AUTH_PASSKEY_REQUIRED_ROLES.
     */
    public function requiresPasskey(): bool
    {
        $roles = config('passkeys.required_roles', []);

        return $roles !== [] && $this->hasAnyRole($roles);
    }

    public function hasPasskey(): bool
    {
        return $this->passkeys()->exists();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * This user's role in the given team, or null if they aren't a member.
     * Reads the loaded `teams` relation, so memberships are queried once.
     */
    public function teamRole(?int $teamId): ?TeamRole
    {
        if ($teamId === null) {
            return null;
        }

        $role = $this->teams->firstWhere('id', $teamId)?->pivot->role;

        return $role === null ? null : TeamRole::tryFrom($role);
    }

    /**
     * IDs of the teams this user owns.
     *
     * @return list<int>
     */
    public function ownedTeamIds(): array
    {
        return $this->teams
            ->filter(fn (Team $team) => $this->teamRole($team->id) === TeamRole::Owner)
            ->modelKeys();
    }

    /**
     * Every permission this user has anywhere: from their roles, plus what
     * their team roles give on their teams' apps. Controlling their account
     * means controlling all of it.
     *
     * @return list<string>
     */
    public function reachablePermissions(): array
    {
        return $this->getAllPermissions()->pluck('name')
            ->merge($this->teams->flatMap(fn (Team $team) => TeamRole::tryFrom($team->pivot->role)?->permissions() ?? []))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether this user may act as the given one: edit their sign-in details,
     * delete them, or hold API tokens that act as them. Only for users with
     * no more access than this one, counting team roles. Super Admin passes
     * checks beyond its assigned permissions, so only a Super Admin may act
     * as another.
     */
    public function mayActAs(User $user): bool
    {
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return $this->hasRole(Role::SUPER_ADMIN);
        }

        return $this->holdsAllPermissions($user->reachablePermissions());
    }

    /**
     * Name and email changes are audited; password changes are logged as their
     * own event, never with the hash.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => ucfirst($event).' user');
    }

    /**
     * Sign this user out everywhere except the given session: cycle the
     * remember-me token so old "remember me" cookies stop working, and, with
     * the database session driver, delete their other sessions. Used after a
     * password change or reset, so a stolen session doesn't outlive it.
     */
    public function signOutOtherSessions(?string $exceptSessionId = null): void
    {
        $this->setRememberToken(Str::random(60));
        $this->save();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * Whether this user holds every given permission. Used to stop users from
     * granting, revoking, or editing access beyond their own.
     *
     * @param  iterable<string|Permission>  $permissions
     */
    public function holdsAllPermissions(iterable $permissions): bool
    {
        $held = $this->getAllPermissions()->pluck('name');

        return collect($permissions)
            ->map(fn ($permission) => is_string($permission) ? $permission : $permission->name)
            ->diff($held)
            ->isEmpty();
    }
}
