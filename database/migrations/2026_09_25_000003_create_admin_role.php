<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every install needs the permissions and an Admin role holding all of them,
 * so `soundboard:add-user --role=Admin` can create the first admin. They used
 * to exist only after `db:seed`. Idempotent: existing installs keep their
 * roles, and Admin gains any permission it was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (config('auth_permissions.permissions', []) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => $guard])
            ->givePermissionTo(Permission::where('guard_name', $guard)->get());
    }

    public function down(): void
    {
        // Roles and permissions may be in use; leave them.
    }
};
