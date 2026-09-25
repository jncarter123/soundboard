<?php

namespace App\Console\Commands;

use App\Alerts\AlertMonitor;
use Illuminate\Console\Command;

class CheckAlerts extends Command
{
    protected $signature = 'soundboard:check-alerts';

    protected $description = 'Check for alert conditions and notify on changes (runs every minute from the scheduler)';

    public function handle(AlertMonitor $monitor): int
    {
        $notified = $monitor->check();

        foreach ($notified as [$alert, $change]) {
            $this->line(sprintf('%-9s %-8s %s', $change, $alert->severity, $alert->message));
        }

        if ($notified === []) {
            $this->components->info('No alert changes.');
        }

        return self::SUCCESS;
    }
}
