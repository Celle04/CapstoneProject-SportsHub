<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FailedPasswordAttemptNotification;
use App\Notifications\FailedPasswordNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FailedPasswordWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_a_wrong_password_warns_the_account_holder_and_the_coordinators_by_email(): void
    {
        Notification::fake();

        $athlete = $this->athlete();
        $coordinator = $this->coordinator();

        $response = $this->post(route('login.submit'), [
            'username' => 'athlete@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');

        Notification::assertSentTo($athlete, FailedPasswordNotification::class);
        Notification::assertSentTo($coordinator, FailedPasswordAttemptNotification::class);
    }

    public function test_an_unknown_account_is_not_reported_to_the_coordinators(): void
    {
        Notification::fake();

        $this->coordinator();

        $response = $this->post(route('login.submit'), [
            'username' => 'nobody@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');

        // An identifier that matches no account is counted but never forwarded,
        // so the form cannot be used to flood the coordinators or to discover
        // which addresses are enrolled.
        Notification::assertNothingSent();
    }

    public function test_the_warning_email_never_contains_the_raw_identifier(): void
    {
        Notification::fake();

        $this->athlete();
        $this->coordinator();

        $this->post(route('login.submit'), [
            'username' => 'athlete@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ]);

        Notification::assertSentTo(
            User::where('email', 'coordinator@example.com')->firstOrFail(),
            FailedPasswordAttemptNotification::class,
            function (FailedPasswordAttemptNotification $notification) {
                // The notification payload the email is built from must carry
                // only the masked identifier.
                return ! str_contains((string) ($notification->details['identifier'] ?? ''), 'athlete@example.com')
                    && str_contains((string) ($notification->details['identifier'] ?? ''), '***@example.com');
            },
        );
    }

    public function test_the_log_never_contains_the_raw_identifier(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);

        $this->athlete();
        $this->coordinator();

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = (string) $message->message.' '.json_encode($message->context);
        });

        $this->post(route('login.submit'), [
            'username' => 'athlete@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ])->assertSessionHasErrors('username');

        $this->assertNotEmpty($logged, 'A warning should have been logged.');

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('athlete@example.com', $line);
        }
    }

    public function test_a_successful_sign_in_sends_no_warning(): void
    {
        Notification::fake();

        $this->athlete();
        $this->coordinator();

        $this->post(route('login.submit'), [
            'username' => 'athlete@example.com',
            'password' => 'correct-password',
            'role' => 'Student',
        ])->assertRedirect(route('student.dashboard'));

        Notification::assertNothingSent();
    }

    public function test_repeated_wrong_passwords_are_throttled_to_a_single_warning(): void
    {
        Notification::fake();

        $athlete = $this->athlete();
        $this->coordinator();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('login.submit'), [
                'username' => 'athlete@example.com',
                'password' => 'wrong-password',
                'role' => 'Student',
            ])->assertSessionHasErrors('username');
        }

        Notification::assertSentToTimes($athlete, FailedPasswordNotification::class, 1);
        Notification::assertSentTimes(FailedPasswordAttemptNotification::class, 1);
    }

    public function test_a_wrong_temporary_password_on_the_change_password_page_warns_by_email(): void
    {
        Notification::fake();

        $athlete = $this->athlete(['must_change_password' => true]);
        $coordinator = $this->coordinator();

        $response = $this->actingAs($athlete)->post(route('student.change-password.update'), [
            'current_password' => 'wrong-temporary-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        Notification::assertSentTo($athlete, FailedPasswordNotification::class);
        Notification::assertSentTo($coordinator, FailedPasswordAttemptNotification::class);
    }

    public function test_a_wrong_current_password_on_the_profile_page_warns_by_email(): void
    {
        Notification::fake();

        $athlete = $this->athlete();
        $coordinator = $this->coordinator();

        $response = $this->actingAs($athlete)->patch(route('student.profile.password'), [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        Notification::assertSentTo($athlete, FailedPasswordNotification::class);
        Notification::assertSentTo($coordinator, FailedPasswordAttemptNotification::class);
    }

    public function test_the_password_stays_untouched_after_a_wrong_password(): void
    {
        Notification::fake();

        $athlete = $this->athlete();

        $this->actingAs($athlete)->patch(route('student.profile.password'), [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(password_verify('correct-password', $athlete->fresh()->password));
    }

    public function test_a_mailer_without_credentials_logs_the_warning_instead_of_waiting_for_a_timeout(): void
    {
        Notification::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);

        $athlete = $this->athlete();

        $this->post(route('login.submit'), [
            'username' => 'athlete@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ])->assertSessionHasErrors('username');

        Notification::assertNothingSent();
    }

    /**
     * An athlete with a known password.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function athlete(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Juan Dela Cruz',
            'email' => 'athlete@example.com',
            'username' => 'juan.delacruz',
            'password' => 'correct-password',
            'role' => 'Student',
        ], $attributes));
    }

    /**
     * A sports coordinator.
     */
    private function coordinator(): User
    {
        return User::factory()->create([
            'name' => 'Sports Coordinator',
            'email' => 'coordinator@example.com',
            'username' => 'coordinator',
            'password' => 'correct-password',
            'role' => 'Administrator',
        ]);
    }
}
