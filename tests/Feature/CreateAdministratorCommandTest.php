<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_administrator_with_a_hashed_password(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Full name', 'SportsHub Administrator')
            ->expectsQuestion('Email', 'admin@sportshub.example')
            ->expectsQuestion('Username', 'sportshub-admin')
            ->expectsQuestion('Password (input hidden)', 'StrongPassword123')
            ->expectsQuestion('Confirm password', 'StrongPassword123')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@sportshub.example')->firstOrFail();

        $this->assertSame('Administrator', $admin->role);
        $this->assertSame('Active', $admin->status);
        $this->assertSame('sportshub-admin', $admin->username);
        $this->assertTrue(Hash::check('StrongPassword123', $admin->password));
    }
}
