# Security Policy

Soundboard stores Reverb app credentials and controls who can read them, so security reports are taken seriously.

## Reporting a vulnerability

**Please don't open a public issue for security problems.** Report them privately through GitHub's [private vulnerability reporting](../../security/advisories/new) for this repository.

Include what you can of:

- The affected version or commit
- Steps to reproduce, or a proof of concept
- The impact you believe it has

You should get an acknowledgement within a few days. Once a fix is ready, it will be released and the advisory published, crediting you unless you prefer otherwise.

## Supported versions

Only the latest release on the `main` branch receives security fixes.

## Deployment notes

- Keep `APP_KEY` secret and backed up. App secrets are encrypted with it, so losing or changing it makes every stored secret unreadable.
- Serve the dashboard over HTTPS only.
- `/api/health` is intentionally unauthenticated and returns only `ok`/`error` per check. Everything else requires login or an API token.
