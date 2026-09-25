# Laravel Reverb Production Tuning

This guide covers the host changes needed to run Soundboard's shared Reverb server under production load, where one Reverb process serves many applications and many long-lived WebSocket connections.

The examples assume:

- Ubuntu or Debian
- PHP 8.5
- Laravel Reverb running as the `forge` user
- Reverb listening internally on TCP port `8080`
- Nginx or another reverse proxy terminating TLS
- Supervisor managing the Reverb daemon

Adjust usernames, paths, PHP binaries, and service names to match the deployed server.

## Why these changes are required

Each WebSocket connection remains open for the life of the client connection and consumes at least one file descriptor in the Reverb process. The reverse proxy also consumes file descriptors for its client-facing and upstream connections.

Three separate limits therefore matter:

1. The event loop used by PHP/Reverb
2. The operating-system and service file-descriptor limit
3. The Nginx connection and file-descriptor limits

Installing `ext-uv` only changes the event loop. It does **not** raise Linux, Supervisor, systemd, or Nginx limits.

## Initial VM sizing

Recommended initial resources for the shared service:

```text
2 vCPU
4 GB RAM
40 GB disk
1 Gbps virtual NIC
```

This is intended as a starting point. Scale based on concurrent connections, message rate, CPU, memory, and open-file utilization.

## Confirm the PHP runtime

Reverb must use the same PHP CLI binary where the event-loop extension is installed.

```bash
php -v
which php
php --ini
php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, PHP_EOL;'
```

Expected PHP version:

```text
8.5
```

If Supervisor uses an explicit binary such as `/usr/bin/php8.5`, use that binary in all verification commands:

```bash
/usr/bin/php8.5 -v
/usr/bin/php8.5 --ini
```

## Install the `ext-uv` build dependencies

On Ubuntu or Debian with PHP 8.5 packages available through the configured repository:

```bash
sudo apt update

sudo apt install -y \
    php8.5-dev \
    php-pear \
    libuv1-dev \
    build-essential \
    pkg-config
```

Confirm that the development tools match PHP 8.5:

```bash
phpize --version
php-config --version
php -v
```

`php-config --version` and `php -v` should report the same PHP major and minor version.

On a server with multiple PHP versions, use the versioned tools when available:

```bash
phpize8.5 --version
php-config8.5 --version
```

## Install `ext-uv`

Laravel documents the following PECL installation command:

```bash
sudo pecl install uv
```

If PECL requires an explicit release channel or version, inspect the available release first:

```bash
pecl remote-info uv
```

Then install the version shown by PECL, for example:

```bash
sudo pecl install uv-0.3.0
```

When prompted for the libuv prefix, accept autodetection unless libuv is installed in a custom location.

### PHP 8.5 compatibility warning

The PECL `uv` extension has historically had a slow release cadence. A successful build on older PHP versions does not guarantee that the same release builds without changes on PHP 8.5.

Do not treat a completed `pecl` command as sufficient proof. Verify that:

- The extension was compiled for PHP 8.5
- PHP CLI loads the extension
- Reverb's ReactPHP runtime selects the UV event loop

If compilation fails because of PHP 8.5 API changes, do not copy an older `uv.so` from another PHP version. PHP extensions must be compiled for the exact PHP API in use.

Safe fallback options are:

1. Remain on the default event loop while the service is comfortably below approximately 1,000 open connections.
2. Use Laravel Forge's managed Reverb setup, which can install an alternate event loop and configure connection limits.
3. Test a maintained `ext-uv` source branch in a nonproduction environment before deploying it.

Do not deploy an unreviewed patch or third-party binary directly to production.

## Enable the extension for PHP CLI

First check whether PECL enabled it automatically:

```bash
php -m | grep -i '^uv$'
```

If no output is returned, create a module configuration:

```bash
sudo tee /etc/php/8.5/mods-available/uv.ini >/dev/null <<'INI'
extension=uv.so
INI

sudo phpenmod -v 8.5 uv
```

Verify the extension:

```bash
php --ri uv
```

Also verify the exact Supervisor PHP binary:

```bash
/usr/bin/php8.5 --ri uv
```

Expected output should show that libuv support is enabled.

## Verify the event loop selected by ReactPHP

Run this from the deployed Soundboard directory:

```bash
cd /home/forge/realtime.example.com

php -r '
require "vendor/autoload.php";
echo get_class(React\\EventLoop\\Loop::get()), PHP_EOL;
'
```

With `ext-uv` loaded, the result should be similar to:

```text
React\EventLoop\ExtUvLoop
```

If the result is:

```text
React\EventLoop\StreamSelectLoop
```

then the Reverb CLI runtime is not using `ext-uv`. Check:

```bash
which php
php --ini
php -m | grep -i '^uv$'
```

Also confirm that Supervisor invokes the same PHP binary.

## Configure the operating-system user limits

Create a dedicated limits file:

```bash
sudo tee /etc/security/limits.d/99-soundboard.conf >/dev/null <<'EOF_LIMITS'
forge soft nofile 10000
forge hard nofile 10000
EOF_LIMITS
```

These PAM limits are useful for interactive sessions, but they may not control a process launched by systemd. Configure the service manager separately.

## Configure Supervisor

Open the Supervisor configuration:

```bash
sudo nano /etc/supervisor/supervisord.conf
```

In the existing `[supervisord]` section, set:

```ini
[supervisord]
minfds=10000
```

Do not create a second `[supervisord]` section if one already exists.

Example Reverb program configuration:

```ini
[program:soundboard-reverb]
process_name=%(program_name)s
command=/usr/bin/php8.5 /home/forge/realtime.example.com/artisan reverb:start
directory=/home/forge/realtime.example.com
user=forge
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/home/forge/realtime.example.com/storage/logs/reverb.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
```

Apply the Supervisor configuration:

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

## Configure the systemd limit for Supervisor

On Ubuntu, Supervisor is generally started by systemd. Create an override:

```bash
sudo systemctl edit supervisor
```

Add:

```ini
[Service]
LimitNOFILE=10000
```

Apply the override:

```bash
sudo systemctl daemon-reload
sudo systemctl restart supervisor
sudo supervisorctl status
```

Restarting Supervisor restarts its managed processes. Perform this change during an appropriate maintenance window if Reverb is already in production.

## Verify the actual Reverb file limit

Do not rely only on `ulimit -n` from an SSH shell. Inspect the running Reverb process:

```bash
REVERB_PID=$(pgrep -f 'artisan reverb:start' | head -1)

echo "Reverb PID: ${REVERB_PID}"
grep -i 'open files' "/proc/${REVERB_PID}/limits"
```

Expected result:

```text
Max open files            10000                10000
```

If the PID is empty, check Supervisor:

```bash
sudo supervisorctl status soundboard-reverb
```

## Configure Nginx limits

Edit the main Nginx configuration:

```bash
sudo nano /etc/nginx/nginx.conf
```

Set the worker file limit at the top level:

```nginx
worker_rlimit_nofile 10000;
```

In the `events` block:

```nginx
events {
    worker_connections 10000;
    multi_accept on;
}
```

Be aware that one proxied WebSocket may use a client-side socket and an upstream socket. Do not assume that `worker_connections 10000` always equals 10,000 usable WebSocket clients.

Validate and reload Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Verify Nginx's systemd file limit:

```bash
systemctl show nginx --property=LimitNOFILE
```

If it is lower than the intended capacity, create an Nginx override:

```bash
sudo systemctl edit nginx
```

Add:

```ini
[Service]
LimitNOFILE=10000
```

Then apply it:

```bash
sudo systemctl daemon-reload
sudo systemctl restart nginx
```

## Reverse-proxy requirements

Reverb listens for WebSocket connections under `/app` and server-side publishing requests under `/apps`. The reverse proxy must route both paths to Reverb.

Example:

```nginx
location / {
    proxy_http_version 1.1;

    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    proxy_read_timeout 3600;
    proxy_send_timeout 3600;

    proxy_pass http://127.0.0.1:8080;
}
```

Port `8080` should normally be restricted to localhost, the private network, or an approved load balancer.

## Restart Reverb after changes

After installing or enabling a PHP extension, restart the daemon so the new CLI process loads it:

```bash
sudo supervisorctl restart soundboard-reverb
sudo supervisorctl status soundboard-reverb
```

After an application deployment or Reverb configuration change:

```bash
cd /home/forge/realtime.example.com
php artisan optimize
php artisan reverb:restart
```

Supervisor should start a new Reverb process after the graceful exit.

## End-to-end validation

### 1. Confirm the extension

```bash
/usr/bin/php8.5 --ri uv
```

### 2. Confirm the event loop

```bash
cd /home/forge/realtime.example.com

/usr/bin/php8.5 -r '
require "vendor/autoload.php";
echo get_class(React\\EventLoop\\Loop::get()), PHP_EOL;
'
```

### 3. Confirm the daemon

```bash
sudo supervisorctl status soundboard-reverb
ss -ltnp | grep ':8080'
```

### 4. Confirm the process file limit

```bash
REVERB_PID=$(pgrep -f 'artisan reverb:start' | head -1)
grep -i 'open files' "/proc/${REVERB_PID}/limits"
```

### 5. Confirm the public WebSocket upgrade

Replace the key and origin with a configured Reverb application:

```bash
curl --http1.1 -i -N \
  -H 'Origin: https://app.example.com' \
  -H 'Connection: Upgrade' \
  -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  'https://realtime.example.com/app/REVERB_APP_KEY?protocol=7&client=js&version=8.4.0&flash=false'
```

A successful response begins with:

```text
HTTP/1.1 101 Switching Protocols
```

## Monitoring commands

### Concurrent established connections

```bash
ss -Htan state established '( sport = :8080 )' | wc -l
```

### Reverb CPU and memory

```bash
REVERB_PID=$(pgrep -f 'artisan reverb:start' | head -1)
ps -o pid,%cpu,%mem,rss,etime,cmd -p "${REVERB_PID}"
```

### Open file descriptors

```bash
REVERB_PID=$(pgrep -f 'artisan reverb:start' | head -1)
find "/proc/${REVERB_PID}/fd" -maxdepth 1 -type l | wc -l
```

### Current process limit

```bash
REVERB_PID=$(pgrep -f 'artisan reverb:start' | head -1)
grep -i 'open files' "/proc/${REVERB_PID}/limits"
```

### Recent Reverb logs

```bash
tail -100 /home/forge/realtime.example.com/storage/logs/reverb.log
```

## Suggested alert thresholds

Initial thresholds should be tuned after observing normal production traffic.

| Metric | Initial warning | Initial critical |
|---|---:|---:|
| CPU sustained for 10 minutes | 60% | 85% |
| VM memory used | 75% | 90% |
| Reverb file descriptors | 70% of limit | 90% of limit |
| Disk used | 75% | 90% |
| Reverb TCP listener | Not listening | Not listening |
| Public WebSocket handshake | Failure | Failure |

## Scaling notes

The following are operational triggers rather than fixed product limits:

- Below approximately 500 concurrent connections with low CPU and memory: keep the initial 2 vCPU / 4 GB allocation.
- Approaching 750–1,000 open connections: confirm an alternative event loop is active and verify all file limits.
- Sustained CPU above approximately 60–70%: consider increasing to 4 vCPU.
- Sustained memory above approximately 70%: investigate connection count and message fan-out, then consider 8 GB RAM.
- Several thousand connections or heavy telemetry fan-out: evaluate multiple Reverb processes or nodes with shared Redis scaling.

For a Dallas-active and Charlotte-standby design, both hosts should use the same application IDs, keys, secrets, allowed origins, and production configuration. Reverb messages are not durable, so clients should refresh current application state after reconnecting or regional failover.

## Rollback

If enabling `ext-uv` causes instability:

```bash
sudo phpdismod -v 8.5 uv
sudo supervisorctl restart soundboard-reverb
```

Verify that Reverb returns to the default event loop:

```bash
cd /home/forge/realtime.example.com

/usr/bin/php8.5 -r '
require "vendor/autoload.php";
echo get_class(React\\EventLoop\\Loop::get()), PHP_EOL;
'
```

The file-descriptor and Nginx limits may remain in place; they do not require `ext-uv` and are still appropriate for Reverb.

## References

- Laravel Reverb documentation: https://laravel.com/docs/13.x/reverb
- Laravel Forge Reverb documentation: https://forge.laravel.com/docs/sites/laravel#laravel-reverb
- `amphp/ext-uv` source repository: https://github.com/amphp/ext-uv
- PECL `uv` package: https://pecl.php.net/package/uv
