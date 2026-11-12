<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Coach;
use App\Models\Event;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class EventSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_the_calendar_opens_the_events_it_shows(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'School basketball program']);
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $sport->id]);

        $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Basketball practice',
            'sport_id' => $sport->id,
            'event_type' => 'Practice',
            'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d\TH:i'),
            'venue' => 'Main Court',
            'status' => 'Scheduled',
        ])->assertRedirect();

        $event = Event::query()->where('title', 'Basketball practice')->firstOrFail();

        // The calendar of the sports office opens the event.
        $this->actingAs($admin)->get(route('admin.calendar'))
            ->assertOk()
            ->assertSee('href="'.route('events.show', $event).'"', false);

        // The calendar of the athlete opens the event as well.
        $this->actingAs($athlete)->get(route('student.calendar'))
            ->assertOk()
            ->assertSee('href="'.route('student.events.show', $event).'"', false);

        // And the page it opens really is the event.
        $this->actingAs($athlete)->get(route('student.events.show', $event))
            ->assertOk()
            ->assertSee('Basketball practice');
    }

    public function test_a_new_event_reaches_the_athlete_even_when_it_is_already_running(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'School basketball program']);
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $sport->id]);

        // The event is running right now: the athlete still has to see it.
        $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Ongoing tournament',
            'sport_id' => $sport->id,
            'event_type' => 'Tournament',
            'starts_at' => now()->subHours(2)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHour()->format('Y-m-d\TH:i'),
            'venue' => 'Gym',
            'status' => 'Ongoing',
        ])->assertRedirect();

        $this->actingAs($athlete)->get(route('student.schedule'))
            ->assertOk()
            ->assertSee('Ongoing tournament');

        $this->actingAs($athlete)->get(route('student.calendar'))
            ->assertOk()
            ->assertSee('Ongoing tournament');

        $this->actingAs($athlete)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Ongoing tournament');

        $this->assertSame(1, $athlete->notifications()->count());
    }

    public function test_a_settled_event_is_left_out_of_the_upcoming_lists(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'School basketball program']);
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $sport->id]);

        // The event is already over.
        $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Earlier today',
            'sport_id' => $sport->id,
            'event_type' => 'Practice',
            'starts_at' => now()->subHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->subHours(2)->format('Y-m-d\TH:i'),
            'venue' => 'Main Court',
            'status' => 'Scheduled',
        ])->assertRedirect();

        // It is history: it does not belong to what is coming, but the month
        // of the calendar still shows it.
        $this->actingAs($athlete)->get(route('student.schedule'))
            ->assertOk()
            ->assertDontSee('Earlier today');

        $this->actingAs($athlete)->get(route('student.calendar'))
            ->assertOk()
            ->assertSee('Earlier today');

        $this->assertSame(0, $athlete->notifications()->count());
    }

    public function test_administrator_can_create_and_view_an_event(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'School basketball program']);
        $coach = Coach::create(['name' => 'Coach Santos', 'email' => 'coach@example.com', 'specialty' => 'Basketball', 'sport_id' => $sport->id]);

        $response = $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Basketball Practice',
            'sport_id' => $sport->id,
            'event_type' => 'Practice',
            'starts_at' => '2026-10-02T15:00',
            'ends_at' => '2026-10-02T17:00',
            'venue' => 'Main Court',
            'coach_id' => $coach->id,
            'team_name' => 'Boys Basketball',
            'max_participants' => 20,
            'status' => 'Scheduled',
        ]);

        $event = Event::firstOrFail();
        $response->assertRedirect(route('events.index'));
        $this->assertDatabaseHas('events', ['id' => $event->id, 'event_type' => 'Practice', 'coach_id' => $coach->id]);
        $this->actingAs($admin)->get(route('events.show', $event))->assertOk()->assertSee('Basketball Practice');
    }

    public function test_overlapping_events_in_the_same_venue_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'School volleyball program']);
        Event::create([
            'title' => 'Existing Practice',
            'sport_id' => $sport->id,
            'event_type' => 'Practice',
            'starts_at' => '2026-10-02 15:00:00',
            'ends_at' => '2026-10-02 17:00:00',
            'venue' => 'Main Court',
            'status' => 'Scheduled',
        ]);

        $response = $this->from(route('events.create'))->actingAs($admin)->post(route('events.store'), [
            'title' => 'Overlapping Training',
            'sport_id' => $sport->id,
            'event_type' => 'Training',
            'starts_at' => '2026-10-02T16:00',
            'ends_at' => '2026-10-02T18:00',
            'venue' => 'Main Court',
            'status' => 'Scheduled',
        ]);

        $response->assertRedirect(route('events.create'));
        $response->assertSessionHasErrors('venue');
        $this->assertDatabaseCount('events', 1);
    }

    public function test_event_search_and_status_filter_use_database_queries(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Chess', 'classification' => 'Individual', 'description' => 'School chess program']);
        Event::create(['title' => 'Chess Tournament', 'sport_id' => $sport->id, 'event_type' => 'Tournament', 'starts_at' => '2026-10-03 09:00:00', 'ends_at' => '2026-10-03 12:00:00', 'venue' => 'Library', 'status' => 'Completed']);
        Event::create(['title' => 'Chess Training', 'sport_id' => $sport->id, 'event_type' => 'Training', 'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '2026-10-04 12:00:00', 'venue' => 'Library', 'status' => 'Scheduled']);

        $response = $this->actingAs($admin)->get(route('events.index', ['search' => 'Tournament', 'status' => 'Completed']));

        $response->assertOk()->assertSee('Chess Tournament')->assertDontSee('Chess Training');
    }

    public function test_the_event_form_offers_the_attendance_pop_out_card(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);

        $this->actingAs($admin)->get(route('events.create'))
            ->assertOk()
            ->assertSee('data-app-modal-open="event-attendance-dialog"', false)
            ->assertSee('id="event-attendance-dialog"', false)
            ->assertSee('name="attendance[title]"', false);
    }

    public function test_creating_an_event_can_attach_its_attendance_session(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'School basketball program']);
        $athlete = User::factory()->create(['role' => 'Student', 'sport_id' => $sport->id, 'student_id' => '2026-2001']);

        $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Basketball Practice',
            'sport_id' => $sport->id,
            'event_type' => 'Practice',
            'starts_at' => '2026-10-02T15:00',
            'ends_at' => '2026-10-02T17:00',
            'venue' => 'Main Court',
            'status' => 'Scheduled',
            'attendance' => [
                'title' => 'Basketball Practice Attendance',
                'session_date' => '2026-10-02',
                'start_time' => '15:00',
                'end_time' => '17:00',
                'status' => 'Open',
            ],
        ])->assertSessionHas('success');

        $event = Event::firstOrFail();
        $session = AttendanceSession::firstOrFail();

        $this->assertSame($event->id, $session->event_id);
        $this->assertSame($sport->id, $session->sport_id);
        $this->assertSame('2026-10-02', $session->session_date->toDateString());
        $this->assertDatabaseHas('attendances', [
            'attendance_session_id' => $session->id,
            'user_id' => $athlete->id,
            'status' => 'Pending',
        ]);
    }

    public function test_an_event_can_receive_another_attendance_session_from_the_edit_screen(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'School volleyball program']);
        $event = Event::create([
            'title' => 'Volleyball Training',
            'sport_id' => $sport->id,
            'venue' => 'Gym',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
            'status' => 'Scheduled',
        ]);

        $this->actingAs($admin)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('id="event-attendance-form"', false)
            ->assertSee('name="event_id"', false);

        $this->actingAs($admin)->post(route('admin.attendance.sessions.store'), [
            'title' => 'Volleyball Set 1',
            'event_id' => $event->id,
            'session_date' => today()->toDateString(),
            'status' => 'Open',
        ])->assertSessionHas('success');

        $session = AttendanceSession::firstOrFail();
        $this->assertSame($event->id, $session->event_id);
        $this->assertSame($sport->id, $session->sport_id);
    }
}