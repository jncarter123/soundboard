<?php

namespace Tests\Feature;

use App\Livewire\Account\Show as Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function user(): User
    {
        return User::factory()->create([
            'email' => 'sam@example.com',
            'password' => Hash::make('old-password-123'),
        ]);
    }

    protected function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
    }

    public function test_any_signed_in_user_can_open_their_account_page(): void
    {
        // No roles or permissions at all.
        $this->actingAs($this->user())->get(route('account'))->assertOk()->assertSee('Your Account');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('account'))->assertRedirect(route('login'));
    }

    public function test_name_can_change_without_password(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Account::class)
            ->set('name', 'Sam Lee')
            ->call('updateProfile')
            ->assertHasNoErrors()
            ->assertSet('profileStatus', 'Profile saved.');

        $this->assertSame('Sam Lee', $user->fresh()->name);
    }

    public function test_changing_email_requires_current_password(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Account::class)
            ->set('email', 'new@example.com')
            ->call('updateProfile')
            ->assertHasErrors(['profileCurrentPassword' => 'required'])
            ->set('profileCurrentPassword', 'wrong-password')
            ->call('updateProfile')
            ->assertHasErrors(['profileCurrentPassword' => 'current_password']);

        $this->assertSame('sam@example.com', $user->fresh()->email);

        Livewire::actingAs($user)->test(Account::class)
            ->set('email', 'new@example.com')
            ->set('profileCurrentPassword', 'old-password-123')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($this->user())->test(Account::class)
            ->set('email', 'taken@example.com')
            ->set('profileCurrentPassword', 'old-password-123')
            ->call('updateProfile')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Account::class)
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'new-password-456')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword']);

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_password_change_signs_out_other_sessions(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $this->addSession($user, 'laptop');
        $this->addSession($user, 'phone');
        $this->addSession($other, 'someone-else');
        config(['session.driver' => 'database']);
        $oldRememberToken = $user->remember_token;

        Livewire::actingAs($user)->test(Account::class)
            ->set('currentPassword', 'old-password-123')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'new-password-456')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('currentPassword', '');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-456', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_header_links_to_account_page(): void
    {
        $this->actingAs($this->user())->get(route('account'))
            ->assertSee('href="'.route('account').'"', false);
    }
}
