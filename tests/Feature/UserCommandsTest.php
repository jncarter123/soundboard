<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_user_interactively_with_role(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->artisan('soundboard:add-user', ['--role' => ['Admin']])
            ->expectsQuestion('Name', 'Priya Shah')
            ->expectsQuestion('Email', 'priya@example.com')
            ->expectsQuestion('Password (leave blank to generate one)', 'priya-password-123')
            ->expectsQuestion('Confirm password', 'priya-password-123')
            ->expectsOutputToContain('Created priya@example.com with role Admin.')
            ->assertSuccessful();

        $user = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('priya-password-123', $user->password));
        $this->assertTrue($user->hasRole(Role::ADMIN));
    }

    public function test_add_user_without_a_terminal_generates_and_prints_a_password(): void
    {
        Artisan::call('soundboard:add-user', [
            '--name' => 'Ops', '--email' => 'ops@example.com', '--no-interaction' => true,
        ]);

        preg_match('/Password: (\S+)/', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'generated password should be printed');
        $this->assertTrue(Hash::check($m[1], User::where('email', 'ops@example.com')->value('password')));
    }

    public function test_fresh_install_can_create_the_first_admin_without_seeding(): void
    {
        $this->artisan('soundboard:add-user', [
            '--name' => 'First', '--email' => 'first@example.com', '--role' => ['Admin'], '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'first@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::ADMIN));
        $this->assertTrue($user->holdsAllPermissions(config('auth_permissions.permissions')));
    }

    public function test_add_user_rejects_bad_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('soundboard:add-user', [
            '--name' => 'X', '--email' => 'taken@example.com', '--role' => ['Nope'], '--no-interaction' => true,
        ])->expectsOutputToContain('The email has already been taken.')
            ->expectsOutputToContain('There is no role named "Nope".')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_add_user_rejects_mismatched_confirmation(): void
    {
        $this->artisan('soundboard:add-user', ['--name' => 'X', '--email' => 'x@example.com'])
            ->expectsQuestion('Password (leave blank to generate one)', 'first-password-123')
            ->expectsQuestion('Confirm password', 'different-password')
            ->expectsOutputToContain('The passwords do not match.')
            ->assertFailed();

        $this->assertNull(User::where('email', 'x@example.com')->first());
    }

    public function test_reset_password_sets_password_and_signs_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'admin@example.com']);
        DB::table('sessions')->insert([
            'id' => 'stolen', 'user_id' => $user->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'x', 'payload' => '', 'last_activity' => time(),
        ]);

        $this->artisan('soundboard:reset-password', ['--email' => 'admin@example.com'])
            ->expectsQuestion('Password (leave blank to generate one)', 'recovered-password-123')
            ->expectsQuestion('Confirm password', 'recovered-password-123')
            ->expectsOutputToContain('Reset the password for admin@example.com')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('recovered-password-123', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_reset_password_for_unknown_email_fails(): void
    {
        $this->artisan('soundboard:reset-password', ['--email' => 'nobody@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('There is no user with the email nobody@example.com.')
            ->assertFailed();
    }
}
