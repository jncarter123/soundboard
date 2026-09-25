<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class TestAlert extends Command
{
    protected $signature = 'soundboard:test-alert';

    protected $description = 'Send a test alert to every configured destination and report what happened';

    public function handle(): int
    {
        $routes = array_filter([
            'email' => config('alerts.mail_to') ? ['mail', config('alerts.mail_to')] : null,
            'webhook' => config('alerts.webhook_url') ? [WebhookChannel::class, config('alerts.webhook_url')] : null,
        ]);

        if ($routes === []) {
            $this->components->warn('No alert destinations configured. Set ALERTS_MAIL_TO and/or ALERTS_WEBHOOK_URL.');

            return self::FAILURE;
        }

        $alert = new Alert([
            'key' => 'test',
            'type' => 'test',
            'severity' => Alert::WARNING,
            'message' => 'Test alert from Soundboard',
            'details' => [],
            'triggered_at' => now(),
        ]);

        $failed = false;

        foreach ($routes as $label => [$channel, $route]) {
            $target = is_array($route) ? implode(', ', $route) : $route;

            try {
                Notification::route($channel, $route)->notifyNow(new AlertNotification($alert, 'test'));
                $this->components->info("Sent test {$label} to {$target}.");
            } catch (Throwable $e) {
                $failed = true;
                $this->components->error("Test {$label} to {$target} failed: {$e->getMessage()}");
            }
        }

        if (config('mail.default') === 'log' && isset($routes['email'])) {
            $this->components->warn('MAIL_MAILER is "log": the email was written to the log, not sent.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
