<?php

namespace App\Console\Commands;

use App\Alerts\AlertMonitor;
use Illuminate\Console\Command;

class TestAlert extends Command
{
    protected $signature = 'soundboard:test-alert';

    protected $description = 'Send a test alert to every configured destination and report what happened';

    public function handle(): int
    {
        $destinations = AlertMonitor::destinations(null);

        if ($destinations === []) {
            $this->components->warn('No alert destinations configured. Set ALERTS_MAIL_TO and/or ALERTS_WEBHOOK_URL.');

            return self::FAILURE;
        }

        $failed = false;

        foreach (AlertMonitor::sendTest($destinations) as $result) {
            if ($result['error'] === null) {
                $this->components->info("Sent test {$result['label']} to {$result['target']}.");
            } else {
                $failed = true;
                $this->components->error("Test {$result['label']} to {$result['target']} failed: {$result['error']}");
            }
        }

        if (config('mail.default') === 'log' && config('alerts.mail_to')) {
            $this->components->warn('MAIL_MAILER is "log": the email was written to the log, not sent.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
