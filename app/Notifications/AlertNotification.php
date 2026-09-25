<?php

namespace App\Notifications;

use App\Alerts\AlertMonitor;
use App\Models\Alert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertNotification extends Notification
{
    /** Bumped only for breaking changes to the webhook payload. */
    public const PAYLOAD_VERSION = 1;

    public function __construct(
        public Alert $alert,
        public string $change,
    ) {}

    /**
     * Sent on demand to one destination at a time (see AlertMonitor::send).
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable
            ? array_keys($notifiable->routes)
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alert = $this->alert;
        $resolved = $this->change === AlertMonitor::RESOLVED;
        $label = $resolved ? 'Resolved' : ucfirst($alert->severity);
        $prefix = $this->change === 'test' ? '[Soundboard test] ' : '[Soundboard] ';

        $mail = (new MailMessage)
            ->subject($prefix."{$label}: {$alert->message}")
            ->greeting($resolved ? 'Resolved' : ucfirst($alert->severity).' alert')
            ->line($resolved ? "This has cleared: {$alert->message}" : $alert->message);

        if ($this->change === AlertMonitor::REMINDER) {
            $mail->line('Still active since '.$alert->triggered_at->toDayDateTimeString().' UTC.');
        }

        if ($resolved) {
            $mail->line('It started '.$alert->triggered_at->toDayDateTimeString().' UTC and lasted '.$alert->triggered_at->diffForHumans($alert->resolved_at, true).'.');
        }

        if ($this->change === 'test') {
            $mail->line('This is a test sent by `php artisan soundboard:test-alert`. Alert email works.');
        }

        return $mail->action('Open Soundboard', url('/admin/status'));
    }

    /**
     * The webhook body. Documented in the README; keep it stable.
     */
    public function toWebhook(object $notifiable): array
    {
        $alert = $this->alert;

        return [
            'type' => 'soundboard.alert',
            'version' => self::PAYLOAD_VERSION,
            'event' => $this->change,
            'alert' => [
                'id' => $alert->id,
                'key' => $alert->key,
                'type' => $alert->type,
                'severity' => $alert->severity,
                'status' => $alert->resolved_at ? 'resolved' : 'active',
                'app_id' => $alert->app_id,
                'message' => $alert->message,
                'details' => $alert->details ?? (object) [],
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ],
            'soundboard' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
            ],
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
