<?php

namespace Tests\Feature;

use App\Livewire\Account\Show as Account;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Tokens\Index as TokensIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
        User::where('email', 'admin@example.com')->update(['password' => Hash::make('admin-password-123')]);
        Activity::query()->delete(); // seeding noise
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function makeApp(): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Storefront', 'app_id' => 'storefront',
            'key' => 'the-app-key-123', 'secret' => 'the-app-secret-456',
            'allowed_origins' => ['*'],
        ]);
    }

    protected function entry(string $event): Activity
    {
        return Activity::where('event', $event)->latest('id')->firstOrFail();
    }

    /** Every audit row, serialized, so a leaked value anywhere is caught. */
    protected function everything(): string
    {
        return DB::table('activity_log')->get()->toJson();
    }

    public function test_app_changes_are_logged_without_credentials(): void
    {
        $this->actingAs($this->admin());
        $app = $this->makeApp();
        $app->update(['name' => 'Storefront Prod', 'max_connections' => 500]);

        $created = $this->entry('created');
        $this->assertSame('Created app', $created->description);
        $this->assertTrue($created->subject->is($app));
        $this->assertTrue($created->causer->is($this->admin()));

        $updated = $this->entry('updated');
        $this->assertSame('Storefront', $updated->attribute_changes['old']['name']);
        $this->assertSame('Storefront Prod', $updated->attribute_changes['attributes']['name']);

        $this->assertStringNotContainsString('the-app-key-123', $this->everything());
        $this->assertStringNotContainsString('the-app-secret-456', $this->everything());
    }

    public function test_viewing_and_regenerating_credentials_is_logged_without_values(): void
    {
        $app = $this->makeApp();

        Livewire::actingAs($this->admin())->test(AppsIndex::class)
            ->call('revealCredentials', $app->id)
            ->call('regenerateCredentials', $app->id);

        $this->assertTrue($this->entry('credentials.viewed')->subject->is($app));
        $this->assertTrue($this->entry('credentials.regenerated')->subject->is($app));
        // Regenerating changes only key/secret, which aren't logged, so there's no empty update entry.
        $this->assertSame(0, Activity::where('event', 'updated')->count());

        $app->refresh();
        $this->assertStringNotContainsString($app->key, $this->everything());
        $this->assertStringNotContainsString($app->secret, $this->everything());
    }

    public function test_api_credential_access_is_logged_with_api_source(): void
    {
        $this->makeApp();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/apps/storefront/credentials')->assertOk();

        $entry = $this->entry('credentials.viewed');
        $this->assertSame('api', $entry->properties['source']);
        $this->assertTrue($entry->causer->is($this->admin()));
    }

    public function test_sign_ins_are_logged_and_passwords_never_are(): void
    {
        Livewire::test(Login::class)->set('email', 'admin@example.com')->set('password', 'wrong-guess-999')->call('login');
        Livewire::test(Login::class)->set('email', 'admin@example.com')->set('password', 'admin-password-123')->call('login');

        $failed = $this->entry('auth.failed');
        $this->assertNull($failed->causer);
        $this->assertSame('admin@example.com', $failed->properties['email']);
        $this->assertSame('web', $failed->properties['source']);
        $this->assertArrayHasKey('ip', $failed->properties->all());

        $this->assertTrue($this->entry('auth.login')->causer->is($this->admin()));
        $this->assertStringNotContainsString('wrong-guess-999', $this->everything());
        $this->assertStringNotContainsString('admin-password-123', $this->everything());
    }

    public function test_role_and_permission_changes_record_what_was_added_and_removed(): void
    {
        $role = Role::create(['name' => 'Support', 'guard_name' => 'web']);
        $role->syncPermissions(['apps.read']);
        $user = User::factory()->create();
        Activity::query()->delete();

        Livewire::actingAs($this->admin())->test(RolesIndex::class)
            ->call('editRole', $role->id)
            ->set('selectedPermissions', ['metrics.read'])
            ->call('saveRole');

        $perms = $this->entry('role.permissions_changed');
        $this->assertSame(['metrics.read'], $perms->properties['added']);
        $this->assertSame(['apps.read'], $perms->properties['removed']);

        Livewire::actingAs($this->admin())->test(UsersIndex::class)
            ->call('editUser', $user->id)
            ->set('selectedRoles', [(string) $role->id])
            ->call('saveEdit');

        $roles = $this->entry('user.roles_changed');
        $this->assertTrue($roles->subject->is($user));
        $this->assertSame(['Support'], $roles->properties['added']);
    }

    public function test_password_changes_and_tokens_are_logged(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Account::class)
            ->set('currentPassword', 'admin-password-123')
            ->set('password', 'brand-new-password-1')
            ->set('password_confirmation', 'brand-new-password-1')
            ->call('updatePassword');

        $this->assertTrue($this->entry('account.password_changed')->subject->is($admin));
        $this->assertStringNotContainsString('brand-new-password-1', $this->everything());
        $this->assertStringNotContainsString($admin->fresh()->password, $this->everything());

        Livewire::actingAs($admin)->test(TokensIndex::class)
            ->set('selectedUserId', $admin->id)
            ->call('openCreateModal')
            ->set('tokenName', 'ci-deploy')
            ->call('createToken');

        $this->assertSame('ci-deploy', $this->entry('token.created')->properties['token']);

        Livewire::actingAs($admin)->test(TokensIndex::class)
            ->set('selectedUserId', $admin->id)
            ->call('revokeToken', $admin->tokens()->first()->id);

        $this->assertSame('ci-deploy', $this->entry('token.revoked')->properties['token']);
    }

    public function test_cli_password_reset_is_logged_as_command_line(): void
    {
        $this->artisan('soundboard:reset-password', ['--email' => 'admin@example.com', '--no-interaction' => true])
            ->assertSuccessful();

        $entry = $this->entry('user.password_reset');
        $this->assertNull($entry->causer);
        $this->assertSame('cli', $entry->properties['source']);
        $this->assertArrayNotHasKey('ip', $entry->properties->all());
    }

    public function test_audit_page_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.audit'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.audit'))->assertOk();
    }

    public function test_audit_page_lists_filters_and_searches(): void
    {
        $this->actingAs($this->admin());
        $this->makeApp();
        $other = User::factory()->create(['name' => 'Taylor Brooks']);

        Livewire::actingAs($this->admin())->test(AuditIndex::class)
            ->assertSee('Storefront (storefront)')
            ->assertSee('Taylor Brooks')
            ->set('event', 'created')
            ->assertSee('Storefront (storefront)')
            ->set('search', 'Taylor')
            ->assertSee('Taylor Brooks')
            ->assertDontSee('Storefront (storefront)');
    }

    public function test_entries_survive_deleting_their_subject(): void
    {
        $this->actingAs($this->admin());
        $app = $this->makeApp();
        $app->delete();

        Livewire::actingAs($this->admin())->test(AuditIndex::class)
            ->assertSee('ReverbApp #'.$app->id.' (deleted)');
    }
}
