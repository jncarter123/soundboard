<?php

namespace App\Notifications\Channels;

use App\Alerts\WebhookTarget;
use App\Notifications\AlertNotification;
use App\Support\PublicUrlGuard;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POSTs a notification's toWebhook() payload as JSON, signed so the receiver
 * can verify it came from this Soundboard and isn't a replay:
 *
 *   X-Soundboard-Timestamp: <unix seconds>
 *   X-Soundboard-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the secret>
 *
 * The route is a URL, signed with the server's ALERTS_WEBHOOK_SECRET, or a
 * WebhookTarget with its own secret. A public-only target (a team's
 * webhook) connects only to the public address checked just before sending,
 * and never follows redirects.
 */
class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $route = $notifiable->routeNotificationFor(self::class, $notification) ?? $notifiable->routeNotificationFor('webhook', $notification);
        $target = $route instanceof WebhookTarget
            ? $route
            : new WebhookTarget((string) $route, (string) config('alerts.webhook_secret'));

        if (blank($target->url)) {
            return;
        }

        if ($target->secret === '') {
            throw new RuntimeException($route instanceof WebhookTarget
                ? 'The webhook has no signing secret; refusing to send it unsigned.'
                : 'ALERTS_WEBHOOK_SECRET is not set; refusing to send an unsigned webhook.');
        }

        $options = $target->publicOnly
            ? ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [app(PublicUrlGuard::class)->pin($target->url)]]]
            : [];

        $body = json_encode($notification->toWebhook($notifiable), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        $response = Http::timeout(5)
            ->withOptions($options)
            ->retry(2, 1000, throw: false)
            ->withHeaders([
                'X-Soundboard-Timestamp' => $timestamp,
                'X-Soundboard-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $target->secret),
                'User-Agent' => 'Soundboard-Webhook/'.AlertNotification::PAYLOAD_VERSION,
            ])
            ->withBody($body, 'application/json')
            ->post($target->url);

        // Redirects aren't followed for team webhooks, so a 3xx is a failure too.
        if (! $response->successful()) {
            throw new RuntimeException("The webhook responded with HTTP {$response->status()}.");
        }
    }
}
