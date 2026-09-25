<?php

namespace App\Pulse\Recorders;

use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Pulse;

/**
 * Records Reverb connection counts into Pulse.
 *
 * Replaces Laravel\Reverb\Pulse\Recorders\ReverbConnections, whose pusher client is
 * built from Application::toArray()['options'] — empty for our database-backed apps,
 * so it 404s against Pusher's default cloud endpoint. We reuse ReverbApiService, which
 * already targets the local Reverb metrics API (reverb.metrics.host/port).
 */
class ReverbConnections
{
    /**
     * The event to listen for. Dispatched once per second by `pulse:check`.
     *
     * @var class-string
     */
    public string $listen = IsolatedBeat::class;

    public function __construct(
        protected Pulse $pulse,
        protected ReverbApiService $api,
    ) {}

    public function record(IsolatedBeat $event): void
    {
        // Match the vendor recorder cadence: sample once every 15 seconds.
        if ($event->time->second % 15 !== 0) {
            return;
        }

        ReverbApp::all()->each(function (ReverbApp $app) use ($event) {
            $connections = $this->api->getConnectionCount($app);

            // getConnectionCount() logs and returns null on failure — skip the bucket
            // rather than recording a misleading zero.
            if ($connections === null) {
                return;
            }

            $this->pulse->record(
                type: 'reverb_connections',
                key: $app->app_id,
                value: $connections,
                timestamp: $event->time->getTimestamp(),
            )->avg()->max()->onlyBuckets();
        });
    }
}
