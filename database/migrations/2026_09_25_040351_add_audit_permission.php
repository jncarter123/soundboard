<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reading the audit log is its own permission, granted to Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        Permission::firstOrCreate(['name' => 'audit.read', 'guard_name' => $guard]);

        Role::where('name', 'Admin')->where('guard_name', $guard)->first()
            ?->givePermissionTo('audit.read');
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', 'audit.read')->delete();
    }
};
