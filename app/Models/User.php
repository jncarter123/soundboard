<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

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
