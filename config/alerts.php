<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where alerts go
    |--------------------------------------------------------------------------
    |
    | Email recipients (comma-separated) and/or a webhook URL. With neither
    | set, alerts are still tracked and shown on the Status page but not sent.
    | Webhook requests are signed with the secret; see the README for the
    | payload and how to verify it.
    |
    */

    'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERTS_MAIL_TO', ''))))),

    'webhook_url' => env('ALERTS_WEBHOOK_URL'),

    'webhook_secret' => env('ALERTS_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | When to alert
    |--------------------------------------------------------------------------
    |
    | connection_threshold: warn when an app with a connection limit reaches
    | this percentage of it; at 100% new clients are being rejected, which is
    | critical. metrics_stale_minutes: warn when Pulse has recorded no
    | connection sample for this long. remind_minutes: repeat a still-active
    | alert this often; 0 notifies only when it starts, changes, and resolves.
    |
    */

    'connection_threshold' => (int) env('ALERTS_CONNECTION_THRESHOLD', 80),

    'metrics_stale_minutes' => (int) env('ALERTS_METRICS_STALE_MINUTES', 5),

    'remind_minutes' => (int) env('ALERTS_REMIND_MINUTES', 60),

    // Warn when Reverb's clock differs from Soundboard's by this many
    // seconds. Beyond 600 Reverb rejects Soundboard's requests (critical).
    'clock_skew_warning_seconds' => (int) env('ALERTS_CLOCK_SKEW_WARNING_SECONDS', 300),

];
