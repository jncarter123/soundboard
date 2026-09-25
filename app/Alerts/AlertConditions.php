<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Works out which alert conditions hold right now.
 */
class AlertConditions
{
    public function __construct(
        protected ReverbApiService $api,
    ) {}

    /**
     * @return list<Condition>
     */
    public function current(): array
    {
        $apps = ReverbApp::all();

        if ($apps->isEmpty()) {
            return [];
        }

        $counts = $this->api->getConnectionCounts($apps);

        // Every poll failed: Reverb itself is down or unreachable. Per-app
        // checks would only repeat that, so report it once.
        if (collect($counts)->every(fn ($count) => $count === null)) {
            return [new Condition(
                key: 'reverb.unreachable',
                type: 'reverb.unreachable',
                severity: Alert::CRITICAL,
                message: "Reverb is unreachable at {$this->reverbTarget()}",
                details: ['target' => $this->reverbTarget()],
            )];
        }

        return array_values(array_filter([
            ...$apps->map(fn (ReverbApp $app) => $this->connectionCondition($app, $counts[$app->app_id] ?? null))->all(),
            $this->staleMetricsCondition($apps->min('created_at')),
        ]));
    }

    protected function connectionCondition(ReverbApp $app, ?int $connections): ?Condition
    {
        if ($connections === null || ! $app->max_connections) {
            return null;
        }

        $limit = $app->max_connections;
        $percent = (int) floor($connections / $limit * 100);
        $threshold = (int) config('alerts.connection_threshold', 80);
        $details = ['connections' => $connections, 'limit' => $limit, 'percent' => $percent, 'threshold' => $threshold];

        if ($connections >= $limit) {
            return new Condition(
                key: "connections:{$app->app_id}",
                type: 'connections.at_limit',
                severity: Alert::CRITICAL,
                message: "{$app->name} is at its connection limit ({$connections}/{$limit}); new clients are being rejected",
                appId: $app->app_id,
                details: $details,
            );
        }

        if ($percent >= $threshold) {
            return new Condition(
                key: "connections:{$app->app_id}",
                type: 'connections.near_limit',
                severity: Alert::WARNING,
                message: "{$app->name} is at {$percent}% of its connection limit ({$connections}/{$limit})",
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

    protected function reverbTarget(): string
    {
        return config('reverb.metrics.scheme', 'http').'://'.config('reverb.metrics.host', '127.0.0.1').':'.config('reverb.metrics.port', 8080);
    }
}
