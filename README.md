# Soundboard

A multi-app control panel for [Laravel Reverb](https://reverb.laravel.com).

Reverb normally reads its applications from `config/reverb.php`, so adding an app or rotating a secret means a deploy and a server restart. Soundboard stores apps in a database instead, and gives you a dashboard and an API to run one shared Reverb server for any number of applications.

> Soundboard is an independent project. It is not affiliated with or endorsed by Laravel.

![Connection and message history for one app over the last hour](docs/screenshots/app-history.png)

## Features

- **Multi-app management**: create apps with their own credentials, allowed origins, and connection and message limits. Changes reach the running Reverb server within seconds, with no restart.
- **Live metrics**: current connections, channels, and subscriber counts for each app.
- **Historical metrics**: connection and message trends over 1 hour to 7 days, recorded with Laravel Pulse.
- **System status**: database, Redis, and Reverb health at a glance, plus a `/api/health` endpoint for load balancers.
- **Role-based access control**: fine-grained permissions for apps, users, roles, tokens, metrics, and status. Users can never grant access they don't hold themselves.
- **API**: manage apps and rotate credentials from scripts and CI with personal access tokens.
- **Secure by default**: app secrets are encrypted at rest, credentials are only visible to users who can edit the app, and login is rate limited.

## Screenshots

**Live metrics**: current connections, channels, and subscriber counts for every app, refreshed every few seconds.

![Live metrics showing connections and channels per app](docs/screenshots/live-metrics.png)

**Applications**: every app on the shared Reverb server, with its origins and credentials.

![Applications list](docs/screenshots/apps.png)

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm
- SQLite, MySQL 8+, or MariaDB
- Redis (optional, only needed for Reverb horizontal scaling)

## Getting started

```bash
git clone https://github.com/jncarter123/soundboard.git
cd soundboard

composer setup           # install dependencies, create .env, migrate, build assets
php artisan db:seed      # creates admin@example.com and prints its password

php artisan serve        # the dashboard
php artisan reverb:start # the WebSocket server
php artisan pulse:check  # records connection metrics
```

Sign in at `http://localhost:8000` with the printed password and change it straight away.

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

## Configuration

| Variable | Default | Description |
|---|---|---|
| `REVERB_APPS_CACHE_TTL` | `10` | Seconds the running Reverb server keeps apps in memory. Dashboard and API changes, including regenerated credentials, take effect within this window. `0` queries on every lookup. |
| `REVERB_METRICS_HOST` | `127.0.0.1` | Where the dashboard reaches Reverb's HTTP API for live metrics and health checks |
| `REVERB_METRICS_PORT` | `8080` | |
| `REVERB_METRICS_SCHEME` | `http` | |

`APP_KEY` encrypts the stored app secrets. Back it up and never change it on an existing install, or every secret becomes unreadable.

## Production

See the [production tuning guide](docs/reverb-production-tuning.md) for file descriptor limits, the event loop, Supervisor, and Nginx configuration for a server with many long-lived connections.

Two artisan commands help diagnose metrics:

- `php artisan reverb:metrics-diagnose` checks that each app's live connection count can be read.
- `php artisan reverb:metrics-health` reports whether Pulse is still recording Reverb metrics.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security issue, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

Soundboard is open-source software licensed under the [MIT License](LICENSE).
