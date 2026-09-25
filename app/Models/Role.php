<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * The built-in role that always holds every permission. It cannot be
     * renamed, edited, or deleted, and must always have at least one member.
     */
    public const ADMIN = 'Admin';

    public function isAdmin(): bool
    {
        return $this->name === self::ADMIN;
    }
}
