<?php

namespace Tests\Feature;

use App\Models\MedicalFollowUp;
use App\Models\MedicalFollowUpRecipient;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\FollowUpAcknowledgedNotification;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MedicalFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Sport}
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

    /**
     * @return array<string, mixed>
     */
    private function generalPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Training Update',
            'content' => 'Training starts at 3 PM.',
            'category' => 'general',
            'priority' => 'Normal',
            'recipient_type' => 'individual',
            'action' => 'send',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Medical Checkup Reminder',
            'content' => 'Please report to the clinic for your checkup.',
            'category' => 'medical_follow_up',
            'priority' => 'Important',
            'recipient_type' => 'individual',
            'follow_up_type' => 'Medical Checkup Reminder',
            'scheduled_date' => today()->addDays(3)->format('Y-m-d'),
            'scheduled_time' => '08:30',
            'location' => 'School Medical Clinic',
            'additional_instructions' => 'Bring your medical certificate.',
            'response_required' => '1',
            'action' => 'send',
        ], $overrides);
    }

    public function test_admin_can_send_a_general_message_to_one_athlete(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();

        $this->actingAs($admin)
            ->post(route('admin.messages.store'), $this->generalPayload(['athlete_id' => $first->id]))
            ->assertRedirect();

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->assertSame(Message::CATEGORY_GENERAL, $message->category);
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $second->id,
        ]);
        Notification::assertSentTo($first, MessageReceivedNotification::class);
        Notification::assertNotSentTo($second, MessageReceivedNotification::class);
    }

    public function test_admin_can_send_a_general_message_to_multiple_athletes(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->generalPayload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id, $second->id],
        ]))->assertRedirect();

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'recipient_id' => $first->id]);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'recipient_id' => $second->id]);
    }

    public function test_admin_can_send_a_general_message_to_all_active_athletes(): void
    {
        Notification::fake();
        [$admin, $first, $second] = $this->context();
        $inactive = User::factory()->create(['role' => 'Student', 'status' => 'Inactive']);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->generalPayload([
            'recipient_type' => 'all',
        ]))->assertRedirect();

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'recipient_id' => $first->id]);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'recipient_id' => $second->id]);
        $this->assertDatabaseMissing('message_recipients', ['message_id' => $message->id, 'recipient_id' => $inactive->id]);
    }

    public function test_admin_can_send_a_message_by_sport(): void
    {
        Notification::fake();
        [$admin, $first, $second, $sport] = $this->context();
        $otherSport = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'Court sport']);
        $otherAthlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $otherSport->id]);

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->generalPayload([
            'recipient_type' => 'sport',
            'sport_id' => $sport->id,
        ]))->assertRedirect();

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'recipient_id' => $first->id]);
        $this->assertDatabaseMissing('message_recipients', ['message_id' => $message->id, 'recipient_id' => $otherAthlete->id]);
    }

    public function test_admin_can_create_and_send_a_medical_follow_up(): void
    {
        Notification::fake();
        [$admin, $first] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->followUpPayload([
            'athlete_id' => $first->id,
        ]))->assertRedirect();

        $message = Message::query()->where('category', Message::CATEGORY_MEDICAL_FOLLOW_UP)->firstOrFail();
        $this->assertSame('medical_follow_up', $message->message_type);

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame('Medical Checkup Reminder', $followUp->follow_up_type);
        $this->assertSame('School Medical Clinic', $followUp->location);
        $this->assertTrue($followUp->response_required);
        $this->assertSame(MedicalFollowUp::STATUS_SENT, $followUp->status);
        $this->assertSame(today()->addDays(3)->toDateString(), $followUp->scheduled_date->toDateString());

        $this->assertDatabaseHas('medical_follow_up_recipients', [
            'medical_follow_up_id' => $followUp->id,
            'recipient_id' => $first->id,
            'acknowledged_at' => null,
            'completed_at' => null,
        ]);

        Notification::assertSentTo($first, MessageReceivedNotification::class);
    }

    public function test_medical_fields_are_only_required_for_a_medical_follow_up(): void
    {
        [$admin, $first] = $this->context();

        // A general message does not need a schedule.
        $this->actingAs($admin)->post(route('admin.messages.store'), $this->generalPayload([
            'athlete_id' => $first->id,
        ]))->assertRedirect();

        $this->assertDatabaseCount('medical_follow_ups', 0);

        // A medical follow-up needs one.
        $this->actingAs($admin)
            ->from(route('admin.messages.create'))
            ->post(route('admin.messages.store'), $this->followUpPayload([
                'athlete_id' => $first->id,
                'scheduled_date' => '',
                'follow_up_type' => '',
            ]))
            ->assertSessionHasErrors(['follow_up_type', 'scheduled_date']);
    }

    public function test_the_compose_form_only_shows_the_medical_fields_for_a_medical_follow_up(): void
    {
        [$admin] = $this->context();

        $this->actingAs($admin)->get(route('admin.messages.create', ['category' => 'medical_follow_up']))
            ->assertOk()
            ->assertSee('Follow-up Type')
            ->assertSee('Checkup or Follow-up Date')
            ->assertSee('Additional Medical Instructions')
            ->assertSee('Response Required');

        $this->actingAs($admin)->get(route('admin.messages.create', ['category' => 'general']))
            ->assertOk()
            ->assertSee('Message Category');
    }

    public function test_students_receive_the_messages_that_were_sent_to_them(): void
    {
        [$admin, $first, $second] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->followUpPayload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id],
        ]))->assertRedirect();

        $this->actingAs($first)->get(route('student.messages'))
            ->assertOk()
            ->assertSee('Medical Checkup Reminder')
            ->assertSee('Medical Follow-up');

        $this->actingAs($second)->get(route('student.messages'))->assertOk()->assertDontSee('Medical Checkup Reminder');
    }

    public function test_students_cannot_access_the_messages_of_another_athlete(): void
    {
        [$admin, $first, $second] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($second)->get(route('student.messages.show', $message))->assertNotFound();
        $this->actingAs($second)->post(route('student.messages.acknowledge', $message))->assertNotFound();
    }

    public function test_read_status_updates_when_the_athlete_opens_the_message(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($first)->get(route('student.messages.show', $message))->assertOk();

        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'recipient_id' => $first->id,
            'is_read' => true,
        ]);

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $recipient = $followUp->recipients()->firstOrFail();
        $this->assertTrue($recipient->isRead());
        $this->assertFalse($recipient->isAcknowledged());
        $this->assertSame(MedicalFollowUp::STATUS_READ, $followUp->fresh()->status);
    }

    public function test_acknowledgment_requires_an_explicit_student_action(): void
    {
        Notification::fake();
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        // Opening the message is not an acknowledgment.
        $this->actingAs($first)->get(route('student.messages.show', $message))->assertOk();

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertNull($followUp->recipients()->firstOrFail()->acknowledged_at);
        $this->assertNotSame(MedicalFollowUp::STATUS_ACKNOWLEDGED, $followUp->fresh()->status);

        $this->actingAs($first)
            ->post(route('student.messages.acknowledge', $message))
            ->assertRedirect();

        $recipient = $followUp->recipients()->firstOrFail();
        $this->assertNotNull($recipient->acknowledged_at);
        $this->assertTrue($recipient->isAcknowledged());
        $this->assertSame(MedicalFollowUp::STATUS_ACKNOWLEDGED, $followUp->fresh()->status);

        // Acknowledging never completes the follow-up.
        $this->assertNull($recipient->completed_at);
        Notification::assertSentTo($admin, FollowUpAcknowledgedNotification::class);
    }

    public function test_admin_can_monitor_the_acknowledgment_status(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($first)->post(route('student.messages.acknowledge', $message))->assertRedirect();

        $this->actingAs($admin)->get(route('admin.messages.show', $message))
            ->assertOk()
            ->assertSee('Follow-up Recipient Tracking')
            ->assertSee('Acknowledged');

        $this->actingAs($admin)->get(route('admin.messages', ['tab' => 'medical']))
            ->assertOk()
            ->assertSee('Medical Checkup Reminder')
            ->assertSee('Acknowledged');
    }

    public function test_admin_can_mark_a_verified_follow_up_as_completed(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($admin)
            ->patch(route('admin.follow-ups.complete', $message))
            ->assertRedirect();

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame(MedicalFollowUp::STATUS_COMPLETED, $followUp->fresh()->status);
        $this->assertNotNull($followUp->completed_at);
        $this->assertSame($admin->id, $followUp->completed_by);
        $this->assertTrue($followUp->recipients()->firstOrFail()->isCompleted());
    }

    public function test_an_athlete_cannot_complete_a_follow_up(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($first)
            ->patch(route('admin.follow-ups.complete', $message))
            ->assertForbidden();

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertNotSame(MedicalFollowUp::STATUS_COMPLETED, $followUp->fresh()->status);
    }

    public function test_overdue_status_is_calculated_from_the_scheduled_date(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
            'scheduled_date' => today()->subDay()->format('Y-m-d'),
        ]));

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame(MedicalFollowUp::STATUS_OVERDUE, $followUp->effectiveStatus());

        $this->actingAs($admin)->get(route('admin.messages', ['tab' => 'medical']))
            ->assertOk()
            ->assertSee('Overdue');
    }

    public function test_the_notification_bell_shows_the_right_unread_counts(): void
    {
        [$admin, $first, $second] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->generalPayload([
            'recipient_type' => 'selected',
            'recipient_ids' => [$first->id, $second->id],
        ]))->assertRedirect();

        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(1, $first->unreadNotifications()->count());

        $message = Message::query()->where('subject', 'Training Update')->firstOrFail();
        $this->actingAs($first)->get(route('student.messages.show', $message))->assertOk();

        $this->assertSame(0, $first->unreadNotifications()->count());
    }

    public function test_a_follow_up_can_be_cancelled_by_the_sports_office(): void
    {
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($admin)->patch(route('admin.follow-ups.cancel', $message))->assertRedirect();

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame(MedicalFollowUp::STATUS_CANCELLED, $followUp->fresh()->status);

        $this->actingAs($first)->post(route('student.messages.acknowledge', $message))->assertStatus(422);
    }

    public function test_a_draft_follow_up_is_not_delivered_until_it_is_sent(): void
    {
        Notification::fake();
        [$admin, $first] = $this->context();

        $this->actingAs($admin)->post(route('admin.messages.store'), $this->followUpPayload([
            'athlete_id' => $first->id,
            'action' => 'draft',
        ]))->assertRedirect();

        $draft = Message::query()->where('status', 'draft')->firstOrFail();
        $this->assertDatabaseCount('medical_follow_up_recipients', 0);
        $followUp = MedicalFollowUp::query()->where('message_id', $draft->id)->firstOrFail();
        $this->assertSame(MedicalFollowUp::STATUS_SCHEDULED, $followUp->status);
        Notification::assertNothingSent();

        $this->actingAs($admin)->put(route('admin.messages.update', $draft), $this->followUpPayload([
            'athlete_id' => $first->id,
            'action' => 'send',
        ]))->assertRedirect(route('admin.messages.show', $draft));

        $this->assertDatabaseHas('medical_follow_up_recipients', ['recipient_id' => $first->id]);
        Notification::assertSentTo($first, MessageReceivedNotification::class);
    }

    public function test_an_athlete_can_reply_to_a_follow_up(): void
    {
        Notification::fake();
        [$admin, $first] = $this->context();
        $message = app(\App\Services\MessageDeliveryService::class)->send($admin, $this->followUpPayload([
            'recipient_type' => 'individual',
            'athlete_id' => $first->id,
        ]));

        $this->actingAs($first)
            ->post(route('student.messages.reply', $message), ['content' => 'I will submit it today.'])
            ->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'sender_id' => $first->id,
            'reply_to_message_id' => $message->id,
            'content' => 'I will submit it today.',
        ]);

        $followUp = MedicalFollowUp::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertTrue($followUp->recipients()->firstOrFail()->isAcknowledged());
    }

    public function test_the_old_follow_up_links_point_to_the_unified_module(): void
    {
        [$admin, $first] = $this->context();

        $this->actingAs($admin)->get(route('admin.medical.follow-ups'))
            ->assertRedirect(route('admin.messages', ['tab' => 'medical']));

        $this->actingAs($first)->get(route('student.medical-follow-ups'))
            ->assertRedirect(route('student.messages', ['tab' => 'medical']));
    }

    public function test_the_navigation_shows_one_entry_for_messages_and_follow_ups(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active']);

        $adminLabels = array_column(\App\Support\PortalNavigation::admin(), 'label');
        $studentLabels = array_column(\App\Support\PortalNavigation::student(), 'label');

        $this->assertContains('Messages & Follow-ups', $adminLabels);
        $this->assertContains('Messages & Follow-ups', $studentLabels);
        $this->assertNotContains('Medical Follow-ups', $adminLabels);
        $this->assertNotContains('Medical Follow-ups', $studentLabels);
        $this->assertCount(1, array_keys($adminLabels, 'Messages & Follow-ups'));
    }
}
