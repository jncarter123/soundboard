<?php

namespace Tests\Feature;

use App\Alerts\AlertMonitor;
use App\Livewire\Admin\Status;
use App\Models\Alert;
use App\Models\ReverbApp;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\ReverbApiService;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int|null> */
    protected array $counts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pulse.enabled' => false,
            'alerts.connection_threshold' => 80,
            'alerts.remind_minutes' => 60,
            'alerts.mail_to' => [],
            'alerts.webhook_url' => null,
            'alerts.webhook_secret' => 'shared-secret',
        ]);

        $api = Mockery::mock(ReverbApiService::class);
        $api->shouldReceive('getConnectionCounts')->andReturnUsing(fn () => $this->counts);
        $this->app->instance(ReverbApiService::class, $api);
    }

    protected function makeApp(string $id = 'storefront', ?int $max = 100): ReverbApp
    {
        return ReverbApp::create([
            'name' => ucfirst($id), 'app_id' => $id,
            'key' => "key-{$id}", 'secret' => 'secret', 'allowed_origins' => ['*'], 'max_connections' => $max,
        ]);
    }

    /** @return list<string> "change:type" for each notification */
    protected function check(): array
    {
        return array_map(fn ($n) => "{$n[1]}:{$n[0]->type}", app(AlertMonitor::class)->check());
    }

    public function test_connection_alert_lifecycle(): void
    {
        $this->makeApp();

        $this->counts = ['storefront' => 50];
        $this->assertSame([], $this->check());

        $this->counts = ['storefront' => 85];
        $this->assertSame(['triggered:connections.near_limit'], $this->check());
        $this->assertSame([], $this->check(), 'no repeat on the next check');

        $this->counts = ['storefront' => 100];
        $this->assertSame(['changed:connections.at_limit'], $this->check());
        $this->assertTrue(Alert::active()->sole()->isCritical());

        $this->counts = ['storefront' => 40];
        $this->assertSame(['resolved:connections.at_limit'], $this->check());
        $this->assertSame(0, Alert::active()->count());

        $this->counts = ['storefront' => 90];
        $this->assertSame(['triggered:connections.near_limit'], $this->check(), 'a recurrence is a new alert');
        $this->assertSame(2, Alert::count());
    }

    public function test_apps_without_a_limit_never_alert(): void
    {
        $this->makeApp('unlimited', max: null);
        $this->counts = ['unlimited' => 100000];

        $this->assertSame([], $this->check());
    }

    public function test_reminders_repeat_after_the_interval(): void
    {
        $this->makeApp();
        $this->counts = ['storefront' => 100];
        $this->check();

        $this->travel(59)->minutes();
        $this->assertSame([], $this->check());

        $this->travel(2)->minutes();
        $this->assertSame(['reminder:connections.at_limit'], $this->check());

        config(['alerts.remind_minutes' => 0]);
        $this->travel(5)->hours();
        $this->assertSame([], $this->check(), 'reminders off');
    }

    public function test_unreachable_reverb_is_one_alert_not_one_per_app(): void
    {
        $this->makeApp('a');
        $this->makeApp('b');
        $this->counts = ['a' => null, 'b' => null];

        $this->assertSame(['triggered:reverb.unreachable'], $this->check());

        $this->counts = ['a' => 1, 'b' => 1];
        $this->assertSame(['resolved:reverb.unreachable'], $this->check());
    }

    public function test_stale_metrics_alert(): void
    {
        config(['pulse.enabled' => true, 'alerts.metrics_stale_minutes' => 5]);
        $this->makeApp(max: null);
        $this->counts = ['storefront' => 3];

        $this->assertSame([], $this->check(), 'fresh install gets a grace period');

        $this->travel(10)->minutes();
        $this->assertSame(['triggered:metrics.stale'], $this->check());

        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        DB::table('pulse_aggregates')->insert([
            'bucket' => now()->getTimestamp(), 'period' => 60, 'type' => 'reverb_connections',
            'key' => 'storefront', 'aggregate' => 'max', 'value' => 3, 'count' => 1,
            ...($sqlite ? ['key_hash' => md5('storefront')] : []),
        ]);
        $this->assertSame(['resolved:metrics.stale'], $this->check());
    }

    public function test_webhook_is_signed_and_documented_shape(): void
    {
        config(['alerts.webhook_url' => 'https://hooks.example.com/soundboard']);
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        $this->makeApp();
        $this->counts = ['storefront' => 100];

        $this->check();

        Http::assertSent(function (Request $request) {
            // Verify the way a receiver would, independently of the sender.
            $timestamp = $request->header('X-Soundboard-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'shared-secret');
            $this->assertSame($expected, $request->header('X-Soundboard-Signature')[0]);

            $payload = $request->data();
            $this->assertSame('soundboard.alert', $payload['type']);
            $this->assertSame(1, $payload['version']);
            $this->assertSame('triggered', $payload['event']);
            $this->assertSame('connections.at_limit', $payload['alert']['type']);
            $this->assertSame('critical', $payload['alert']['severity']);
            $this->assertSame('active', $payload['alert']['status']);
            $this->assertSame('storefront', $payload['alert']['app_id']);
            $this->assertSame(['connections' => 100, 'limit' => 100, 'percent' => 100, 'threshold' => 80], $payload['alert']['details']);

            return true;
        });
    }

    public function test_webhook_without_a_secret_is_refused_and_mail_still_goes(): void
    {
        config([
            'alerts.webhook_url' => 'https://hooks.example.com/soundboard',
            'alerts.webhook_secret' => null,
            'alerts.mail_to' => ['ops@example.com'],
        ]);
        Http::fake();
        Log::spy();
        $this->makeApp();
        $this->counts = ['storefront' => 100];

        $this->check();

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($context['message'], 'ALERTS_WEBHOOK_SECRET'))->once();
        $this->assertNotNull(Alert::sole()->last_notified_at, 'the alert is still recorded as notified by mail');
    }

    public function test_a_failing_webhook_does_not_stop_the_check(): void
    {
        config(['alerts.webhook_url' => 'https://hooks.example.com/soundboard']);
        Http::fake(['*' => Http::response('down', 500)]);
        Log::spy();
        $this->makeApp();
        $this->counts = ['storefront' => 100];

        $this->assertSame(['triggered:connections.at_limit'], $this->check());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_mail_is_sent_on_demand_to_every_recipient(): void
    {
        Notification::fake();
        config(['alerts.mail_to' => ['ops@example.com', 'oncall@example.com']]);
        $this->makeApp();
        $this->counts = ['storefront' => 100];

        $this->check();

        Notification::assertSentOnDemand(AlertNotification::class, function (AlertNotification $n, array $channels, object $notifiable) {
            $mail = $n->toMail($notifiable);

            return $notifiable->routes['mail'] === ['ops@example.com', 'oncall@example.com']
                && $mail->subject === '[Soundboard] Critical: Storefront is at its connection limit (100/100); new clients are being rejected';
        });
    }

    public function test_test_alert_command_reports_each_destination(): void
    {
        $this->artisan('soundboard:test-alert')
            ->expectsOutputToContain('No alert destinations configured')
            ->assertFailed();

        config(['alerts.webhook_url' => 'https://hooks.example.com/soundboard']);
        // The webhook retries once, so the first command sees two failures.
        Http::fake(['*' => Http::sequence()->push('nope', 500)->push('nope', 500)->push('', 204)]);
        $this->artisan('soundboard:test-alert')
            ->expectsOutputToContain('Test webhook to https://hooks.example.com/soundboard failed')
            ->assertFailed();

        $this->artisan('soundboard:test-alert')
            ->expectsOutputToContain('Sent test webhook to https://hooks.example.com/soundboard.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->data()['event'] === 'test');
        $this->assertSame(0, Alert::count(), 'a test alert is never stored');
    }

    public function test_status_page_shows_active_alerts(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->makeApp();
        $this->counts = ['storefront' => 90];
        $this->check();

        Livewire::actingAs(User::where('email', 'admin@example.com')->first())
            ->test(Status::class)
            ->assertSee('Storefront is at 90% of its connection limit (90/100)')
            ->assertSee('No destinations configured');
    }
}
