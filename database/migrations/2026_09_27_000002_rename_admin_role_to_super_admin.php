<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The built-in Admin role becomes Super Admin, with control of the whole
 * server, now that teams can give users access to only some apps. Members
 * and permissions carry over unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Admin', 'Super Admin');
    }

    public function down(): void
    {
        $this->rename('Super Admin', 'Admin');
    }

    private function rename(string $from, string $to): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $role = Role::where('name', $from)->where('guard_name', $guard)->first();

        if ($role === null) {
            return;
        }

        // Merging two roles could silently hand one role's members the other's access.
        if (Role::where('name', $to)->where('guard_name', $guard)->exists()) {
            throw new RuntimeException("A role named \"{$to}\" already exists. Rename it, then run the migrations again.");
        }

        $role->update(['name' => $to]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
