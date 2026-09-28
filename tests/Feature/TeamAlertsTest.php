<?php

namespace Tests\Feature;

use App\Alerts\AlertMonitor;
use App\Alerts\WebhookTarget;
use App\Enums\TeamRole;
use App\Livewire\Teams\Index as TeamsIndex;
use App\Models\Alert;
use App\Models\ReverbApp;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\WebhookChannel;
use App\Support\HostResolver;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TeamAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Team $payments;

    private ReverbApp $paymentsApp;

    /** @var array<string, list<string>> host => addresses the fake DNS returns */
    private array $dns = [
        'hooks.payments.example' => ['93.184.216.34'],
        'server-hooks.example' => ['93.184.216.35'],
        'internal.example' => ['10.0.0.5'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);

        config([
            'alerts.mail_to' => ['ops@example.com'],
            'alerts.webhook_url' => 'https://server-hooks.example/soundboard',
            'alerts.webhook_secret' => 'server-secret',
            'alerts.server_gets_team_alerts' => true,
            'alerts.team_webhooks_allow_private' => false,
        ]);

        $dns = &$this->dns;
        $this->app->instance(HostResolver::class, new class($dns) extends HostResolver
        {
            public function __construct(private array &$dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });

        $this->payments = Team::create([
            'name' => 'Payments',
            'alert_mail_to' => ['payments@example.com'],
            'alert_webhook_url' => 'https://hooks.payments.example/alerts',
            'alert_webhook_secret' => 'team-secret',
        ]);
        $this->paymentsApp = ReverbApp::create([
            'name' => 'Checkout', 'app_id' => 'checkout', 'team_id' => $this->payments->id,
            'key' => 'k', 'secret' => 's', 'allowed_origins' => ['*'],
        ]);
    }

    private function alert(?string $appId = 'checkout', string $type = 'connections.at_limit'): Alert
    {
        return Alert::create([
            'key' => $appId ? "connections:{$appId}" : $type,
            'type' => $type,
            'severity' => Alert::CRITICAL,
            'app_id' => $appId,
            'message' => 'Test',
            'triggered_at' => now(),
        ]);
    }

    /** @return list<string> Each destination an alert was sent to: mail recipients or webhook URL. */
    private function sentTo(): array
    {
        $targets = [];

        Notification::assertSentOnDemand(AlertNotification::class, function (AlertNotification $n, array $channels, AnonymousNotifiable $notifiable) use (&$targets) {
            foreach ($notifiable->routes as $route) {
                $targets[] = match (true) {
                    $route instanceof WebhookTarget => $route->url,
                    is_array($route) => implode(',', $route),
                    default => (string) $route,
                };
            }

            return true;
        });

        sort($targets);

        return $targets;
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $this->payments->members()->attach($user, ['role' => TeamRole::Owner->value]);

        return $user;
    }

    // --- Routing ---------------------------------------------------------

    public function test_team_app_alerts_go_to_the_team_and_the_server(): void
    {
        Notification::fake();

        app(AlertMonitor::class)->send($this->alert(), AlertMonitor::TRIGGERED);

        $this->assertSame([
            'https://hooks.payments.example/alerts',
            'https://server-hooks.example/soundboard',
            'ops@example.com',
            'payments@example.com',
        ], $this->sentTo());
    }

    public function test_server_destinations_can_skip_team_alerts(): void
    {
        config(['alerts.server_gets_team_alerts' => false]);
        Notification::fake();

        app(AlertMonitor::class)->send($this->alert(), AlertMonitor::TRIGGERED);

        $this->assertSame(['https://hooks.payments.example/alerts', 'payments@example.com'], $this->sentTo());
    }

    public function test_server_wide_alerts_never_go_to_teams(): void
    {
        Notification::fake();

        app(AlertMonitor::class)->send($this->alert(null, 'reverb.unreachable'), AlertMonitor::TRIGGERED);

        $this->assertSame(['https://server-hooks.example/soundboard', 'ops@example.com'], $this->sentTo());
    }

    public function test_apps_without_a_team_alert_the_server_only(): void
    {
        ReverbApp::create(['name' => 'Solo', 'app_id' => 'solo', 'key' => 'k2', 'secret' => 's', 'allowed_origins' => ['*']]);
        config(['alerts.server_gets_team_alerts' => false]);
        Notification::fake();

        app(AlertMonitor::class)->send($this->alert('solo'), AlertMonitor::TRIGGERED);

        $this->assertSame(['https://server-hooks.example/soundboard', 'ops@example.com'], $this->sentTo());
    }

    public function test_team_webhook_is_signed_with_the_team_secret_and_names_the_team(): void
    {
        config(['alerts.mail_to' => [], 'alerts.webhook_url' => null]);
        $this->payments->update(['alert_mail_to' => null]);
        Http::fake(['*' => Http::response('', 204)]);

        app(AlertMonitor::class)->send($this->alert(), AlertMonitor::TRIGGERED);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Soundboard-Timestamp')[0];
            $this->assertSame('sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'team-secret'), $request->header('X-Soundboard-Signature')[0]);
            $this->assertSame('https://hooks.payments.example/alerts', $request->url());
            $this->assertSame(['id' => $this->payments->id, 'name' => 'Payments'], $request->data()['team']);
            $this->assertSame(1, $request->data()['version']);

            return true;
        });
    }

    public function test_server_webhook_payload_names_the_team_too(): void
    {
        config(['alerts.mail_to' => []]);
        $this->payments->update(['alert_mail_to' => null, 'alert_webhook_url' => null, 'alert_webhook_secret' => null]);
        Http::fake(['*' => Http::response('', 204)]);

        app(AlertMonitor::class)->send($this->alert(), AlertMonitor::TRIGGERED);

        Http::assertSent(fn (Request $request) => $request->data()['team']['name'] === 'Payments'
            && str_contains($request->header('X-Soundboard-Signature')[0], hash_hmac('sha256', $request->header('X-Soundboard-Timestamp')[0].'.'.$request->body(), 'server-secret')));
    }

    public function test_a_team_webhook_that_now_resolves_privately_is_not_called(): void
    {
        config(['alerts.mail_to' => [], 'alerts.webhook_url' => null]);
        $this->payments->update(['alert_mail_to' => null]);
        // The host passed the check when saved, then its DNS changed.
        $this->dns['hooks.payments.example'] = ['127.0.0.1'];
        Http::fake();
        Log::spy();

        app(AlertMonitor::class)->send($this->alert(), AlertMonitor::TRIGGERED);

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'Alert notification to Payments webhook failed'
            && str_contains($context['message'], 'private or reserved'));
    }

    public function test_team_webhook_redirects_count_as_failures(): void
    {
        $this->expectExceptionMessage('HTTP 302');
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        Notification::route(WebhookChannel::class, new WebhookTarget('https://hooks.payments.example/alerts', 'team-secret', publicOnly: true))
            ->notifyNow(new AlertNotification($this->alert(), 'test', $this->payments));
    }

    // --- Managing destinations -------------------------------------------

    public function test_owners_set_their_teams_destinations(): void
    {
        $team = Team::create(['name' => 'Search']);
        $owner = User::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->call('showAlerts', $team->id)
            ->set('alertMailTo', 'A@example.com, b@example.com; a@example.com')
            ->set('alertWebhookUrl', 'https://hooks.payments.example/search')
            ->call('saveAlerts')
            ->assertHasNoErrors()
            ->assertSee('Saved.');

        $team->refresh();
        $this->assertEquals(['a@example.com', 'b@example.com'], $team->alert_mail_to);
        $this->assertSame('https://hooks.payments.example/search', $team->alert_webhook_url);
        $this->assertSame(64, strlen($team->alert_webhook_secret));
    }

    public function test_maintainers_and_other_teams_owners_cannot_manage_alerts(): void
    {
        $maintainer = User::factory()->create();
        $this->payments->members()->attach($maintainer, ['role' => TeamRole::Maintainer->value]);

        $otherOwner = User::factory()->create();
        Team::create(['name' => 'Search'])->members()->attach($otherOwner, ['role' => TeamRole::Owner->value]);

        foreach ([$maintainer, $otherOwner] as $user) {
            Livewire::actingAs($user)->test(TeamsIndex::class)
                ->call('showAlerts', $this->payments->id)
                ->assertForbidden();
        }
    }

    public function test_team_webhooks_must_be_public_https(): void
    {
        $component = Livewire::actingAs($this->owner())->test(TeamsIndex::class)->call('showAlerts', $this->payments->id);

        foreach ([
            'http://hooks.payments.example/alerts' => 'https://',
            'https://internal.example/alerts' => 'private or reserved',
            'https://169.254.169.254/latest' => 'private or reserved',
            'https://[::1]/alerts' => 'private or reserved',
            'https://unknown.example/alerts' => 'could not be resolved',
            'https://user:pass@hooks.payments.example/' => 'username or password',
        ] as $url => $error) {
            $component->set('alertWebhookUrl', $url)->call('saveAlerts')->assertHasErrors('alertWebhookUrl');
            $this->assertStringContainsString($error, $component->errors()->first('alertWebhookUrl'), $url);
        }

        $this->assertSame('https://hooks.payments.example/alerts', $this->payments->fresh()->alert_webhook_url);
    }

    public function test_private_webhooks_can_be_allowed_for_trusted_networks(): void
    {
        config(['alerts.team_webhooks_allow_private' => true]);

        Livewire::actingAs($this->owner())->test(TeamsIndex::class)
            ->call('showAlerts', $this->payments->id)
            ->set('alertWebhookUrl', 'http://internal.example/alerts')
            ->call('saveAlerts')
            ->assertHasNoErrors();

        $this->assertSame('http://internal.example/alerts', $this->payments->fresh()->alert_webhook_url);
    }

    public function test_email_addresses_are_validated(): void
    {
        $component = Livewire::actingAs($this->owner())->test(TeamsIndex::class)->call('showAlerts', $this->payments->id);

        $component->set('alertMailTo', 'ok@example.com, not-an-email')->call('saveAlerts')->assertHasErrors('alertMailTo');
        $component->set('alertMailTo', implode(',', array_map(fn ($i) => "u{$i}@example.com", range(1, 11))))->call('saveAlerts')->assertHasErrors('alertMailTo');
    }

    public function test_the_secret_is_kept_on_save_and_removed_with_the_webhook(): void
    {
        $component = Livewire::actingAs($this->owner())->test(TeamsIndex::class)->call('showAlerts', $this->payments->id);

        $component->call('saveAlerts')->assertHasNoErrors();
        $this->assertSame('team-secret', $this->payments->fresh()->alert_webhook_secret);

        $component->set('alertWebhookUrl', '')->call('saveAlerts')->assertHasNoErrors();
        $this->assertNull($this->payments->fresh()->alert_webhook_secret);
    }

    public function test_revealing_and_regenerating_the_secret_is_audited(): void
    {
        $component = Livewire::actingAs($this->owner())->test(TeamsIndex::class)
            ->call('showAlerts', $this->payments->id)
            ->assertDontSee('team-secret')
            ->call('revealWebhookSecret')
            ->assertSee('team-secret');

        $component->call('regenerateWebhookSecret');
        $secret = $this->payments->fresh()->alert_webhook_secret;
        $this->assertNotSame('team-secret', $secret);
        $component->assertSee($secret);

        $this->assertSame(
            ['team.webhook_secret_viewed', 'team.webhook_secret_regenerated'],
            Activity::where('event', 'like', 'team.webhook%')->orderBy('id')->pluck('event')->all(),
        );
        $this->assertStringNotContainsString($secret, Activity::all()->toJson());
    }

    public function test_test_alerts_go_only_to_the_team_and_are_rate_limited(): void
    {
        Notification::fake();
        RateLimiter::clear("team-test-alert:{$this->payments->id}");
        $component = Livewire::actingAs($this->owner())->test(TeamsIndex::class)->call('showAlerts', $this->payments->id);

        $component->call('sendTestAlert')->assertHasNoErrors()->assertSee('Sent a test to payments@example.com.');
        $this->assertSame(['https://hooks.payments.example/alerts', 'payments@example.com'], $this->sentTo());

        foreach (range(2, 5) as $ignored) {
            $component->call('sendTestAlert')->assertHasNoErrors();
        }

        $component->call('sendTestAlert')->assertHasErrors('alertTest');
        Notification::assertSentOnDemandTimes(AlertNotification::class, 10);
    }

    public function test_test_alert_needs_a_saved_destination(): void
    {
        $team = Team::create(['name' => 'Search']);
        $owner = User::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->call('showAlerts', $team->id)
            ->call('sendTestAlert')
            ->assertHasErrors('alertTest');
    }
}
