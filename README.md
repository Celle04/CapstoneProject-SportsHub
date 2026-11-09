<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Deploying on Railway

Railway builds the root `Dockerfile` with PHP 8.3, Apache 2.4, and Node.js 22.
The Apache entrypoint listens on Railway's assigned `PORT`; `railway.json`
configures `/up` as the health check. No local `.env` file is copied into the
image. The repository has no root `package-lock.json`, so the asset stage uses
`npm install` rather than `npm ci`.

Create a Railway MySQL service and link it to the web service. Set these
variables on the web service (replace `MySQL` with the exact database service
name shown in Railway):

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | A fresh key generated with `php artisan key:generate --show`; save it only in Railway |
| `APP_URL` | `https://${{RAILWAY_PUBLIC_DOMAIN}}` |
| `APP_TIMEZONE` | `Asia/Manila` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `DB_DATABASE` | `${{MySQL.MYSQLDATABASE}}` |
| `DB_USERNAME` | `${{MySQL.MYSQLUSER}}` |
| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` |
| `RUN_MIGRATIONS` | `true` to run `php artisan migrate --force` at container startup |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_ENCRYPT` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `FILESYSTEM_DISK` | `local` |

Keep the MySQL password and `APP_KEY` in Railway variables only. The key
previously used in local configuration was exposed; generate a new production
key. Changing an existing production key invalidates encrypted sessions and
other values encrypted with the old key.

Railway's container filesystem is ephemeral. Before relying on uploaded
documents or images, attach a Railway volume mounted at
`/var/www/html/storage/app`. The entrypoint creates the required Laravel
directories and storage link. The entrypoint runs migrations at startup when
`RUN_MIGRATIONS=true` (the default); set it to `false` to defer them. Confirm
the production database target and backups before enabling migrations.
One existing migration backfills legacy medical-record fields; review that
mapping before running migrations against a populated database. Migrations are
not run during the Docker build.

### Internal messages and medical reminders

SportsHub messages and medical checkup reminders are delivered through the
existing MySQL-backed inbox and Laravel database notifications. They do not
use SMTP, Resend, or SMS. The messages migration creates `messages` and
`message_recipients`; apply it through the normal Railway migration step (or
run `php artisan migrate --force` after confirming the production database and
backup). Students see only messages addressed to their own account, and
opening a message records its read timestamp.

The demo `admin@example.com` account is only seeded in local and testing
environments. To create a production administrator, open the Railway web
service console after this code is deployed and run `php artisan admin:create`.
The command prompts for a name, email, username, and a hidden password; it
creates an active administrator without printing or storing a default password
in the repository.

To deploy this branch to the configured GitHub remote:

```powershell
git add Dockerfile .dockerignore docker/entrypoint.sh docker/apache-vhost.conf railway.json README.md
git commit -m "fix: deploy Laravel on Railway with Apache"
git push new-origin render-deploy
```

In Railway, connect the GitHub repository and select the `render-deploy`
branch, or trigger a redeploy after pushing. Confirm the MySQL variables are
available, then deploy and check the build/runtime logs and `/up` health check.

## Deploying on Vercel

This is a server-rendered Laravel application, not a static Vite site. The
`Dockerfile.vercel` image runs Laravel with FrankenPHP and builds the Vite
assets as part of the image. In Vercel, use the **Container** framework preset;
do not configure `dist` or `public/build` as a static output directory.

Configure these production environment variables in Vercel:

- `APP_KEY`: generate with `php artisan key:generate --show`; add the result as
  a secret and do not commit it.
- `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` set to the canonical
  HTTPS deployment URL.
- `DB_CONNECTION=mysql` and the `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, and `DB_PASSWORD` values for an externally hosted MySQL
  database. The database must accept connections from the deployment.
- `SESSION_DRIVER=database`, `CACHE_STORE=database`, and
  `SESSION_SECURE_COOKIE=true`.
- `LOG_CHANNEL=stderr` so application logs are available in Vercel runtime
  logs.
- Configure the `MAIL_*` variables if password-reset email is required.

Run `php artisan migrate --force` against the production database before
serving traffic. Do not rely on the container filesystem for uploaded files:
it is ephemeral across redeployments and instances. Before enabling
production uploads, configure durable object storage, install its required
Flysystem adapter, and set `FILESYSTEM_DISK` to that storage driver.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
