# Contributing

Thanks for your interest in improving Soundboard.

## Reporting bugs and requesting features

Open an issue with steps to reproduce, what you expected, and what happened. For security problems, follow [SECURITY.md](SECURITY.md) instead.

## Development setup

```bash
composer setup    # install dependencies, create .env, migrate, build assets
php artisan db:seed   # creates admin@example.com and prints its password
composer dev      # app server, queue, logs, and Vite
php artisan reverb:start
```

## Pull requests

- Keep each PR focused on one change, and describe the problem it solves.
- Add or update tests for behaviour changes, especially anything touching permissions or credentials.
- Before pushing, make sure these pass:

```bash
composer test
vendor/bin/pint --test
```

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
