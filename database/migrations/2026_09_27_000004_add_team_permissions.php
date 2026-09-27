<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Viewing and managing teams are their own permissions, granted to Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (['teams.read', 'teams.manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::where('name', 'Super Admin')->where('guard_name', $guard)->first()
            ?->givePermissionTo(['teams.read', 'teams.manage']);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', ['teams.read', 'teams.manage'])->delete();
    }
};
