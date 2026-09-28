<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Models\Team;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Turns the current conditions into alert state changes and notifications.
 * Each alert notifies when it starts, when its severity changes, every
 * `alerts.remind_minutes` while it lasts, and once when it resolves.
 */
class AlertMonitor
{
    public const TRIGGERED = 'triggered';

    public const CHANGED = 'changed';

    public const REMINDER = 'reminder';

    public const RESOLVED = 'resolved';

    public function __construct(
        protected AlertConditions $conditions,
    ) {}

    /**
     * @return list<array{0: Alert, 1: string}> The alerts notified, and why.
     */
    public function check(): array
    {
        $current = collect($this->conditions->current())->keyBy('key');
        $active = Alert::active()->get()->keyBy('key');
        $notify = [];

        foreach ($current as $key => $condition) {
            $alert = $active->get($key);

            if (! $alert) {
                $alert = Alert::create([
                    ...$this->attributes($condition),
                    'triggered_at' => now(),
                ]);
                $notify[] = [$alert, self::TRIGGERED];

                continue;
            }

            $severityChanged = $alert->severity !== $condition->severity;
            $alert->fill($this->attributes($condition))->save();

            if ($severityChanged) {
                $notify[] = [$alert, self::CHANGED];
            } elseif ($this->reminderDue($alert)) {
                $notify[] = [$alert, self::REMINDER];
            }
        }

        // Conditions that no longer hold. (Eloquent's except() takes model
        // IDs, not collection keys, so filter explicitly.)
        foreach ($active->reject(fn (Alert $alert, string $key) => $current->has($key)) as $alert) {
            $alert->update(['resolved_at' => now()]);
            $notify[] = [$alert, self::RESOLVED];
        }

        foreach ($notify as [$alert, $change]) {
            $this->send($alert, $change);
        }

        return $notify;
    }

    /**
     * Send one alert to every destination that should hear about it: the
     * server's, and for an app on a team, the team's. A failing destination
     * is logged and never stops the others or the check.
     */
    public function send(Alert $alert, string $change): void
    {
        $team = $alert->app?->team;

        foreach (self::destinations($team, includeServer: $team === null || config('alerts.server_gets_team_alerts')) as $destination) {
            try {
                Notification::route($destination['channel'], $destination['route'])
                    ->notifyNow(new AlertNotification($alert, $change, $team));
            } catch (Throwable $e) {
                Log::error("Alert notification to {$destination['label']} failed", [
                    'alert' => $alert->key,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($alert->exists) {
            $alert->forceFill(['last_notified_at' => now()])->save();
        }
    }

    /**
     * Where alerts go: the server's destinations and/or a team's.
     *
     * @return list<array{label: string, channel: string, route: mixed, target: string}>
     */
    public static function destinations(?Team $team, bool $includeServer = true): array
    {
        $destinations = [];

        if ($includeServer && config('alerts.mail_to')) {
            $destinations[] = ['label' => 'email', 'channel' => 'mail', 'route' => config('alerts.mail_to'), 'target' => implode(', ', config('alerts.mail_to'))];
        }

        if ($includeServer && config('alerts.webhook_url')) {
            $destinations[] = ['label' => 'webhook', 'channel' => WebhookChannel::class, 'route' => config('alerts.webhook_url'), 'target' => config('alerts.webhook_url')];
        }

        if ($team?->alert_mail_to) {
            $destinations[] = ['label' => "{$team->name} email", 'channel' => 'mail', 'route' => $team->alert_mail_to, 'target' => implode(', ', $team->alert_mail_to)];
        }

        if ($team?->alert_webhook_url) {
            $destinations[] = [
                'label' => "{$team->name} webhook",
                'channel' => WebhookChannel::class,
                'route' => new WebhookTarget($team->alert_webhook_url, (string) $team->alert_webhook_secret, publicOnly: ! config('alerts.team_webhooks_allow_private')),
                'target' => $team->alert_webhook_url,
            ];
        }

        return $destinations;
    }

    /**
     * Send a test alert to each destination.
     *
     * @param  list<array{label: string, channel: string, route: mixed, target: string}>  $destinations
     * @return list<array{label: string, target: string, error: string|null}>
     */
    public static function sendTest(array $destinations, ?Team $team = null): array
    {
        $alert = new Alert([
            'key' => 'test',
            'type' => 'test',
            'severity' => Alert::WARNING,
            'message' => 'Test alert from Soundboard',
            'details' => [],
            'triggered_at' => now(),
        ]);

        return array_map(function (array $destination) use ($alert, $team) {
            try {
                Notification::route($destination['channel'], $destination['route'])
                    ->notifyNow(new AlertNotification($alert, 'test', $team));
                $error = null;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            return ['label' => $destination['label'], 'target' => $destination['target'], 'error' => $error];
        }, $destinations);
    }

    protected function reminderDue(Alert $alert): bool
    {
        $minutes = (int) config('alerts.remind_minutes', 60);

        return $minutes > 0
            && $alert->last_notified_at !== null
            && $alert->last_notified_at->diffInMinutes(now(), true) >= $minutes;
    }

    protected function attributes(Condition $condition): array
    {
        return [
            'key' => $condition->key,
            'type' => $condition->type,
            'severity' => $condition->severity,
            'app_id' => $condition->appId,
            'message' => $condition->message,
            'details' => $condition->details,
        ];
    }
}
