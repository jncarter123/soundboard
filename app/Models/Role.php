<?php

namespace App\Models;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use LogsActivity;

    /**
     * The built-in role that always holds every permission. It cannot be
     * renamed, edited, or deleted, and must always have at least one member.
     */
    public const ADMIN = 'Admin';

    /**
     * Renames are audited here. Permission changes go through a pivot table,
     * which fires no model events, so they are logged as their own event.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => ucfirst($event).' role');
    }

    public function isAdmin(): bool
    {
        return $this->name === self::ADMIN;
    }
}
