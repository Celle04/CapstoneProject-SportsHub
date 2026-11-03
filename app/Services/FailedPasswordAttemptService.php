<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\FailedPasswordAttemptNotification;
use App\Notifications\FailedPasswordNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warns about a wrong password by email, both on the screen and in the
 * inbox.
 *
 * The service owns the three decisions the screens must not repeat: who is
 * warned, what the warning says and how often a warning may be sent. The
 * screens only report that a wrong password was typed.
 *
 * Two rules keep the feature safe:
 *
 *  1. The account holder is only warned when the account really exists, so
 *     the sign-in form can never be used to send mail to an arbitrary
 *     address.
 *  2. Every warning is throttled, so repeated attempts cannot be used to
 *     flood a mailbox.
 */
class FailedPasswordAttemptService
{
    /**
     * Report a sign-in attempt that was rejected because the password was
     * wrong.
     *
     * The account is only passed when the identifier matched an account of
     * the portal that was chosen, which means the password itself was the
     * problem. Any other rejected attempt (an unknown identifier, or an
     * account of the other portal) is reported to the coordinators only,
     * because the account holder cannot be told that a password was wrong
     * when it may not have been.
     */
    public function reportLoginFailure(?User $account, string $identifier, string $role, Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        $details = $this->details($account, $identifier, $role, $request);

        $this->logAttempt('sign-in', $details);

        if ($account && $this->shouldWarnAccountOwner($account)) {
            $this->send($account, new FailedPasswordNotification('login', $details), 'account holder');
        }

        /*
         * Only a real account is reported to the coordinators. An identifier
         * that matched nothing is counted but never forwarded, so the sign-in
         * form cannot be used to make the app email the coordinators or to
         * reveal which addresses are enrolled.
         */
        if ($account) {
            $this->warnCoordinators('login', $details, $request);
        }
    }

    /**
     * Report a wrong password typed on one of the password change screens,
     * where the athlete is already signed in and proves the password by
     * typing it.
     */
    public function reportChangePasswordFailure(User $account, Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        $details = $this->details($account, (string) $account->email, (string) $account->role, $request);

        $this->logAttempt('password change', $details);

        if ($this->shouldWarnAccountOwner($account)) {
            $this->send($account, new FailedPasswordNotification('change-password', $details), 'account holder');
        }

        $this->warnCoordinators('change-password', $details, $request);
    }

    /**
     * Whether the warning emails are switched on.
     */
    private function enabled(): bool
    {
        return (bool) config('security.failed_password_warnings.enabled', true);
    }

    /**
     * The details that go into the warning emails.
     *
     * @return array<string, mixed>
     */
    private function details(?User $account, string $identifier, string $role, Request $request): array
    {
        $window = $this->attemptWindow();

        return [
            'account' => $account?->name,
            'account_email' => $account?->email,
            'identifier' => $identifier,
            'role' => $role,
            'when' => now()->format('M j, Y g:i A'),
            'ip' => $request->ip() ?: 'Unknown',
            'device' => $this->deviceLabel($request),
            'user_agent' => $this->userAgent($request),
            'attempts' => $this->countAttempt($account, $request),
            'window' => $window,
        ];
    }

    /**
     * Warn the holder of the account, at most once per throttle window.
     */
    private function shouldWarnAccountOwner(User $account): bool
    {
        if (! config('security.failed_password_warnings.notify_account_owner', true)) {
            return false;
        }

        return $this->claimThrottle(
            'sportshub:failed-password:throttle:owner:'.$account->id,
            (int) config('security.failed_password_warnings.owner_throttle_minutes', 5),
        );
    }

    /**
     * Warn every sports coordinator about the attempt.
     *
     * The warning is throttled per network source, so a flood of attempts
     * from one place cannot turn into a flood of coordinator mailboxes.
     */
    private function warnCoordinators(string $context, array $details, Request $request): void
    {
        if (! config('security.failed_password_warnings.notify_administrators', true)) {
            return;
        }

        $claimed = $this->claimThrottle(
            'sportshub:failed-password:throttle:coordinator:'.sha1((string) $request->ip()),
            (int) config('security.failed_password_warnings.admin_throttle_minutes', 5),
        );

        if (! $claimed) {
            return;
        }

        foreach ($this->coordinators() as $coordinator) {
            $this->send($coordinator, new FailedPasswordAttemptNotification($context, $details), 'coordinator');
        }
    }

    /**
     * The coordinators that receive the warning.
     *
     * @return Collection<int, User>
     */
    private function coordinators(): Collection
    {
        return User::query()
            ->where('role', 'Administrator')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }

    /**
     * Claim the throttle slot for a key.
     *
     * Cache::add only stores the key when it is not there yet, so the first
     * attempt inside a window wins and the ones after it are suppressed. A
     * cache that is not reachable must not stop the warning, so the slot is
     * treated as claimed when it cannot be written.
     */
    private function claimThrottle(string $key, int $minutes): bool
    {
        try {
            return Cache::add($key, true, now()->addMinutes(max(1, $minutes)));
        } catch (Throwable $exception) {
            Log::error('Incorrect password throttle could not be stored: '.$exception->getMessage());

            return true;
        }
    }

    /**
     * Count how many wrong passwords were entered recently.
     *
     * Attempts against a known account are counted per account, attempts
     * against an unknown identifier per network source, so the number in the
     * warning always describes the same thing.
     */
    private function countAttempt(?User $account, Request $request): int
    {
        $key = $account
            ? 'sportshub:failed-password:attempts:account:'.$account->id
            : 'sportshub:failed-password:attempts:source:'.sha1((string) $request->ip());

        try {
            if (! Cache::has($key)) {
                Cache::put($key, 1, now()->addMinutes($this->attemptWindow()));

                return 1;
            }

            $count = Cache::increment($key);

            return $count === false ? 1 : (int) $count;
        } catch (Throwable $exception) {
            Log::error('Incorrect password attempt counter could not be read: '.$exception->getMessage());

            return 1;
        }
    }

    /**
     * The window used to count recent attempts, in minutes.
     */
    private function attemptWindow(): int
    {
        return max(1, (int) config('security.failed_password_warnings.attempt_window_minutes', 15));
    }

    /**
     * Send a warning, without ever letting a mail server problem break the
     * screen the athlete is looking at.
     *
     * @param  string  $recipientRole  Who the warning is meant for, used in
     *                                 the log entry.
     */
    private function send(User $recipient, Notification $notification, string $recipientRole): void
    {
        if (! $this->mailerIsConfigured()) {
            Log::warning(sprintf(
                'Incorrect password warning for the %s was not emailed because the "%s" mailer has no credentials. The warning was written here instead: %s',
                $recipientRole,
                (string) config('mail.default'),
                $this->describe($notification),
            ));

            return;
        }

        try {
            $recipient->notify($notification);
        } catch (Throwable $exception) {
            Log::error('Incorrect password warning for account '.$recipient->id.' could not be delivered: '.$exception->getMessage());
        }
    }

    /**
     * Whether the configured mailer can actually deliver a mail.
     *
     * A local install often ships with the SMTP transport but without the
     * credentials filled in. Sending anyway would make every failed sign-in
     * wait for a connection that can never succeed, so the warning is written
     * to the log instead of being emailed.
     */
    private function mailerIsConfigured(): bool
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return true;
        }

        if ($mailer === 'smtp') {
            return filled(config('mail.mailers.smtp.username'))
                && filled(config('mail.mailers.smtp.password'));
        }

        return true;
    }

    /**
     * A short description of a warning, used when it can only be logged.
     */
    private function describe(Notification $notification): string
    {
        $context = property_exists($notification, 'context') ? $notification->context : 'unknown';
        $details = property_exists($notification, 'details') ? $notification->details : [];

        return sprintf(
            '%s attempt for "%s" from %s.',
            $context,
            $details['identifier'] ?? 'unknown',
            $details['ip'] ?? 'unknown',
        );
    }

    /**
     * Record the attempt in the application log.
     *
     * The identifier is masked and the password is never part of the entry.
     *
     * @param  array<string, mixed>  $details
     */
    private function logAttempt(string $context, array $details): void
    {
        Log::warning(sprintf(
            'Incorrect password entered (%s) for %s [%s] from %s.',
            $context,
            $details['account'] ?? 'an unknown account',
            self::maskIdentifier((string) $details['identifier']),
            $details['ip'],
        ));
    }

    /**
     * A short description of the browser and the device, e.g.
     * "Chrome on Windows".
     */
    private function deviceLabel(Request $request): string
    {
        $agent = $this->userAgent($request);

        if ($agent === 'Unknown') {
            return 'Unknown';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return $browser.' on '.$platform;
    }

    /**
     * The raw browser details, kept for the coordinators.
     */
    private function userAgent(Request $request): string
    {
        $agent = trim((string) $request->userAgent());

        if ($agent === '') {
            return 'Unknown';
        }

        return mb_substr($agent, 0, 255);
    }

    /**
     * Mask an identifier for the log, e.g. j***@gmail.com.
     */
    private static function maskIdentifier(string $identifier): string
    {
        if (! str_contains($identifier, '@')) {
            return $identifier === '' ? '***' : substr($identifier, 0, 1).'***';
        }

        [$name, $domain] = array_pad(explode('@', $identifier, 2), 2, '');

        if ($name === '') {
            return '***@'.$domain;
        }

        $stars = match (true) {
            strlen($name) >= 3 => 3,
            strlen($name) === 2 => 2,
            default => 1,
        };

        return substr($name, 0, 1).str_repeat('*', $stars).'@'.$domain;
    }
}
