<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seed data creates accounts with the password "password", so the
 * seeder must never run outside the local and testing environments.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_creates_demo_accounts_locally(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'Administrator',
        ]);
    }

    public function test_the_seeder_refuses_to_run_in_production(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true]);
            $this->fail('Expected DatabaseSeeder to refuse running in production.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('DatabaseSeeder is disabled outside local/testing', $e->getMessage());
        } finally {
            $this->app['env'] = $previous;
        }

        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'student@example.com']);
    }
}
