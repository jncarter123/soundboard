<?php

namespace Tests\Feature;

use App\Livewire\Account\Show as Account;
use App\Livewire\Auth\Login;
use App\Models\Passkey;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use App\Passkeys\PasskeyCeremony;
use App\Passkeys\SetupLink;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
        config(['passkeys.relying_party.id' => 'localhost', 'passkeys.required_roles' => []]);
        Notification::fake();
    }

    private function user(string $password = 'correct-horse-battery'): User
    {
        return User::factory()->create(['password' => Hash::make($password)]);
    }

    private function superAdmin(): User
    {
        return tap($this->user())->assignRole(Role::SUPER_ADMIN);
    }

    /** Register a passkey for the user through the real verification path. */
    private function enroll(User $user, ?SoftwareAuthenticator $authenticator = null): SoftwareAuthenticator
    {
        $authenticator ??= new SoftwareAuthenticator;
        $ceremony = app(PasskeyCeremony::class);
        $this->assertNotNull($ceremony->register($user, $authenticator->register($ceremony->registrationOptions($user, 'test')), 'test', 'Test key'));

        return $authenticator;
    }

    /** A signed response to the login options the component would hand the browser. */
    private function signIn(SoftwareAuthenticator $authenticator, User $user, ?User $pending = null): string
    {
        return $authenticator->authenticate(app(PasskeyCeremony::class)->assertionOptions('login', $pending), (string) $user->id);
    }

    private function emailedCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailCodeNotification::class, function (EmailCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function passwordStep(User $user)
    {
        return Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-battery')
            ->call('login');
    }

    // --- Verification ----------------------------------------------------

    public function test_credential_ids_are_stored_as_base64url(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);

        $this->assertSame(rtrim(strtr(base64_encode($authenticator->credentialId), '+/', '-_'), '='), $user->passkeys()->value('credential_id'));
    }

    public function test_passkeys_without_user_verification_are_refused(): void
    {
        $user = $this->user();
        $authenticator = new SoftwareAuthenticator;
        $ceremony = app(PasskeyCeremony::class);

        $response = $authenticator->register($ceremony->registrationOptions($user, 'x'), userVerified: false);
        $this->assertNull($ceremony->register($user, $response, 'x', 'No PIN'));

        $this->enroll($user, $authenticator);
        $response = $authenticator->authenticate($ceremony->assertionOptions('y', $user), (string) $user->id, userVerified: false);
        $this->assertNull($ceremony->verify($response, 'y', $user));
    }

    public function test_a_response_answers_its_challenge_once(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);
        $ceremony = app(PasskeyCeremony::class);

        $response = $authenticator->authenticate($ceremony->assertionOptions('y', $user), (string) $user->id);
        $this->assertNotNull($ceremony->verify($response, 'y', $user));
        $this->assertNull($ceremony->verify($response, 'y', $user));
    }

    public function test_another_users_passkey_is_refused_when_a_user_is_expected(): void
    {
        $user = $this->user();
        $other = $this->user();
        $authenticator = $this->enroll($other);
        $ceremony = app(PasskeyCeremony::class);

        $response = $authenticator->authenticate($ceremony->assertionOptions('y'), (string) $other->id);
        $this->assertNull($ceremony->verify($response, 'y', $user));
    }

    // --- Sign-in ---------------------------------------------------------

    public function test_users_without_passkeys_sign_in_with_a_password_as_before(): void
    {
        $user = $this->user();

        $this->passwordStep($user)->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_alone_is_not_enough_once_a_user_has_a_passkey(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);

        $component = $this->passwordStep($user)->assertNoRedirect()->assertSee('with your passkey for');
        $this->assertGuest();

        $component->call('loginWithPasskey', $this->signIn($authenticator, $user, $user))->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('passkey', Activity::where('event', 'auth.login')->latest('id')->first()->properties['method']);
    }

    public function test_the_second_step_rejects_someone_elses_passkey(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $intruder = $this->user();
        $intruderKey = $this->enroll($intruder);

        $this->passwordStep($user)
            ->call('loginWithPasskey', $this->signIn($intruderKey, $intruder))
            ->assertHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_signing_in_with_a_passkey_alone(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);

        Livewire::test(Login::class)
            ->call('loginWithPasskey', $this->signIn($authenticator, $user))
            ->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_pending_step_expires(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $component = $this->passwordStep($user);

        $this->travel(11)->minutes();

        $component->call('$refresh')->assertSee('Sign in with a passkey')->assertDontSee('with your passkey for');
    }

    // --- Required passkeys -----------------------------------------------

    public function test_requirement_is_off_by_default(): void
    {
        $admin = $this->superAdmin();

        $this->passwordStep($admin)->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_required_user_confirms_an_email_code_then_registers_a_passkey(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $admin = $this->superAdmin();

        $component = $this->passwordStep($admin)->assertSee('enter the code we sent');
        $this->assertGuest();

        // No registering before the code.
        $this->assertNull($component->instance()->registrationOptions());

        $component->set('code', '000000')->call('verifyCode')->assertHasErrors('code');
        $component->set('code', $this->emailedCode($admin))->call('verifyCode')->assertHasNoErrors()->assertSee('Now create a passkey');

        $authenticator = new SoftwareAuthenticator;
        $response = $authenticator->register($component->instance()->registrationOptions());
        $component->set('passkeyName', 'Laptop')->call('registerPasskey', $response)->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('Laptop', $admin->passkeys()->value('name'));
        $this->assertDatabaseHas('activity_log', ['event' => 'passkey.added', 'subject_id' => $admin->id]);
    }

    public function test_five_wrong_codes_use_the_code_up(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $admin = $this->superAdmin();
        $component = $this->passwordStep($admin);
        $code = $this->emailedCode($admin);

        foreach (range(1, 5) as $ignored) {
            $component->set('code', $code === '111111' ? '222222' : '111111')->call('verifyCode');
        }

        $component->set('code', $code)->call('verifyCode')->assertHasErrors('code')->assertDontSee('Now create a passkey');
        $this->assertDatabaseHas('activity_log', ['event' => 'auth.email_code_failed']);
    }

    public function test_code_sends_are_rate_limited(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $component = $this->passwordStep($this->superAdmin());

        $component->call('sendCode')->call('sendCode')->assertHasNoErrors();
        $component->call('sendCode')->assertHasErrors('code');
    }

    public function test_existing_sessions_are_sent_through_the_steps_once_required(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/admin')->assertOk();

        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);

        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
        Notification::assertSentTo($admin, EmailCodeNotification::class);
    }

    public function test_required_users_with_a_passkey_are_let_through(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $admin = $this->superAdmin();
        $this->enroll($admin);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    // --- Setup links and recovery ----------------------------------------

    public function test_a_setup_link_skips_the_email_code_once(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $admin = $this->superAdmin();

        Artisan::call('soundboard:passkey-link', ['--email' => $admin->email]);
        preg_match('#https?://\S+#', Artisan::output(), $m);
        $path = parse_url($m[0], PHP_URL_PATH).'?'.parse_url($m[0], PHP_URL_QUERY);

        $this->get($path)->assertRedirect(route('login'));
        Livewire::test(Login::class)->assertSee('Now create a passkey');
        Notification::assertNothingSentTo($admin);

        $this->get($path)->assertStatus(410);
        $this->assertDatabaseHas('activity_log', ['event' => 'auth.setup_link_used', 'subject_id' => $admin->id]);
    }

    public function test_setup_links_expire_and_must_be_signed(): void
    {
        $url = SetupLink::create($this->user());
        $path = parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertForbidden();

        $this->travel(16)->minutes();
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertForbidden();
    }

    public function test_remove_passkeys_command(): void
    {
        $user = $this->user();
        $this->enroll($user);

        $this->artisan('soundboard:remove-passkeys', ['--email' => $user->email])->assertSuccessful();

        $this->assertSame(0, $user->passkeys()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'passkey.removed', 'subject_id' => $user->id]);
    }

    // --- Account page ----------------------------------------------------

    public function test_adding_a_first_passkey_needs_an_email_code(): void
    {
        $user = $this->user();
        $authenticator = new SoftwareAuthenticator;

        $component = Livewire::actingAs($user)->test(Account::class);
        $this->assertNull($component->instance()->registrationOptions());
        $component->call('addPasskey', '{}')->assertHasErrors('confirm');

        $component->call('sendConfirmCode')->set('confirmCode', $this->emailedCode($user))->call('confirmWithCode')->assertHasNoErrors();

        $response = $authenticator->register($component->instance()->registrationOptions());
        $component->set('newPasskeyName', 'Phone')->call('addPasskey', $response)->assertHasNoErrors()->assertSee('Passkey added.');
        $this->assertSame(['Phone'], $user->passkeys()->pluck('name')->all());
    }

    public function test_with_a_passkey_changes_are_confirmed_with_it(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);
        $component = Livewire::actingAs($user)->test(Account::class);

        // No email codes for users who have a passkey.
        $component->call('sendConfirmCode');
        Notification::assertNothingSentTo($user);

        $passkeyId = $user->passkeys()->value('id');
        $component->call('removePasskey', $passkeyId)->assertHasErrors('confirm');

        $response = $authenticator->authenticate($component->instance()->confirmOptions(), (string) $user->id);
        $component->call('confirmWithPasskey', $response)->assertHasNoErrors();

        $component->call('removePasskey', $passkeyId)->assertSee('Passkey removed.');
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_the_last_passkey_stays_when_the_role_requires_one(): void
    {
        config(['passkeys.required_roles' => [Role::SUPER_ADMIN]]);
        $admin = $this->superAdmin();
        $authenticator = $this->enroll($admin);
        $component = Livewire::actingAs($admin)->test(Account::class);

        $component->call('confirmWithPasskey', $authenticator->authenticate($component->instance()->confirmOptions(), (string) $admin->id));
        $component->call('removePasskey', $admin->passkeys()->value('id'))->assertHasErrors('passkeys');

        $this->assertSame(1, $admin->passkeys()->count());
    }

    public function test_changing_email_needs_the_passkey_when_there_is_one(): void
    {
        $user = $this->user();
        $authenticator = $this->enroll($user);
        $component = Livewire::actingAs($user)->test(Account::class)
            ->set('email', 'new@example.com')
            ->set('profileCurrentPassword', 'correct-horse-battery')
            ->call('updateProfile')
            ->assertHasErrors('email');
        $this->assertNotSame('new@example.com', $user->fresh()->email);

        $component->call('confirmWithPasskey', $authenticator->authenticate($component->instance()->confirmOptions(), (string) $user->id))
            ->call('updateProfile')
            ->assertHasNoErrors();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_confirmation_lasts_five_minutes(): void
    {
        $user = $this->user();
        $component = Livewire::actingAs($user)->test(Account::class)
            ->call('sendConfirmCode')
            ->set('confirmCode', $this->emailedCode($user))
            ->call('confirmWithCode');
        $this->assertNotNull($component->instance()->registrationOptions());

        $this->travel(6)->minutes();
        $this->assertNull($component->instance()->registrationOptions());
    }

    public function test_super_admins_without_a_passkey_see_a_banner(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/admin')->assertSee('Add a passkey');

        $this->enroll($admin);
        $this->actingAs($admin)->get('/admin')->assertDontSee('Add a passkey');

        $this->actingAs($this->user())->get('/admin')->assertDontSee('Add a passkey');
    }
}
