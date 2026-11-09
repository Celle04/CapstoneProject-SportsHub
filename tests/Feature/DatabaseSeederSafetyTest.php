<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_fails_fast_outside_local_and_testing(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('DatabaseSeeder is disabled outside local/testing');
            $this->app->make(DatabaseSeeder::class)->run();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_database_seeder_runs_in_testing_environment(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }
}
