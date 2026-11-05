# Render deployment notes

## Changes on this branch

- `b9284ce deploy: add Render Docker runtime` — adds a multi-stage Docker build, Apache entrypoint, and Docker context exclusions.
- `1a72859 deploy: deduplicate Docker ignore rules` — removes a duplicate ignore pattern.
- `f0200a7 deploy: trust Render proxy headers` — trusts forwarded protocol/client-IP headers and generates HTTPS URLs in production.
- `deploy: document Render settings` — adds safe production environment placeholders and these deployment notes.

The Docker entrypoint fails on storage-link errors instead of hiding them. Docker was not available locally, so the image and container smoke test could not be run.

`php artisan test` completed with 363 passing tests and one unrelated existing failure: `EventCalendarTest::test_a_multi_day_event_is_visible_in_both_months_it_spans` expects October-specific day counts while deriving its dates from the current month. The new proxy test passes.

## Render environment variables

Set these on the Render Docker web service. Replace every placeholder with the actual production value.

| Variable | Value | Secret? |
|---|---|---|
| `APP_KEY` | Generate with `php artisan key:generate --show` | Yes |
| `APP_ENV` | `production` | No |
| `APP_DEBUG` | `false` | No |
| `APP_URL` | `https://<your-service>.onrender.com` (or your custom HTTPS domain) | No |
| `LOG_CHANNEL` | `stderr` | No |
| `LOG_LEVEL` | `warning` | No |
| `CACHE_STORE` | `database` | No |
| `DB_CONNECTION` | `mysql` | No |
| `DB_HOST` | Your hosted MySQL hostname | No |
| `DB_PORT` | Provider port (usually `3306`) | No |
| `DB_DATABASE` | Your database name | No |
| `DB_USERNAME` | Your database user | Treat as sensitive |
| `DB_PASSWORD` | Your database password | Yes |
| `MYSQL_ATTR_SSL_CA` | CA certificate path in the container; leave empty if TLS CA is not required | No (certificate file must be available to the container) |
| `SESSION_DRIVER` | `database` | No |
| `SESSION_SECURE_COOKIE` | `true` | No |
| `SESSION_ENCRYPT` | `true` for a fresh deployment | No |
| `FILESYSTEM_DISK` | `local` | No |
| `QUEUE_CONNECTION` | `database` (or `sync`; see queue note below) | No |
| `MAIL_MAILER` | `smtp` (or the configured Laravel mail transport) | No |
| `MAIL_SCHEME` | `smtp` for STARTTLS or `smtps` for implicit TLS | No |
| `MAIL_ENCRYPTION` | `tls` for the supplied SMTP example | No |
| `MAIL_HOST` | Your SMTP hostname | No |
| `MAIL_PORT` | Your SMTP port | No |
| `MAIL_AUTO_TLS` | `true` when supported by the SMTP provider | No |
| `MAIL_USERNAME` | SMTP account username | Treat as sensitive |
| `MAIL_PASSWORD` | SMTP password or provider app password | Yes |
| `MAIL_FROM_ADDRESS` | Verified sender address | No |
| `MAIL_FROM_NAME` | `SNNHS SportsHub` | No |
| `RUN_MIGRATIONS` | `true` to run `php artisan migrate --force` at container startup | No |

Render supplies `PORT`; the container defaults to `10000` when it is absent. Do not set `APP_DEBUG=true` or reuse a development `APP_KEY` in production.

The sample also retains `QUEUE_CONNECTION=database`. No application notification, mail, or job class implements `ShouldQueue`; current notifications send synchronously, so existing email is not dependent on a queue worker. If future code dispatches queued jobs, add a Render worker or choose `QUEUE_CONNECTION=sync` to avoid jobs waiting unprocessed.

## Deployment findings and remaining decisions

- No application runtime references to `/SportsHub`, `/public/`, `/storage/`, or `/build/` need rewriting for a domain-root deployment. The sample `APP_URL` now uses a root-domain placeholder.
- `public/css/landing.css:561` uses `/images/sports-banner.jpg`, which is root-relative and therefore resolves correctly at the Render domain root. That file is absent from `public/images`, so the landing banner image will not load; decide whether to add the intended image or remove/replace that background.
- `public/js/offline.js:14-20` derives the worker URL and scope from the script path; `public/sw.js:9-19` resolves the app shell from the worker URL. `public/manifest.webmanifest:5-6` uses `./` for `start_url` and `scope`. These are root-safe and also support subfolders.
- `resources/css/app.css:4` and `vite.config.js:15` refer to the local compiled-view directory for Tailwind/Vite, not browser URLs.
- `config/filesystems.php` stores both private and public files under `storage/app/private` and `storage/app/public`. Application documents, medical certificates, and generated certificate PDFs use the private disk; profile photos and sports/coach images use the public disk. Mount the persistent disk at `/var/www/html/storage/app`.
- `config/logging.php` defines the `stderr` channel. `config/database.php` reads `MYSQL_ATTR_SSL_CA` for MySQL/MariaDB TLS.
- Migrations create the `sessions`, `cache`, and `jobs` tables. No `env()` calls were found outside `config/`.
- No notification, mail, or job class implements `ShouldQueue`; no queue worker is needed for current synchronous notifications. Decide whether to retain the sample database queue driver or set `QUEUE_CONNECTION=sync` before adding queued work.
- `SESSION_ENCRYPT=true` is suitable for a new service. Changing it on a live service makes existing encrypted session data unreadable.

## Manual checklist

- [ ] Review the commits on `render-deploy`; do not merge it into another branch yet.
- [ ] Push `render-deploy` and create a new Render Web Service using the **Docker** runtime and this branch.
- [ ] Add a persistent disk mounted at `/var/www/html/storage/app`.
- [ ] Set the environment variables above, including a new production `APP_KEY`, database credentials, and mail credentials.
- [ ] Confirm the production database is reachable from Render before enabling `RUN_MIGRATIONS=true`; the entrypoint runs migrations at startup.
- [ ] Deploy and check the landing page and Render logs.
- [ ] Create the real coordinator account; rotate any shared or previously distributed credentials.
- [ ] Complete Batch 1 of the security fixes (default admin account, login throttle, and package updates) before storing real student data.
