<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\StudentUpdateNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_user_can_browse_notifications_and_mark_only_their_own_as_read(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $otherStudent = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification('Schedule update', 'Practice time changed.', route('student.schedule')));
        $otherStudent->notify(new StudentUpdateNotification('Private update', 'Only for another student.', route('student.schedule')));
        $notification = $student->notifications()->firstOrFail();
        $foreignNotification = $otherStudent->notifications()->firstOrFail();

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Schedule update')
            ->assertSee('portal-notification-content')
            ->assertSee(route('notifications.open', $notification->id))
            ->assertSee('1 unread')
            ->assertDontSee('Only for another student.');

        foreach (range(1, 6) as $index) {
            $student->notify(new StudentUpdateNotification('Extra update '.$index, 'Update message '.$index, route('student.schedule')));
        }

        $this->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Extra update 6');

        // The bell now lives in the shared portal layout, so the student's own
        // notification follows them onto every page - but never another
        // athlete's notification.
        $this->get(route('student.sports'))
            ->assertOk()
            ->assertSee('Schedule update')
            ->assertDontSee('Only for another student.');

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Schedule update')
            ->assertSee('notification-inbox-link')
            ->assertSee(route('notifications.open', $notification->id))
            ->assertDontSee('Only for another student.');

        $this->post(route('notifications.read', $foreignNotification->id))->assertNotFound();
        $this->post(route('notifications.read', $notification->id))->assertRedirect();
        $this->assertNotNull($student->notifications()->whereKey($notification->id)->firstOrFail()->read_at);

        $student->notify(new StudentUpdateNotification('Another update', 'A new schedule is ready.', route('student.schedule')));
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_the_bell_asks_the_server_for_new_notifications(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification('Schedule update', 'Practice time changed.', route('student.schedule')));

        // The bell on the page carries the live feed and a badge to update.
        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-notification-feed="'.route('notifications.feed').'"', false)
            ->assertSee('data-notification-badge', false)
            ->assertSee('data-notification-panel', false)
            ->assertSee('js/notifications.js', false);

        $feed = $this->actingAs($student)->get(route('notifications.feed'));

        $feed->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('badge', '1');

        $this->assertStringContainsString('Schedule update', $feed->json('panel'));
        $this->assertStringContainsString('1 unread', $feed->json('panel'));
    }

    public function test_a_new_notification_shows_up_in_the_feed_without_a_page_reload(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification('First update', 'The first one.', route('student.schedule')));

        $before = $this->actingAs($student)->get(route('notifications.feed'));
        $before->assertOk()->assertJsonPath('unread', 1);

        // A notification arrives while the page is open.
        $student->notify(new StudentUpdateNotification('Second update', 'The second one.', route('student.schedule')));

        $after = $this->actingAs($student)->get(route('notifications.feed'));
        $after->assertOk()->assertJsonPath('unread', 2);

        $this->assertStringContainsString('Second update', $after->json('panel'));
        $this->assertStringContainsString('2 unread', $after->json('panel'));
    }

    public function test_the_feed_only_shows_the_notifications_of_the_signed_in_user(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $otherStudent = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification('Own update', 'For this athlete.', route('student.schedule')));
        $otherStudent->notify(new StudentUpdateNotification('Foreign update', 'For another athlete.', route('student.schedule')));

        $feed = $this->actingAs($student)->get(route('notifications.feed'));

        $feed->assertOk()->assertJsonPath('unread', 1);
        $this->assertStringContainsString('Own update', $feed->json('panel'));
        $this->assertStringNotContainsString('Foreign update', $feed->json('panel'));
    }

    public function test_marking_a_notification_as_read_answers_the_bell_without_a_reload(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification('Schedule update', 'Practice time changed.', route('student.schedule')));
        $notification = $student->notifications()->firstOrFail();

        $this->actingAs($student)
            ->postJson(route('notifications.read', $notification->id))
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->actingAs($student)
            ->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_opening_a_notification_never_redirects_to_an_external_host(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification(
            'Sketchy update',
            'Do not follow this.',
            'https://evil.example/phishing',
        ));

        $notification = $student->notifications()->firstOrFail();

        $this->actingAs($student)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_opening_an_in_app_notification_still_redirects_to_its_page(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $student->notify(new StudentUpdateNotification(
            'Schedule update',
            'Practice time changed.',
            route('student.schedule'),
        ));

        $notification = $student->notifications()->firstOrFail();

        $this->actingAs($student)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('student.schedule'));
    }

    public function test_upcoming_event_updates_notify_students_in_the_affected_sports(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $basketball = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'Court sport']);
        $volleyball = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'Net sport']);
        $basketballStudent = User::factory()->create(['role' => 'Student', 'sport_id' => $basketball->id]);
        $volleyballStudent = User::factory()->create(['role' => 'Student', 'sport_id' => $volleyball->id]);
        $startsAt = now()->addDay()->startOfHour();

        $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Basketball practice',
            'sport_id' => $basketball->id,
            'event_type' => 'Practice',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(2)->format('Y-m-d\TH:i'),
            'venue' => 'Main Court',
            'status' => 'Scheduled',
        ])->assertRedirect();

        $event = \App\Models\Event::firstOrFail();
        $this->assertSame(1, $basketballStudent->notifications()->count());
        $this->assertSame(0, $volleyballStudent->notifications()->count());

        $this->put(route('events.update', $event), [
            'title' => 'Basketball practice',
            'sport_id' => $basketball->id,
            'event_type' => 'Practice',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(2)->format('Y-m-d\TH:i'),
            'venue' => 'Main Court',
            'status' => 'Cancelled',
        ])->assertRedirect();

        $this->assertSame(2, $basketballStudent->notifications()->count());
        $this->assertSame(0, $volleyballStudent->notifications()->count());
    }

    public function test_application_and_attendance_changes_notify_the_affected_student(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $sport = Sport::create(['name' => 'Track', 'classification' => 'Individual', 'description' => 'Track program']);
        $student = User::factory()->create(['role' => 'Student', 'sport_id' => $sport->id, 'student_id' => 'ST-100']);
        $application = Application::create([
            'name' => $student->name,
            'student_id' => $student->student_id,
            'grade' => 'Grade 10',
            'gender' => 'Prefer not to say',
            'email' => $student->email,
            'sport' => $sport->name,
            'sport_id' => $sport->id,
            'status' => 'Pending',
            'athlete_id' => $student->id,
        ]);

        $this->actingAs($admin)->post(route('applications.request-documents', $application), [
            'documents' => ['medical'],
            'message' => 'Please submit an updated medical certificate.',
        ])->assertRedirect();

        $this->assertSame('Application update', $student->notifications()->firstOrFail()->data['title']);

        $session = AttendanceSession::create([
            'sport_id' => $sport->id,
            'title' => 'Track session',
            'session_date' => today(),
            'status' => 'Closed',
            'created_by' => $admin->id,
        ]);
        $attendance = Attendance::create([
            'attendance_session_id' => $session->id,
            'user_id' => $student->id,
            'status' => 'Pending',
            'attended_on' => today(),
        ]);

        $this->put(route('admin.attendance.records.update', $attendance), ['status' => 'Present'])
            ->assertRedirect();

        $this->assertSame(2, $student->notifications()->count());
        $this->assertTrue($student->notifications()->get()->contains(fn ($notification) => $notification->data['title'] === 'Attendance updated'));
    }
}