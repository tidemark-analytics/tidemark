# Contributing to Tidemark

Thanks for helping! Tidemark aims to stay small: one clear dashboard, easy installs on shared hosting, and no personal data collected. Changes that fit that spirit are very welcome.

## Getting started

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan tidemark:demo     # fake traffic; login demo@example.com / password
php artisan serve
```

The dashboard is a no-build Preact app in `public/assets/js` (ES modules, no Node needed). Icons are Lucide, built into one sprite by `scripts/build-icons.mjs`.

## Before you open a pull request

- `php artisan test` passes and new behaviour has a test.
- `vendor/bin/pint` leaves nothing to fix.
- The change works on SQLite, MySQL/MariaDB and PostgreSQL (use the helpers in `App\Analytics\Support\Sql` instead of vendor-specific SQL).
- User-facing changes are noted in `CHANGELOG.md`.
- Keep the shared-hosting promise: no required Node, Docker, SSH, daemons or non-bundled PHP extensions.

## Privacy rules

Never store IP addresses, raw user agents or anything that follows a person across days. Pull requests that weaken this will not be merged.

## Reporting security issues

Please don't open a public issue — see [SECURITY.md](SECURITY.md).
