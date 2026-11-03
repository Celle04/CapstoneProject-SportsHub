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

    public function test_the_seeder_creates_no_demo_accounts_in_production(): void
    {
        $this->app['env'] = 'production';

        // db:seed asks for confirmation in production; --force answers it.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'student@example.com']);
    }
}
