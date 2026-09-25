<?php

namespace Tests\Feature;

use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
    }

    protected function actingAsAdmin(): User
    {
        return Sanctum::actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    }

    protected function actingAsUserWith(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return Sanctum::actingAs(tap(User::factory()->create())->assignRole($role));
    }

    protected function makeApp(array $attributes = []): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Test App',
            'app_id' => 'app-123',
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => ['app.example.com'],
            ...$attributes,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/apps')->assertUnauthorized();
    }

    public function test_real_token_authenticates(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $token = $admin->createToken('ci')->plainTextToken;

        $this->withToken($token)->getJson('/api/apps')->assertOk();
    }

    public function test_lists_apps_without_credentials(): void
    {
        $app = $this->makeApp();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/apps')
            ->assertOk()
            ->assertJsonPath('data.0.app_id', 'app-123');

        $this->assertStringNotContainsString($app->secret, $response->getContent());
        $this->assertStringNotContainsString($app->key, $response->getContent());
    }

    public function test_create_returns_credentials_and_normalizes_origins(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/apps', [
            'name' => 'New App',
            'app_id' => 'new-app',
            'allowed_origins' => ['https://App.Example.com/path', '*.example.com'],
        ])->assertCreated()
            ->assertJsonPath('data.allowed_origins', ['app.example.com', '*.example.com'])
            ->assertJsonPath('data.ping_interval', 60);

        $app = ReverbApp::where('app_id', 'new-app')->firstOrFail();
        $response->assertJsonPath('credentials.key', $app->key)
            ->assertJsonPath('credentials.secret', $app->secret);
    }

    public function test_create_requires_at_least_one_origin(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/apps', ['name' => 'X', 'app_id' => 'x', 'allowed_origins' => ['  ']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allowed_origins']);
    }

    public function test_update_cannot_change_app_id(): void
    {
        $this->makeApp();
        $this->actingAsAdmin();

        $this->patchJson('/api/apps/app-123', ['app_id' => 'renamed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app_id']);

        $this->patchJson('/api/apps/app-123', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.app_id', 'app-123');
    }

    public function test_regenerate_credentials(): void
    {
        $app = $this->makeApp();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/apps/app-123/credentials')->assertOk();

        $this->assertNotSame($app->secret, $response->json('data.secret'));
        $this->assertSame($app->fresh()->secret, $response->json('data.secret'));
    }

    public function test_delete(): void
    {
        $this->makeApp();
        $this->actingAsAdmin();

        $this->deleteJson('/api/apps/app-123')->assertNoContent();
        $this->assertNull(ReverbApp::where('app_id', 'app-123')->first());
    }

    public function test_permissions_are_enforced(): void
    {
        $this->makeApp();
        $this->actingAsUserWith(['apps.read']);

        $this->getJson('/api/apps/app-123')->assertOk();
        $this->getJson('/api/apps/app-123/credentials')->assertForbidden();
        $this->postJson('/api/apps', ['name' => 'X', 'app_id' => 'x', 'allowed_origins' => ['*']])->assertForbidden();
        $this->patchJson('/api/apps/app-123', ['name' => 'X'])->assertForbidden();
        $this->deleteJson('/api/apps/app-123')->assertForbidden();
    }
}
