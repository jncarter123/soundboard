<?php

namespace Tests\Feature;

use App\Livewire\Apps\Index as AppsIndex;
use App\Models\ReverbApp;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ReverbAppSettingsTest extends TestCase
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

    protected function makeApp(array $attributes = []): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Test App',
            'app_id' => 'app-123',
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => ['*'],
            ...$attributes,
        ]);
    }

    public function test_defaults_keep_existing_behaviour(): void
    {
        $reverb = $this->makeApp()->fresh()->toReverbApplication();

        $this->assertSame('members', $reverb->acceptClientEventsFrom());
        $this->assertFalse($reverb->usesRateLimiting());
    }

    public function test_settings_reach_reverb(): void
    {
        $reverb = $this->makeApp([
            'accept_client_events_from' => 'all',
            'rate_limit_enabled' => true,
            'rate_limit_max_attempts' => 5,
            'rate_limit_decay_seconds' => 10,
            'rate_limit_terminate' => true,
        ])->fresh()->toReverbApplication();

        $this->assertSame('all', $reverb->acceptClientEventsFrom());
        // Reverb checks `enabled === true`; a stored 1 must still enable it.
        $this->assertTrue($reverb->usesRateLimiting());
        $this->assertSame([
            'enabled' => true,
            'max_attempts' => 5,
            'decay_seconds' => 10,
            'terminate_on_limit' => true,
        ], $reverb->rateLimiting());
    }

    public function test_api_creates_and_patches_settings(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/apps', [
            'name' => 'Chat',
            'app_id' => 'chat',
            'allowed_origins' => ['*'],
            'accept_client_events_from' => 'none',
            'rate_limiting' => ['enabled' => true, 'max_attempts' => 20],
        ])->assertCreated()
            ->assertJsonPath('data.accept_client_events_from', 'none')
            ->assertJsonPath('data.rate_limiting', [
                'enabled' => true,
                'max_attempts' => 20,
                'decay_seconds' => 60,
                'terminate_on_limit' => false,
            ]);

        // A PATCH with part of rate_limiting leaves the rest alone.
        $this->patchJson('/api/apps/chat', ['rate_limiting' => ['terminate_on_limit' => true]])
            ->assertOk()
            ->assertJsonPath('data.rate_limiting.enabled', true)
            ->assertJsonPath('data.rate_limiting.max_attempts', 20)
            ->assertJsonPath('data.rate_limiting.terminate_on_limit', true);
    }

    public function test_api_rejects_invalid_settings(): void
    {
        Sanctum::actingAs($this->admin());
        $this->makeApp();

        $this->patchJson('/api/apps/app-123', [
            'accept_client_events_from' => 'everyone',
            'rate_limiting' => ['max_attempts' => 0, 'unknown' => true],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['accept_client_events_from', 'rate_limiting', 'rate_limiting.max_attempts']);
    }

    public function test_dashboard_form_saves_settings(): void
    {
        $app = $this->makeApp();

        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('editApp', $app->id)
            ->assertSet('acceptClientEventsFrom', 'members')
            ->assertSet('rateLimitEnabled', false)
            ->set('acceptClientEventsFrom', 'all')
            ->set('rateLimitEnabled', true)
            ->set('rateLimitMaxAttempts', 30)
            ->set('rateLimitDecaySeconds', 5)
            ->set('rateLimitTerminate', true)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $app->refresh();
        $this->assertSame('all', $app->accept_client_events_from);
        $this->assertTrue($app->rate_limit_enabled);
        $this->assertSame(30, $app->rate_limit_max_attempts);
        $this->assertSame(5, $app->rate_limit_decay_seconds);
        $this->assertTrue($app->rate_limit_terminate);
    }

    public function test_dashboard_form_rejects_unknown_client_events_value(): void
    {
        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('openCreate')
            ->set('name', 'X')
            ->set('appId', 'x')
            ->set('allowedOrigins', '*')
            ->set('acceptClientEventsFrom', 'everyone')
            ->call('saveCreate')
            ->assertHasErrors(['acceptClientEventsFrom']);
    }
}
