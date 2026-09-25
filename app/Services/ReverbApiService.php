<?php

namespace App\Services;

use App\Models\ReverbApp;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

class ReverbApiService
{
    public function __construct(
        protected BroadcastManager $broadcast,
    ) {}

    public function getConnectionCount(ReverbApp $app): ?int
    {
        try {
            $response = $this->pusher($app)->get('/connections');

            return isset($response->connections)
                ? (int) $response->connections
                : null;
        } catch (Throwable $exception) {
            Log::error('Unable to retrieve Reverb connection count', [
                'app_id' => $app->app_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function getChannels(ReverbApp $app): ?array
    {
        try {
            $response = $this->pusher($app)->get('/channels', [
                'info' => 'subscription_count',
            ]);

            return (array) ($response->channels ?? []);
        } catch (Throwable $exception) {
            Log::error('Unable to retrieve Reverb channels', [
                'app_id' => $app->app_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function getLiveStats(ReverbApp $app): array
    {
        return [
            'app_id' => $app->app_id,
            'name' => $app->name,
            'connections' => $this->getConnectionCount($app),
            'channels' => $this->getChannels($app),
        ];
    }

    public function getAllAppsLiveStats(): array
    {
        return ReverbApp::all()
            ->map(fn (ReverbApp $app) => $this->getLiveStats($app))
            ->all();
    }

    protected function pusher(ReverbApp $app): Pusher
    {
        $scheme = config('reverb.metrics.scheme', 'http');

        $config = $app->toReverbApplication()->toArray();

        $config['options'] = array_merge($config['options'] ?? [], [
            'host' => config('reverb.metrics.host', '127.0.0.1'),
            'port' => (int) config('reverb.metrics.port', 8080),
            'scheme' => $scheme,
            'useTLS' => $scheme === 'https',
        ]);

        $config['client_options'] = [
            'connect_timeout' => 2,
            'timeout' => 5,
        ];

        return $this->broadcast->pusher($config);
    }
}
