<?php

namespace App\Console\Commands;

use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Illuminate\Console\Command;

class ReverbMetricsDiagnose extends Command
{
    protected $signature = 'reverb:metrics-diagnose';

    protected $description = 'Poll each Reverb app for its live connection count via the same path the recorder now uses';

    public function handle(ReverbApiService $api): int
    {
        $scheme = config('reverb.metrics.scheme', 'http');
        $host = config('reverb.metrics.host', '127.0.0.1');
        $port = (int) config('reverb.metrics.port', 8080);

        $this->line('Metrics API target: <info>'.$scheme.'://'.$host.':'.$port.'</info>');
        $this->newLine();

        $apps = ReverbApp::all();
        $failed = 0;
        $rows = [];

        $counts = $api->getConnectionCounts($apps);

        foreach ($apps as $app) {
            $count = $counts[$app->app_id];

            if ($count === null) {
                $failed++;
                $rows[] = [$app->app_id, '<error>FAILED</error>', '(see laravel.log)'];
            } else {
                $rows[] = [$app->app_id, '<info>OK</info>', number_format($count).' connections'];
            }
        }

        $this->table(['App', 'Poll', 'Result'], $rows);

        if ($failed > 0) {
            $this->newLine();
            $this->warn($failed.' of '.$apps->count().' app(s) failed. Check the metrics target above is reachable from this host and that REVERB_METRICS_HOST/PORT are correct.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All apps reachable. Once `pulse:check` is running, reverb_connections will populate.');

        return self::SUCCESS;
    }
}
