<?php

namespace App\Alerts;

use App\Models\Alert;
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
     * Send one alert to every configured destination. A failing destination
     * is logged and never stops the others or the check.
     */
    public function send(Alert $alert, string $change): void
    {
        $routes = array_filter([
            'mail' => config('alerts.mail_to') ?: null,
            WebhookChannel::class => config('alerts.webhook_url') ?: null,
        ]);

        foreach ($routes as $channel => $route) {
            try {
                Notification::route($channel, $route)->notifyNow(new AlertNotification($alert, $change));
            } catch (Throwable $e) {
                Log::error("Alert notification via {$channel} failed", [
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
