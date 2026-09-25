<?php

namespace App\Notifications\Channels;

use App\Notifications\AlertNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POSTs a notification's toWebhook() payload as JSON, signed so the receiver
 * can verify it came from this Soundboard and isn't a replay:
 *
 *   X-Soundboard-Timestamp: <unix seconds>
 *   X-Soundboard-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the secret>
 */
class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor(self::class, $notification) ?? $notifiable->routeNotificationFor('webhook', $notification);
        $secret = (string) config('alerts.webhook_secret');

        if (blank($url)) {
            return;
        }

        if ($secret === '') {
            throw new RuntimeException('ALERTS_WEBHOOK_SECRET is not set; refusing to send an unsigned webhook.');
        }

        $body = json_encode($notification->toWebhook($notifiable), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        Http::timeout(5)
            ->retry(2, 1000, throw: false)
            ->withHeaders([
                'X-Soundboard-Timestamp' => $timestamp,
                'X-Soundboard-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
                'User-Agent' => 'Soundboard-Webhook/'.AlertNotification::PAYLOAD_VERSION,
            ])
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }
}
