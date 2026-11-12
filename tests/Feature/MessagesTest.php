<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\MedicalRecord;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * @return array{User, User, User, Sport}
     */
    private function context(): array
    {
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'Court sport']);
        $admin = User::factory()->create(['role' => 'Administrator', 'name' => 'Sports Admin']);
        $first = User::factory()->create([
            'role' => 'Student',
            'status' => 'Active',
            'name' => 'Juan Dela Cruz',
            'student_id' => 'S-1001',
            'sport_id' => $sport->id,
        ]);
        $second = User::factory()->create([
            'role' => 'Student',
            'status' => 'Active',
            'name' => 'Maria Santos',
            'student_id' => 'S-1002',
            'sport_id' => $sport->id,
        ]);

        return [$admin, $first, $second, $sport];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Training Update',
            'content' => 'Training starts at 3 PM.',
            'category' => 'general',
            'message_type' => 'general',
            'priority' => 'Normal',
            'recipient_type' => 'individual',
        ], $overrides);
    }

    public function test_the_event_scope_of_the_message_list_survives_the_search(): void
    {
        [$admin, $first] = $this->context();
        $event = Event::create([
            'title' => 'Basketball Finals',
            'venue' => 'Main Court',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'Scheduled',
        ]);

        $scoped = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
            'event_id' => $event->id,
            'subject' => 'Finals schedule',
        ]));

        app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
            'subject' => 'Unrelated notice',
        ]));

        $response = $this->actingAs($admin)->get(route('admin.messages', ['event_id' => $event->id]));

        $response->assertOk()->assertSee('Finals schedule')->assertDontSee('Unrelated notice');

        // The search keeps the event in view.
        $response->assertSee('<input type="hidden" name="event_id" value="'.$event->id.'">', false);

        // The tabs keep the event in view as well.
        $response->assertSee(e(route('admin.messages', ['event_id' => $event->id, 'tab' => 'general'])), false);
    }

    public function test_admin_can_send_a_message_to_an_individual_athlete(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();

        $this->actingAs($admin)
            ->post(route('admin.messages.store'), $this->payload([
                'athlete_id' => $first->id,
                'action' => 'send',
            ]))
            ->assertRedirect();

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $first->id,
            'is_read' => false,
        ]);
        $this->assertDatabaseMissing('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $second->id,
        ]);
        Notification::assertSentTo($first, MessageReceivedNotification::class);
        Notification::assertNotSentTo($second, MessageReceivedNotification::class);
    }

    public function test_admin_can_send_to_selected_athletes_and_all_active_athletes(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();
        $inactive = User::factory()->create(['role' => 'Student', 'status' => 'Inactive']);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->payload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id, $second->id],
            'action' => 'send',
        ]))->assertRedirect();

        $this->assertDatabaseCount('message_recipients', 2);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->payload([
            'subject' => 'All athletes',
            'recipient_type' => 'all',
            'action' => 'send',
        ]))->assertRedirect();

        $this->assertDatabaseCount('message_recipients', 4);
        $this->assertDatabaseMissing('message_recipients', ['recipient_id' => $inactive->id]);
    }

    public function test_admin_can_send_to_active_athletes_by_sport(): void
    {
        Notification::fake();
        [$admin, $first, $second, $sport] = $this->context();
        $otherSport = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'Court sport']);
        $otherAthlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $otherSport->id]);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->payload([
            'recipient_type' => 'sport',
            'sport_id' => $sport->id,
            'action' => 'send',
        ]))->assertRedirect();

        $this->assertDatabaseHas('message_recipients', ['recipient_id' => $first->id]);
        $this->assertDatabaseHas('message_recipients', ['recipient_id' => $second->id]);
        $this->assertDatabaseMissing('message_recipients', ['recipient_id' => $otherAthlete->id]);
    }

    public function test_drafts_do_not_create_recipient_rows_or_notifications_and_can_be_sent_later(): void
    {
        Notification::fake();
        [$admin, $first] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->payload([
            'athlete_id' => $first->id,
            'action' => 'draft',
        ]))->assertRedirect();

        $draft = Message::query()->where('status', 'draft')->firstOrFail();
        $this->assertDatabaseCount('message_recipients', 0);
        Notification::assertNothingSent();

        $this->actingAs($admin)->put(route('admin.messages.update', $draft), $this->payload([
            'athlete_id' => $first->id,
            'action' => 'send',
        ]))->assertRedirect(route('admin.messages.show', $draft));

        $this->assertDatabaseHas('messages', ['id' => $draft->id, 'status' => 'sent']);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $draft->id, 'recipient_id' => $first->id]);
        Notification::assertSentTo($first, MessageReceivedNotification::class);
    }

    public function test_students_only_see_their_own_messages_and_opening_one_marks_it_read(): void
    {
        [$admin, $first, $second] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id],
        ]));

        $this->actingAs($second)->get(route('student.messages'))->assertOk()->assertDontSee('Training Update');
        $this->actingAs($second)->get(route('student.messages.show', $message))->assertNotFound();
        $this->actingAs($second)->post(route('student.messages.reply', $message), [
            'content' => 'This message was not addressed to me.',
        ])->assertNotFound();

        $this->actingAs($first)->get(route('student.messages.show', $message))
            ->assertOk()
            ->assertSee('Training Update')
            ->assertSee('Training starts at 3 PM.');

        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $first->id,
            'is_read' => true,
        ]);
        $this->assertSame(0, $first->fresh()->unreadNotifications()->count());
    }

    public function test_student_can_reply_to_a_general_message_and_notify_the_sports_office(): void
    {
        [$admin, $first, $second] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id, $second->id],
        ]));

        $this->actingAs($first)
            ->post(route('student.messages.reply', $message), ['content' => 'Can I confirm the practice time?'])
            ->assertRedirect(route('student.messages.show', $message))
            ->assertSessionHas('success', 'Reply sent to the sports office.');

        $reply = Message::query()->where('reply_to_message_id', $message->id)->firstOrFail();
        $this->assertSame($first->id, $reply->sender_id);
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $reply->id,
            'recipient_id' => $admin->id,
        ]);
        $this->assertDatabaseMissing('message_recipients', [
            'message_id' => $reply->id,
            'recipient_id' => $second->id,
        ]);

        $notification = $admin->notifications()
            ->where('type', MessageReceivedNotification::class)
            ->latest()
            ->firstOrFail();
        $this->assertSame(route('admin.messages.show', $message), $notification->data['url']);

        $this->actingAs($admin)
            ->get(route('admin.messages.show', $message))
            ->assertOk()
            ->assertSee('Can I confirm the practice time?')
            ->assertSee('Reply to an athlete');
    }

    public function test_admin_can_reply_privately_to_one_recipient_of_a_general_message(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id, $second->id],
        ]));

        $this->actingAs($admin)
            ->post(route('admin.messages.reply', $message), [
                'recipient_id' => $first->id,
                'content' => 'Practice starts at 3:30 PM.',
            ])
            ->assertRedirect(route('admin.messages.show', $message))
            ->assertSessionHas('success', 'Reply sent to '.$first->name.'.');

        $reply = Message::query()->where('reply_to_message_id', $message->id)->firstOrFail();
        $this->assertSame($admin->id, $reply->sender_id);
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $reply->id,
            'recipient_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('message_recipients', [
            'message_id' => $reply->id,
            'recipient_id' => $second->id,
        ]);
        Notification::assertSentTo($first, MessageReceivedNotification::class);

        $this->actingAs($first)
            ->get(route('student.messages.show', $reply))
            ->assertOk()
            ->assertSee('Training starts at 3 PM.')
            ->assertSee('Practice starts at 3:30 PM.');

        $this->actingAs($second)
            ->get(route('student.messages.show', $reply))
            ->assertNotFound();

        $this->actingAs($second)
            ->get(route('student.messages.show', $message))
            ->assertOk()
            ->assertDontSee('Practice starts at 3:30 PM.');
    }

    public function test_admin_cannot_reply_to_a_student_who_did_not_receive_the_general_message(): void
    {
        [$admin, $first, $second] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($admin)
            ->from(route('admin.messages.show', $message))
            ->post(route('admin.messages.reply', $message), [
                'recipient_id' => $second->id,
                'content' => 'This should not be delivered.',
            ])
            ->assertSessionHasErrors('recipient_id');

        $this->assertDatabaseMissing('messages', [
            'reply_to_message_id' => $message->id,
            'content' => 'This should not be delivered.',
        ]);
    }

    public function test_medical_reminder_is_delivered_as_an_in_app_message_with_schedule_details(): void
    {
        [$admin, $first] = $this->context();
        $checkupDate = today()->addDays(3);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->payload([
            'subject' => 'Medical Checkup Reminder',
            'content' => 'Bring your medical documents.',
            'category' => 'medical_follow_up',
            'priority' => 'Important',
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
            'follow_up_type' => 'Medical Checkup Reminder',
            'scheduled_date' => $checkupDate->format('Y-m-d'),
            'scheduled_time' => '08:30',
            'location' => 'School Medical Clinic',
            'action' => 'send',
        ]))->assertRedirect();

        $message = Message::query()->where('category', 'medical_follow_up')->firstOrFail();
        $followUp = \App\Models\MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame('School Medical Clinic', $followUp->location);
        $this->assertSame('08:30', substr((string) $followUp->scheduled_time, 0, 5));
        $notification = $first->notifications()->firstOrFail();
        $this->assertSame(MessageReceivedNotification::class, $notification->type);
        $this->assertStringContainsString($checkupDate->format('F j, Y'), data_get($notification->data, 'message'));
        $this->assertStringContainsString('8:30 AM', data_get($notification->data, 'message'));
        $this->assertStringContainsString('School Medical Clinic', data_get($notification->data, 'message'));

        $this->actingAs($first)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Upcoming medical checkups')
            ->assertSee('Medical Checkup Reminder');
    }

    public function test_admin_routes_are_forbidden_to_students_and_students_cannot_send_messages(): void
    {
        [$admin, $first] = $this->context();

        $this->actingAs($first)->get(route('admin.messages'))->assertForbidden();
        $this->actingAs($first)->post(route('admin.messages.store'), $this->payload(['action' => 'send']))->assertForbidden();
        // The sports office follows the same module from the other side, so an
        // athlete address is sent to the administrator module.
        $this->actingAs($admin)->get(route('student.messages'))->assertRedirect(route('admin.messages'));
    }

    public function test_admin_message_dashboard_uses_real_recipient_read_counts(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id],
        ]));
        MessageRecipient::markReadFor($message->id, $first->id);

        $this->actingAs($admin)->get(route('admin.messages'))
            ->assertOk()
            ->assertSee('Total Messages Sent')
            ->assertSee('General Messages')
            ->assertSee('Medical Follow-ups')
            ->assertSee('Training Update');
    }

    public function test_admin_can_render_the_compose_and_sent_message_details_pages(): void
    {
        [$admin, $first] = $this->context();

        $this->actingAs($admin)->get(route('admin.messages.create'))
            ->assertOk()
            ->assertSee('Compose Message')
            ->assertSee('Find athlete by name or Student ID')
            ->assertSee('Individually selected athletes');

        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($admin)->get(route('admin.messages.show', $message))
            ->assertOk()
            ->assertSee('Recipient Read Tracking')
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Unread');
    }

    public function test_the_confirmation_of_an_action_is_shown_once(): void
    {
        [$admin, $first] = $this->context();

        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($admin)
            ->from(route('admin.messages.show', $message))
            ->post(route('admin.messages.reply', $message), [
                'recipient_id' => $first->id,
                'content' => 'Noted, thank you.',
            ])
            ->assertRedirect(route('admin.messages.show', $message));

        $page = $this->actingAs($admin)->get(route('admin.messages.show', $message));

        $page->assertOk();

        // The confirmation is written once, not once per layer of the page.
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'Reply sent to Juan Dela Cruz.'),
            'The confirmation is shown more than once.'
        );
    }

    public function test_invalid_compose_submission_returns_to_a_renderable_form_with_validation_errors(): void
    {
        [$admin] = $this->context();

        $this->from(route('admin.messages.create'))
            ->actingAs($admin)
            ->post(route('admin.messages.store'), [
                'category' => 'general',
                'message_type' => 'general',
                'priority' => 'Normal',
                'recipient_type' => 'individual',
                'action' => 'send',
            ])
            ->assertRedirect(route('admin.messages.create'))
            ->assertSessionHasErrors(['subject', 'content', 'athlete_id']);

        $this->actingAs($admin)
            ->get(route('admin.messages.create'))
            ->assertOk()
            ->assertSee('Compose Message')
            ->assertSee('The subject field is required.');
    }

    public function test_marking_a_message_notification_read_updates_recipient_read_tracking(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->payload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));
        $notification = $first->unreadNotifications()->firstOrFail();

        $this->actingAs($first)
            ->post(route('notifications.read', $notification->id))
            ->assertRedirect();

        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $first->id,
            'is_read' => true,
        ]);
    }

    public function test_student_dashboard_notifies_once_when_clearance_is_approaching_expiration(): void
    {
        [, $student, $expiredStudent] = $this->context();
        MedicalRecord::create([
            'athlete_id' => $student->id,
            'examination_date' => today()->subDays(30),
            'medical_status' => 'Cleared',
            'expiration_date' => today()->addDays(10),
            'findings' => 'Recorded',
        ]);
        MedicalRecord::create([
            'athlete_id' => $expiredStudent->id,
            'examination_date' => today()->subDays(40),
            'medical_status' => 'Cleared',
            'expiration_date' => today()->subDays(2),
            'findings' => 'Recorded',
        ]);

        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();
        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();
        $this->actingAs($expiredStudent)->get(route('student.dashboard'))->assertOk();
        $this->actingAs($expiredStudent)->get(route('student.dashboard'))->assertOk();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
        ]);
        $notification = $student->notifications()->firstOrFail();
        $this->assertSame('Medical clearance expiring soon', data_get($notification->data, 'title'));
        $expiredNotification = $expiredStudent->notifications()->firstOrFail();
        $this->assertSame('Medical clearance expired', data_get($expiredNotification->data, 'title'));
    }
}
