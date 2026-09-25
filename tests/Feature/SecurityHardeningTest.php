<?php

namespace Tests\Feature;

use App\Livewire\Admin\Status;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    protected function makeApp(): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Test App',
            'app_id' => 'app-123',
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);
    }

    // --- Health endpoint -------------------------------------------------

    public function test_health_skips_redis_when_scaling_disabled(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => false]);
        Http::fake(['*/up' => Http::response('', 200)]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['redis' => 'skipped', 'reverb' => 'ok']]);
    }

    public function test_health_does_not_leak_exception_messages(): void
    {
        Http::fake(fn () => throw new \RuntimeException('secret-internal-host.example:8080 refused'));

        $response = $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.reverb', 'error');

        $this->assertStringNotContainsString('secret-internal-host', $response->getContent());
    }

    // --- App secrets -----------------------------------------------------

    public function test_app_secret_is_encrypted_at_rest(): void
    {
        $app = $this->makeApp();

        $stored = DB::table('reverb_apps')->where('id', $app->id)->value('secret');

        $this->assertNotSame($app->secret, $stored);
        $this->assertSame($app->secret, $app->fresh()->toReverbApplication()->secret());
    }

    public function test_revealing_credentials_requires_apps_update(): void
    {
        $app = $this->makeApp();

        Livewire::actingAs($this->userWithPermissions(['apps.read']))
            ->test(AppsIndex::class)
            ->call('revealCredentials', $app->id)
            ->assertForbidden();
    }

    public function test_revealed_credentials_are_not_in_the_component_snapshot(): void
    {
        $app = $this->makeApp();

        $component = Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('revealCredentials', $app->id)
            ->assertSee($app->secret);

        $this->assertStringNotContainsString($app->secret, json_encode($component->snapshot));
        $this->assertStringNotContainsString($app->key, json_encode($component->snapshot));
    }

    // --- Login throttling ------------------------------------------------

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        RateLimiter::clear('login:admin@example.com|127.0.0.1');

        $component = Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'wrong');

        foreach (range(1, 5) as $i) {
            $component->call('login')->assertHasErrors(['email']);
        }

        $component->call('login');
        $this->assertStringContainsString('Too many login attempts', $component->errors()->first('email'));
    }

    // --- Privilege escalation --------------------------------------------

    public function test_user_manager_cannot_grant_admin_role(): void
    {
        $manager = $this->userWithPermissions(['users.read', 'users.update']);
        $target = User::factory()->create();
        $adminRole = Role::findByName(Role::ADMIN);

        Livewire::actingAs($manager)
            ->test(UsersIndex::class)
            ->call('editUser', $target->id)
            ->set('selectedRoles', [(string) $adminRole->id])
            ->call('saveEdit')
            ->assertHasErrors(['selectedRoles']);

        $this->assertFalse($target->fresh()->hasRole(Role::ADMIN));
    }

    public function test_user_cannot_change_own_roles(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('editUser', $admin->id)
            ->set('selectedRoles', [])
            ->call('saveEdit')
            ->assertHasErrors(['selectedRoles']);

        $this->assertTrue($admin->fresh()->hasRole(Role::ADMIN));
    }

    public function test_user_manager_cannot_edit_more_privileged_user(): void
    {
        $manager = $this->userWithPermissions(['users.read', 'users.update']);

        Livewire::actingAs($manager)
            ->test(UsersIndex::class)
            ->call('editUser', $this->admin()->id)
            ->assertForbidden();
    }

    public function test_admin_can_delete_a_user_and_their_tokens(): void
    {
        $target = $this->userWithPermissions(['apps.read']);
        $target->createToken('ci');

        Livewire::actingAs($this->admin())
            ->test(UsersIndex::class)
            ->call('deleteUser', $target->id)
            ->assertOk();

        $this->assertNull(User::find($target->id));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $target->id)->count());
    }

    public function test_deleting_users_requires_permission(): void
    {
        $target = User::factory()->create();

        Livewire::actingAs($this->userWithPermissions(['users.read', 'users.update']))
            ->test(UsersIndex::class)
            ->call('deleteUser', $target->id)
            ->assertForbidden();

        $this->assertNotNull(User::find($target->id));
    }

    public function test_user_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('deleteUser', $admin->id)
            ->assertForbidden();

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_user_cannot_delete_more_privileged_user(): void
    {
        Livewire::actingAs($this->userWithPermissions(['users.read', 'users.delete']))
            ->test(UsersIndex::class)
            ->call('deleteUser', $this->admin()->id)
            ->assertForbidden();

        $this->assertNotNull(User::find($this->admin()->id));
    }

    public function test_last_admin_cannot_be_deleted(): void
    {
        // Holds every permission through a non-Admin role, so only the
        // last-admin rule stands in the way.
        $superuser = $this->userWithPermissions(config('auth_permissions.permissions'));

        Livewire::actingAs($superuser)
            ->test(UsersIndex::class)
            ->call('deleteUser', $this->admin()->id)
            ->assertForbidden();

        $this->assertNotNull(User::find($this->admin()->id));
    }

    public function test_delete_button_hidden_for_self_and_more_privileged_users(): void
    {
        $admin = $this->admin();
        $peer = $this->userWithPermissions(['users.read']);

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->assertSeeHtml("deleteUser({$peer->id})")
            ->assertDontSeeHtml("deleteUser({$admin->id})");

        Livewire::actingAs($this->userWithPermissions(['users.read', 'users.delete']))
            ->test(UsersIndex::class)
            ->assertDontSeeHtml("deleteUser({$admin->id})");
    }

    public function test_role_manager_cannot_grant_permissions_they_lack(): void
    {
        $manager = $this->userWithPermissions(['roles.read', 'roles.create']);

        Livewire::actingAs($manager)
            ->test(RolesIndex::class)
            ->call('newRole')
            ->set('roleName', 'Escalated')
            ->set('selectedPermissions', ['users.update'])
            ->call('saveRole')
            ->assertHasErrors(['selectedPermissions']);

        $this->assertNull(Role::where('name', 'Escalated')->first());
    }

    public function test_duplicate_role_name_is_a_validation_error(): void
    {
        Role::create(['name' => 'Support', 'guard_name' => 'web']);

        Livewire::actingAs($this->admin())
            ->test(RolesIndex::class)
            ->call('newRole')
            ->set('roleName', 'Support')
            ->call('saveRole')
            ->assertHasErrors(['roleName' => 'unique']);
    }

    public function test_admin_role_cannot_be_edited_or_deleted(): void
    {
        $adminRole = Role::findByName(Role::ADMIN);

        Livewire::actingAs($this->admin())
            ->test(RolesIndex::class)
            ->call('deleteRole', $adminRole->id)
            ->assertForbidden();

        Livewire::actingAs($this->admin())
            ->test(RolesIndex::class)
            ->call('editRole', $adminRole->id)
            ->assertForbidden();

        $this->assertNotNull(Role::where('name', Role::ADMIN)->first());
    }

    // --- App form ---------------------------------------------------------

    public function test_app_requires_at_least_one_origin(): void
    {
        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('openCreate')
            ->set('name', 'X')
            ->set('appId', 'x')
            ->set('allowedOrigins', ' , ')
            ->call('saveCreate')
            ->assertHasErrors(['allowedOrigins']);
    }

    public function test_app_id_cannot_be_changed_on_edit(): void
    {
        $app = $this->makeApp();

        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('editApp', $app->id)
            ->set('appId', 'renamed')
            ->set('allowedOrigins', 'https://app.example.com')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('app-123', $app->fresh()->app_id);
        $this->assertSame(['app.example.com'], $app->fresh()->allowed_origins);
    }

    // --- Route gating ----------------------------------------------------

    public function test_metrics_and_status_require_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.metrics'))->assertForbidden();
        $this->get(route('admin.status'))->assertForbidden();
    }

    // --- Status page -----------------------------------------------------

    public function test_status_lists_apps_from_database(): void
    {
        $this->makeApp();

        Livewire::actingAs($this->admin())
            ->test(Status::class)
            ->assertSee('app-123')
            ->assertSee('Test App');
    }
}
