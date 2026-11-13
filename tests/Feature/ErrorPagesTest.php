<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_athlete_opening_an_administrator_page_sees_a_branded_403_with_a_way_home(): void
    {
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active']);

        $this->actingAs($athlete)->get(route('events.create'))
            ->assertForbidden()
            ->assertSee('You do not have access to this page')
            ->assertSee('Go to my portal')
            ->assertSee(route('student.dashboard'), false);
    }

    public function test_a_guest_hitting_an_unknown_page_sees_a_branded_404(): void
    {
        $this->get('/definitely-not-a-real-page')
            ->assertNotFound()
            ->assertSee('We could not find that page')
            ->assertSee('Sign in');
    }

    public function test_an_administrator_sees_their_own_portal_on_the_404_page(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);

        $this->actingAs($admin)->get('/definitely-not-a-real-page')
            ->assertNotFound()
            ->assertSee('We could not find that page')
            ->assertSee(route('dashboard'), false);
    }
}
