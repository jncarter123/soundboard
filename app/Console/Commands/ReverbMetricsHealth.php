<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReverbMetricsHealth extends Command
{
    protected $signature = 'reverb:metrics-health';

    protected $description = 'Report whether Reverb Pulse metrics are currently being recorded';

    public function handle(): int
    {
        $conn = config('pulse.storage.database.connection');
        $now = CarbonImmutable::now();

        $this->line('Pulse storage connection: <info>'.($conn ?: config('database.default')).'</info>');
        $this->newLine();

        $types = [
            'reverb_connections' => 'pulse:check daemon',
            'reverb_message:sent' => 'reverb:start (messages)',
            'reverb_message:received' => 'reverb:start (messages)',
        ];

        $rows = [];
        foreach ($types as $type => $source) {
            $newest = DB::connection($conn)->table('pulse_aggregates')
                ->where('type', $type)
                ->max('bucket');

            if ($newest === null) {
                $rows[] = [$type, $source, '—', 'NO DATA', '0'];

                continue;
            }

            $ageSeconds = $now->getTimestamp() - (int) $newest;
            $keys = DB::connection($conn)->table('pulse_aggregates')
                ->where('type', $type)
                ->distinct()
                ->count('key');

            $fresh = $ageSeconds <= 120; // connections poll every ~15s
            $status = $fresh ? '<info>LIVE</info>' : '<comment>STALE</comment>';

            $rows[] = [
                $type,
                $source,
                CarbonImmutable::createFromTimestamp($newest)->diffForHumans($now, true).' ago',
                $status,
                (string) $keys,
            ];
        }

        $this->table(
            ['Metric', 'Recorded by', 'Newest bucket', 'Status', 'Apps'],
            $rows,
        );

        $anyStale = collect($rows)->contains(fn ($r) => str_contains($r[3], 'STALE') || $r[3] === 'NO DATA');

        if ($anyStale) {
            $this->newLine();
            $this->warn('Some metrics are stale or missing. Check that `php artisan pulse:check` and `php artisan reverb:start` are running from a valid working directory.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Reverb metrics are being recorded.');

        return self::SUCCESS;
    }
}
