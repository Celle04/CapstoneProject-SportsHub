<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_administrator_can_log_in_with_database_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'username' => 'admin',
            'password' => 'password',
            'role' => 'Administrator',
        ]);

        $response = $this->post(route('login.submit'), [
            'username' => 'admin@example.com',
            'password' => 'password',
            'role' => 'Administrator',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_student_can_log_in_with_database_credentials(): void
    {
        User::factory()->create([
            'email' => 'student@example.com',
            'password' => 'password',
            'role' => 'Student',
        ]);

        $response = $this->post(route('login.submit'), [
            'username' => 'student@example.com',
            'password' => 'password',
            'role' => 'Student',
        ]);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_student_can_log_in_with_username(): void
    {
        User::factory()->create([
            'email' => 'student2@example.com',
            'username' => 'juan.delacruz',
            'password' => 'password',
            'role' => 'Student',
        ]);

        $response = $this->post(route('login.submit'), [
            'username' => 'juan.delacruz',
            'password' => 'password',
            'role' => 'Student',
        ]);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $response = $this->post(route('login.submit'), [
            'username' => 'unknown@example.com',
            'password' => 'wrong-password',
            'role' => 'Student',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_portal_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_login_is_rate_limited_per_identifier_and_address(): void
    {
        User::factory()->create([
            'email' => 'athlete@example.com',
            'username' => 'athlete1',
            'password' => 'password',
            'role' => 'Student',
        ]);

        // Five wrong attempts are allowed; each is still a normal rejection.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.submit'), [
                'username' => 'athlete1',
                'password' => 'wrong-password',
                'role' => 'Student',
            ])->assertRedirect(route('login'));
        }

        // The sixth attempt in the same minute is throttled.
        $this->post(route('login.submit'), [
            'username' => 'athlete1',
            'password' => 'wrong-password',
            'role' => 'Student',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_login_rate_limit_does_not_block_a_different_account(): void
    {
        User::factory()->create([
            'email' => 'first@example.com',
            'username' => 'first.athlete',
            'password' => 'password',
            'role' => 'Student',
        ]);
        User::factory()->create([
            'email' => 'second@example.com',
            'username' => 'second.athlete',
            'password' => 'password',
            'role' => 'Student',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.submit'), [
                'username' => 'first.athlete',
                'password' => 'wrong-password',
                'role' => 'Student',
            ]);
        }

        // A different identifier from the same address can still sign in.
        $this->post(route('login.submit'), [
            'username' => 'second.athlete',
            'password' => 'password',
            'role' => 'Student',
        ])->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticated();
    }
}