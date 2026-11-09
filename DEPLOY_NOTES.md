# Deployment notes (Railway)

## Canonical Railway setup
- **Config**: `railway.json` uses the Dockerfile builder (`dockerfilePath: Dockerfile`), healthcheck `GET /up` (timeout 300), restart on failure.
- **Runtime**: `Dockerfile` (canonical for Railway). Other files (`Dockerfile.apache`, `Dockerfile.vercel`, `Caddyfile`) may exist for alternate targets; treat them as **non-canonical** unless intentionally selected.

## Required environment/config
Set in Railway variables (replace placeholders; mark secrets appropriately):
- `APP_NAME`, `APP_ENV=production`, `APP_KEY` (generate: `php artisan key:generate --show`), `APP_DEBUG=false`
- `APP_URL=https://<your-service>.up.railway.app` (or custom HTTPS domain)
- Database: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (+ `MYSQL_ATTR_SSL_CA` if required)
- Session/cache: `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`
- Files/logging: `FILESYSTEM_DISK=local`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`
- Mail: defaults to `MAIL_MAILER=log` (no SMTP). Configure `resend`/SMTP later only if you enable email features.
- `RUN_MIGRATIONS=true` if container startup runs migrations (confirm entrypoint behavior)

## Operations
- **Migrations**: run `php artisan migrate --force` on deploy/startup as configured. New migrations may be added (e.g. `2026_11_10_000003_add_event_id_to_messages_table`, `2026_11_10_000004/000005` medical follow-ups).
- **Persistent storage (important)**: uploads/certificates/private documents use local/private storage (`storage/app/*`). Mount a **persistent Railway volume** to the app storage path used by your runtime; ensure `php artisan storage:link` and permissions are correct. Without a volume, files are lost on redeploy/restart.
- **Queue posture**: `QUEUE_CONNECTION=database` is configured. Email reminders were removed; no `ShouldQueue` jobs were found in app code during review. If queued jobs/mail are introduced, deploy a worker (e.g. `php artisan queue:work --tries=3 --timeout=90`) and monitor failures.
- **Proxies/HTTPS**: app trusts proxies in bootstrap middleware and forces HTTPS in production (review for your Railway/proxy setup). Ensure `APP_URL` is HTTPS.
- **Security hardening status**: SEC-01 route-level admin authorization added; SEC-02 DatabaseSeeder fails fast outside local/testing; SEC-03 Symfony dependency advisories remediated (`composer audit` clean at update time). Re-run `composer audit` and tests before release; review `SECURITY_AUDIT.md` for remaining items (failed-login PII/logging, etc.).

## Verification
- `php artisan test` (known pre-existing failure: `EventCalendarTest > a multi day event is visible in both months it spans`).
- `composer audit` (should report no known advisories).
- Manual QA: login/authZ (student vs admin), medical follow-ups, event notifications/messages, uploads/downloads (private), reports/exports, `GET /up` healthcheck.
