<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use App\Support\MessageCounts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out which alert conditions hold right now.
 */
class AlertConditions
{
    /**
     * Reverb (1.12+) rejects signed API requests whose timestamp is further
     * than this from its own clock.
     */
    public const REVERB_SIGNATURE_TOLERANCE = 600;

    public function __construct(
        protected ReverbApiService $api,
    ) {}

    /**
     * @return list<Condition>
     */
    public function current(): array
    {
        $skew = $this->clockSkewCondition($this->api->getClockSkew());
        $apps = ReverbApp::all();

        if ($apps->isEmpty()) {
            return array_values(array_filter([$skew]));
        }

        // Message counts come from Pulse's tables, not Reverb's API, so they
        // hold even when the checks below can't reach Reverb.
        $messages = $this->messageConditions($apps);

        // Beyond Reverb's tolerance every signed request is refused, so the
        // polls below would fail and look like an outage. The skew is the
        // cause; report only that.
        if ($skew?->severity === Alert::CRITICAL) {
            return [$skew, ...$messages];
        }

        $counts = $this->api->getConnectionCounts($apps);

        // Every poll failed: Reverb itself is down or unreachable. Per-app
        // checks would only repeat that, so report it once.
        if (collect($counts)->every(fn ($count) => $count === null)) {
            return array_values(array_filter([$skew, new Condition(
                key: 'reverb.unreachable',
                type: 'reverb.unreachable',
                severity: Alert::CRITICAL,
                message: "Reverb is unreachable at {$this->reverbTarget()}",
                details: ['target' => $this->reverbTarget()],
            ), ...$messages]));
        }

        // With scaling, each Reverb server enforces max_connections on its own
        // connections, so the real ceiling is the limit times the servers.
        // Unknown (Redis unreachable) counts as one: alert early, not late.
        $servers = $this->api->getServerCount() ?? 1;

        return array_values(array_filter([
            ...$apps->map(fn (ReverbApp $app) => $this->connectionCondition($app, $counts[$app->app_id] ?? null, $servers))->all(),
            ...$messages,
            $this->staleMetricsCondition($apps->min('created_at')),
            $skew,
        ]));
    }

    /**
     * @param  Collection<int, ReverbApp>  $apps
     * @return list<Condition>
     */
    protected function messageConditions(Collection $apps): array
    {
        $limited = $apps->filter(fn (ReverbApp $app) => $app->max_messages_per_day);

        if ($limited->isEmpty()) {
            return [];
        }

        $counts = MessageCounts::today();

        return array_values(array_filter($limited->map(
            fn (ReverbApp $app) => $this->messageCondition($app, $counts[$app->app_id]['total'] ?? 0),
        )->all()));
    }

    /**
     * The daily message limit is Soundboard's own: Reverb keeps delivering
     * past it, so going over is reported, not enforced. Counts reset at
     * midnight UTC, which resolves the alert.
     */
    protected function messageCondition(ReverbApp $app, int $messages): ?Condition
    {
        $limit = $app->max_messages_per_day;
        $percent = (int) floor($messages / $limit * 100);
        $threshold = (int) config('alerts.message_threshold', 80);
        $details = ['messages' => $messages, 'limit' => $limit, 'percent' => $percent, 'threshold' => $threshold];
        $limitText = number_format($messages).'/'.number_format($limit).' today, UTC';

        if ($messages > $limit) {
            return new Condition(
                key: "messages:{$app->app_id}",
                type: 'messages.over_limit',
                severity: Alert::CRITICAL,
                message: "{$app->name} is over its daily message limit ({$limitText})",
                appId: $app->app_id,
                details: $details,
            );
        }

        if ($percent >= $threshold) {
            return new Condition(
                key: "messages:{$app->app_id}",
                type: 'messages.near_limit',
                severity: Alert::WARNING,
                message: "{$app->name} has used {$percent}% of its daily message limit ({$limitText})",
                appId: $app->app_id,
                details: $details,
            );
        }

        return null;
    }

    /**
     * Soundboard signs every Reverb API request with its current time, so
     * the two clocks must agree to within Reverb's tolerance. Warns from
     * alerts.clock_skew_warning_seconds (5 minutes) so there's time to fix
     * time sync before requests start failing.
     */
    protected function clockSkewCondition(?int $skew): ?Condition
    {
        $warnAt = (int) config('alerts.clock_skew_warning_seconds', 300);

        if ($skew === null || abs($skew) < $warnAt) {
            return null;
        }

        $drift = self::describeSkew($skew);
        $direction = $skew > 0 ? 'ahead of' : 'behind';
        $critical = abs($skew) > self::REVERB_SIGNATURE_TOLERANCE;
        $details = ['skew_seconds' => $skew, 'warning_seconds' => $warnAt, 'reverb_tolerance_seconds' => self::REVERB_SIGNATURE_TOLERANCE];

        return new Condition(
            key: 'reverb.clock_skew',
            type: 'reverb.clock_skew',
            severity: $critical ? Alert::CRITICAL : Alert::WARNING,
            message: $critical
                ? "Reverb's clock is {$drift} {$direction} Soundboard's, so Reverb is rejecting Soundboard's requests (the limit is 10 minutes); check time sync (NTP) on both hosts"
                : "Reverb's clock is {$drift} {$direction} Soundboard's; Reverb will reject Soundboard's requests beyond 10 minutes, so check time sync (NTP) on both hosts",
            details: $details,
        );
    }

    protected function connectionCondition(ReverbApp $app, ?int $connections, int $servers = 1): ?Condition
    {
        if ($connections === null || ! $app->max_connections) {
            return null;
        }

        $limit = $app->max_connections * $servers;
        $percent = (int) floor($connections / $limit * 100);
        $threshold = (int) config('alerts.connection_threshold', 80);
        $details = ['connections' => $connections, 'limit' => $limit, 'percent' => $percent, 'threshold' => $threshold];

        if ($servers > 1) {
            $details += ['limit_per_server' => $app->max_connections, 'servers' => $servers];
        }

        $limitText = $servers > 1
            ? number_format($connections).'/'.number_format($limit).": {$app->max_connections} per server × {$servers} servers"
            : number_format($connections).'/'.number_format($limit);

        if ($connections >= $limit) {
            return new Condition(
                key: "connections:{$app->app_id}",
                type: 'connections.at_limit',
                severity: Alert::CRITICAL,
                message: "{$app->name} is at its connection limit ({$limitText}); new clients are being rejected",
                appId: $app->app_id,
                details: $details,
            );
        }

        if ($percent >= $threshold) {
            return new Condition(
                key: "connections:{$app->app_id}",
                type: 'connections.near_limit',
                severity: Alert::WARNING,
                message: "{$app->name} is at {$percent}% of its connection limit ({$limitText})",
                appId: $app->app_id,
                details: $details,
            );
        }

        return null;
    }

    /**
     * Pulse records a connection sample every 15 seconds while `pulse:check`
     * runs. None for a while means the historical charts have stopped.
     */
    protected function staleMetricsCondition(mixed $firstAppCreatedAt): ?Condition
    {
        if (! config('pulse.enabled')) {
            return null;
        }

        $minutes = (int) config('alerts.metrics_stale_minutes', 5);
        $newest = DB::connection(config('pulse.storage.database.connection'))
            ->table('pulse_aggregates')
            ->where('type', 'reverb_connections')
            ->max('bucket');

        // A fresh install has no samples yet; give it the same grace period.
        $since = $newest !== null
            ? CarbonImmutable::createFromTimestamp((int) $newest)
            : CarbonImmutable::parse($firstAppCreatedAt);

        if ($since->diffInMinutes(now(), true) < $minutes) {
            return null;
        }

        return new Condition(
            key: 'metrics.stale',
            type: 'metrics.stale',
            severity: Alert::WARNING,
            message: $newest !== null
                ? "Connection metrics have not been recorded for {$since->diffForHumans(now(), true)}; is pulse:check running?"
                : 'No connection metrics have been recorded yet; is pulse:check running?',
            details: ['last_recorded_at' => $newest !== null ? $since->toIso8601String() : null],
        );
    }

    /**
     * "15 minutes" / "15m". Measurement is only good to about a second, so
     * anything over a minute is rounded to whole minutes.
     */
    public static function describeSkew(int $seconds, bool $short = false): string
    {
        $seconds = abs($seconds);
        $interval = $seconds >= 60
            ? CarbonInterval::minutes((int) round($seconds / 60))
            : CarbonInterval::seconds($seconds);

        return $interval->cascade()->forHumans(['parts' => 2, 'short' => $short]);
    }

    protected function reverbTarget(): string
    {
        return config('reverb.metrics.scheme', 'http').'://'.config('reverb.metrics.host', '127.0.0.1').':'.config('reverb.metrics.port', 8080);
    }
}
