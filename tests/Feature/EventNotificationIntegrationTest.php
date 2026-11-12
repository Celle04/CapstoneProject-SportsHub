<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Message;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EventNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * @return array{0: User, 1: Sport, 2: User}
     */
    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'Active']);
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'Court sport', 'status' => 'Active']);
        $athlete = User::factory()->create([
            'role' => 'Student',
            'name' => 'Athlete One',
            'email' => 'athlete@example.com',
            'sport_id' => $sport->id,
            'status' => 'Active',
        ]);

        return [$admin, $sport, $athlete];
    }

    public function test_creating_an_event_can_redirect_to_a_prefilled_notification_composer(): void
    {
        [$admin, $sport] = $this->context();

        $response = $this->actingAs($admin)->post(route('events.store'), [
            'title' => 'Intramurals Opening',
            'sport_id' => $sport->id,
            'event_type' => 'Competition',
            'starts_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d\\TH:i'),
            'venue' => 'Main Court',
            'status' => 'Scheduled',
            'send_event_notification' => '1',
        ]);

        $event = Event::firstOrFail();
        $response->assertRedirect();
        $this->assertStringContainsString('admin/messages/compose', $response->headers->get('Location'));
        $this->assertStringContainsString('event_id='.$event->id, $response->headers->get('Location'));
    }

    public function test_event_details_page_offers_send_notification_and_cancel_preserves_history(): void
    {
        Notification::fake();
        [$admin, $sport, $athlete] = $this->context();
        $event = Event::create([
            'title' => 'Practice Game',
            'sport_id' => $sport->id,
            'event_type' => 'Friendly Match',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
            'venue' => 'Gym',
            'status' => 'Scheduled',
        ]);

        $this->actingAs($admin)->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Send Notification')
            ->assertSee('Cancel Event')
            ->assertSee('Event Notifications');

        $this->post(route('events.cancel', $event), ['notify_athletes' => '1'])
            ->assertRedirect(route('events.show', $event));

        $event->refresh();
        $this->assertSame('Cancelled', $event->status);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->assertDatabaseHas('messages', ['event_id' => $event->id, 'status' => 'sent']);
        Notification::assertSentTo($athlete, MessageReceivedNotification::class);
    }

    public function test_sending_an_event_notification_links_the_message_and_athlete_can_view_event_details(): void
    {
        Notification::fake();
        [$admin, $sport, $athlete] = $this->context();
        $event = Event::create([
            'title' => 'Championship',
            'sport_id' => $sport->id,
            'event_type' => 'Competition',
            'starts_at' => now()->addDays(3)->setTime(9, 0),
            'ends_at' => now()->addDays(3)->setTime(12, 0),
            'venue' => 'Stadium',
            'status' => 'Scheduled',
            'description' => 'Bring your ID.',
        ]);

        $this->actingAs($admin)->post(route('admin.messages.store'), [
            'action' => 'send',
            'event_id' => $event->id,
            'subject' => 'Championship reminder',
            'content' => 'See you at the venue.',
            'category' => 'general',
            'message_type' => 'competition',
            'priority' => 'Important',
            'recipient_type' => 'sport',
            'sport_id' => $sport->id,
        ])->assertRedirect();

        $message = Message::where('event_id', $event->id)->firstOrFail();
        $this->assertSame('competition', $message->message_type);

        Notification::assertSentTo($athlete, MessageReceivedNotification::class, function (MessageReceivedNotification $notification) use ($event, $athlete) {
            $data = $notification->toDatabase($athlete)->data;
            return ($data['event_id'] ?? null) === $event->id
                && str_contains((string) ($data['message'] ?? ''), 'Championship')
                && str_contains((string) ($data['url'] ?? ''), '/student/events/'.$event->id);
        });

        $this->actingAs($athlete)
            ->get(route('student.events.show', $event))
            ->assertOk()
            ->assertSee('Championship')
            ->assertSee('Stadium');

        $this->actingAs($athlete)
            ->get(route('student.messages.show', $message))
            ->assertOk()
            ->assertSee('View Event Details')
            ->assertSee('Championship');
    }

    public function test_updating_an_event_with_notify_option_sends_an_event_update_message(): void
    {
        Notification::fake();
        [$admin, $sport, $athlete] = $this->context();
        $event = Event::create([
            'title' => 'Training',
            'sport_id' => $sport->id,
            'event_type' => 'Training',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'venue' => 'Court A',
            'status' => 'Scheduled',
        ]);

        $this->actingAs($admin)->put(route('events.update', $event), [
            'title' => 'Training',
            'sport_id' => $sport->id,
            'event_type' => 'Training',
            'starts_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d\\TH:i'),
            'venue' => 'Court B',
            'status' => 'Scheduled',
            'notify_changes' => '1',
        ])->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('messages', [
            'event_id' => $event->id,
            'message_type' => 'event_update',
            'status' => 'sent',
        ]);
        Notification::assertSentTo($athlete, MessageReceivedNotification::class);
    }

    public function test_students_cannot_view_unrelated_events(): void
    {
        [$admin, $sport, $athlete] = $this->context();
        $otherSport = Sport::create(['name' => 'Chess', 'classification' => 'Individual', 'description' => 'Board game', 'status' => 'Active']);
        $event = Event::create([
            'title' => 'Chess Meet',
            'sport_id' => $otherSport->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'venue' => 'Library',
            'status' => 'Scheduled',
        ]);

        $this->actingAs($athlete)->get(route('student.events.show', $event))->assertNotFound();
    }
}
