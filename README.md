# Soundboard

A multi-app control panel for [Laravel Reverb](https://reverb.laravel.com).

Reverb normally reads its applications from `config/reverb.php`, so adding an app or rotating a secret means a deploy and a server restart. Soundboard stores apps in a database instead, and gives you a dashboard and an API to run one shared Reverb server for any number of applications.

> Soundboard is an independent project. It is not affiliated with or endorsed by Laravel.

![Connection and message history for one app over the last hour](docs/screenshots/app-history.png)

## Features

- **Multi-app management**: create apps with their own credentials, allowed origins, and connection and message limits. Changes reach the running Reverb server within seconds, with no restart.
- **Live metrics**: current connections, channels, and subscriber counts for each app, plus the members of each presence channel.
- **Historical metrics**: connection and message trends over 1 hour to 7 days, recorded with Laravel Pulse.
- **System status**: database, Redis, and Reverb health at a glance, plus a `/api/health` endpoint for load balancers.
- **Role-based access control**: fine-grained permissions for apps, users, roles, tokens, metrics, and status. Users can never grant access they don't hold themselves.
- **API**: manage apps and rotate credentials from scripts and CI with personal access tokens.
- **Alerts** by email and signed webhook when an app nears or hits its connection limit, Reverb goes down, or metrics stop recording.
- **Audit log**: who signed in, viewed or regenerated credentials, and changed apps, users, roles, or tokens, with the source and IP of each action.
- **Secure by default**: app secrets are encrypted at rest, credentials are only visible to users who can edit the app, and login is rate limited.

## Screenshots

**Live metrics**: current connections, channels, and subscriber counts for every app, refreshed every few seconds. Presence channels show their member count; click it to list the member IDs.

![Live metrics showing connections and channels per app](docs/screenshots/live-metrics.png)

**Applications**: every app on the shared Reverb server, with its origins and credentials.

![Applications list](docs/screenshots/apps.png)

## Requirements

- PHP 8.4+
- Composer
- Node.js and npm
- SQLite, MySQL 8+, or MariaDB
- Redis (optional, only needed for Reverb horizontal scaling)

## Getting started

```bash
composer create-project jncarter123/soundboard soundboard   # installs, creates .env, and migrates
cd soundboard

npm install && npm run build   # build the dashboard's assets
php artisan soundboard:add-user --role=Admin   # your admin account; prompts for name, email, password

php artisan serve              # the dashboard
php artisan reverb:start       # the WebSocket server
php artisan pulse:check        # records connection metrics
```

In production, also add Laravel's scheduler to cron. It removes audit log entries older than `ACTIVITYLOG_CLEAN_AFTER_DAYS` (365 by default):

```cron
* * * * * cd /path/to/soundboard && php artisan schedule:run >> /dev/null 2>&1
```

To work on Soundboard itself, clone the repository instead and run `composer setup`, which installs dependencies, creates `.env`, migrates, and builds the assets.

Sign in at `http://localhost:8000`. Everyone can change their own name, email, and password on the account page, reached by clicking your name in the header.

## Running with Docker

One image runs as four containers from [`compose.yaml`](compose.yaml): the dashboard, the Reverb WebSocket server, the Pulse metrics recorder, and a scheduler that trims the audit log.

```bash
curl -fsSLO https://raw.githubusercontent.com/jncarter123/soundboard/main/compose.yaml
curl -fsSL -o docker.env https://raw.githubusercontent.com/jncarter123/soundboard/main/docker.env.example
echo "SOUNDBOARD_IMAGE=jncarter/soundboard:1" > .env   # pull instead of build

docker compose up -d
docker compose exec app php artisan soundboard:add-user --role=Admin   # your admin account
```

The dashboard is on `127.0.0.1:8000` and Reverb on `127.0.0.1:8080`, both plain HTTP on loopback only, for a reverse proxy in front to terminate TLS. Set `APP_URL` in `docker.env` to the dashboard's public URL, scheme included (`https://soundboard.example.com`), or the browser blocks its assets as mixed content.

- **Back up the volume.** On first start, the dashboard container generates an `APP_KEY` onto the `soundboard-data` volume and says so in its log. It encrypts every stored app secret, so without it clients can't connect. Set `APP_KEY` in `docker.env` instead if you'd rather hold it yourself.
- **Data** lives in SQLite on the same volume. Switch to MySQL or MariaDB in `docker.env`.
- **Upgrades:** `docker compose pull && docker compose up -d`. Migrations run when the dashboard container starts.
- **Reverb is tuned already:** the image includes the libuv event loop and compose raises its file limit to 65,536.

Images are published to Docker Hub as [`jncarter/soundboard`](https://hub.docker.com/r/jncarter/soundboard) for `linux/amd64` and `linux/arm64`, tagged by version (`1.1.0`, `1.1`, `1`), `latest`, and commit SHA. To build from source instead, clone the repository and run `docker compose up -d --build`.

## Alerts

Soundboard checks every minute (from the scheduler) and alerts when:

| Alert | Severity | When |
|---|---|---|
| `connections.near_limit` | warning | An app with a connection limit reaches `ALERTS_CONNECTION_THRESHOLD` percent of it (80 by default) |
| `connections.at_limit` | critical | An app is at its limit, so new clients are being rejected |
| `reverb.unreachable` | critical | Reverb's HTTP API can't be reached |
| `metrics.stale` | warning | No connection sample recorded for `ALERTS_METRICS_STALE_MINUTES` (5), usually because `pulse:check` stopped |
| `reverb.clock_skew` | warning | Reverb's clock and Soundboard's differ by `ALERTS_CLOCK_SKEW_WARNING_SECONDS` (300) or more |
| `reverb.clock_skew` | critical | They differ by more than 10 minutes, so Reverb rejects Soundboard's requests. Reported instead of `reverb.unreachable`, since Reverb itself is fine |

Each alert notifies when it starts, when its severity changes, every `ALERTS_REMIND_MINUTES` (60; `0` turns reminders off) while it lasts, and once when it resolves. Active and recent alerts are also shown on the **Status** page.

Configure one or both destinations, then prove they work with `php artisan soundboard:test-alert`:

```dotenv
ALERTS_MAIL_TO=ops@example.com,oncall@example.com   # needs Laravel's MAIL_* settings
ALERTS_WEBHOOK_URL=https://example.com/hooks/soundboard
ALERTS_WEBHOOK_SECRET=a-long-random-string          # required with a webhook: openssl rand -hex 32
```

### Webhook format

A `POST` with a JSON body:

```json
{
  "type": "soundboard.alert",
  "version": 1,
  "event": "triggered",
  "alert": {
    "id": 42,
    "key": "connections:storefront",
    "type": "connections.at_limit",
    "severity": "critical",
    "status": "active",
    "app_id": "storefront",
    "message": "Storefront is at its connection limit (500/500); new clients are being rejected",
    "details": { "connections": 500, "limit": 500, "percent": 100, "threshold": 80 },
    "triggered_at": "2026-09-25T14:03:00+00:00",
    "resolved_at": null
  },
  "soundboard": { "name": "Soundboard", "url": "https://soundboard.example.com" },
  "sent_at": "2026-09-25T14:03:01+00:00"
}
```

`event` is `triggered`, `changed` (severity went up or down), `reminder`, `resolved`, or `test`. Use `alert.key` to group notifications about the same problem.

Every request is signed. Verify it before trusting the body, and reject old timestamps to stop replays:

```php
$timestamp = $request->header('X-Soundboard-Timestamp');
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

if (! hash_equals($expected, (string) $request->header('X-Soundboard-Signature'))
    || abs(time() - (int) $timestamp) > 300) {
    abort(401);
}
```

A destination that fails is retried once, then logged; it never stops other destinations or the next check.

## Audit log

The **Audit Log** page (the `audit.read` permission, which Admin has) records who did what, when, and from where:

- Sign-ins, failed sign-ins, and sign-outs
- Viewing and regenerating app credentials, from the dashboard or the API
- Changes to apps, users, roles, role permissions, and user roles, with old and new values
- Password changes and resets, and API tokens created or revoked

App keys, secrets, and passwords are never recorded. Entries can't be edited or deleted from the dashboard; entries older than `ACTIVITYLOG_CLEAN_AFTER_DAYS` (365 by default) are removed daily by the scheduler.

## Locked out?

Soundboard sends no email, so there's no "forgot password" link. Reset a password from the server instead. It also signs that user out everywhere:

```bash
php artisan soundboard:reset-password --email=you@example.com
docker compose exec app php artisan soundboard:reset-password --email=you@example.com   # with Docker
```

Both user commands prompt for a password in a terminal, or generate one and print it when there's no terminal to ask in, such as `docker compose exec -T` or a script.

## Connecting an application

Create an app in the dashboard, then point your Laravel app's broadcasting config at Soundboard's Reverb server using the credentials it shows:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=generated-key
REVERB_APP_SECRET=generated-secret
REVERB_HOST=realtime.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

Each app must list at least one allowed origin. Reverb matches the browser's host against the list, so enter hostnames such as `app.example.com` or `*.example.com`, or `*` to allow any origin.

## API

Apps can be managed over HTTP with a personal access token created on the **Tokens** page. Requests act as the token's owner and use the same permissions as the dashboard.

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" https://soundboard.example.com/api/apps
```

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/apps` | `apps.read` | Paginated; no credentials |
| `POST` | `/api/apps` | `apps.create` | Requires `name`, `app_id`, `allowed_origins`; returns credentials |
| `GET` | `/api/apps/{app_id}` | `apps.read` | |
| `PATCH` | `/api/apps/{app_id}` | `apps.update` | `app_id` can't be changed |
| `DELETE` | `/api/apps/{app_id}` | `apps.delete` | |
| `GET` | `/api/apps/{app_id}/credentials` | `apps.update` | Key and secret |
| `POST` | `/api/apps/{app_id}/credentials` | `apps.update` | Regenerates key and secret |
| `GET` | `/api/health` | none | Public health check |

Requests are limited to 60 per minute per user.

Besides `name` and `allowed_origins`, create and update accept Reverb's per-app options: `ping_interval`, `activity_timeout`, `max_message_size`, `max_connections`, `accept_client_events_from` (`members`, `all`, or `none`), and `rate_limiting`, which takes the same shape as Reverb's own config:

```json
{ "rate_limiting": { "enabled": true, "max_attempts": 60, "decay_seconds": 60, "terminate_on_limit": false } }
```

## Configuration

| Variable | Default | Description |
|---|---|---|
| `REVERB_APPS_CACHE_TTL` | `10` | Seconds the running Reverb server keeps apps in memory. Dashboard and API changes, including regenerated credentials, take effect within this window. `0` queries on every lookup. |
| `REVERB_METRICS_HOST` | `127.0.0.1` | Where the dashboard reaches Reverb's HTTP API for live metrics and health checks |
| `REVERB_METRICS_PORT` | `8080` | |
| `REVERB_METRICS_SCHEME` | `http` | |

`APP_KEY` encrypts the stored app secrets. Back it up and never change it on an existing install, or every secret becomes unreadable.

## Production

Soundboard signs every request to Reverb's API with the current time, and Reverb rejects signatures more than 10 minutes from its own clock. If they run on different hosts, keep time sync (NTP, e.g. `systemd-timesyncd` or `chrony`) running on both; containers share their host's clock. The Status page shows the measured difference, and Soundboard alerts before it becomes a problem.

See the [production tuning guide](docs/reverb-production-tuning.md) for file descriptor limits, the event loop, Supervisor, and Nginx configuration for a server with many long-lived connections.

Two artisan commands help diagnose metrics:

- `php artisan reverb:metrics-diagnose` checks that each app's live connection count can be read.
- `php artisan reverb:metrics-health` reports whether Pulse is still recording Reverb metrics.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security issue, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

Soundboard is open-source software licensed under the [MIT License](LICENSE).
