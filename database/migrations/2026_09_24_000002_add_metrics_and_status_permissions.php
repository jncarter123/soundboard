<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Metrics and status pages were previously open to any authenticated user.
     * Grant the new permissions to the Admin role so existing admins keep access.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (['metrics.read', 'status.read'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::where('name', 'Admin')->where('guard_name', $guard)->first()
            ?->givePermissionTo(['metrics.read', 'status.read']);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', ['metrics.read', 'status.read'])->delete();
    }
};
