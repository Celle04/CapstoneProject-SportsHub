<?php

namespace Tests\Feature;

use App\Models\MedicalConversation;
use App\Models\MedicalMessage;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\MedicalFollowUpNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicalFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('private');
    }

    /**
     * @return array{admin: User, athlete: User, otherAthlete: User}
     */
    private function users(): array
    {
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'x', 'status' => 'Active']);
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'Active']);
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $sport->id, 'student_id' => '2026-1001']);
        $otherAthlete = User::factory()->create(['role' => 'Student', 'status' => 'Active', 'sport_id' => $sport->id, 'student_id' => '2026-1002']);

        return compact('admin', 'athlete', 'otherAthlete');
    }

    public function test_admin_can_start_a_medical_follow_up_and_athlete_is_notified(): void
    {
        Notification::fake();
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();

        $response = $this->actingAs($admin)->post(route('admin.medical.follow-ups.store'), [
            'athlete_id' => $athlete->id,
            'category' => 'Medical Check-up Follow-up',
            'subject' => 'Follow up on your check-up',
            'message' => 'Please confirm your check-up schedule.',
        ]);

        $conversation = MedicalConversation::firstOrFail();
        $response->assertRedirect(route('admin.medical.follow-ups.show', $conversation));
        $this->assertSame(MedicalConversation::STATUS_PENDING, $conversation->status);
        $this->assertDatabaseHas('medical_messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
        ]);
        Notification::assertSentTo($athlete, MedicalFollowUpNotification::class);
    }

    public function test_athlete_can_reply_and_status_becomes_responded_without_resolving(): void
    {
        Notification::fake();
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();
        $conversation = MedicalConversation::create([
            'athlete_id' => $athlete->id,
            'created_by' => $admin->id,
            'subject' => 'Missing documents',
            'category' => 'Missing Medical Requirements',
            'status' => MedicalConversation::STATUS_PENDING,
        ]);
        $conversation->messages()->create(['sender_id' => $admin->id, 'message' => 'Please submit your certificate.']);

        $this->actingAs($athlete)
            ->post(route('student.medical-follow-ups.reply', $conversation), ['message' => 'I will submit it today.'])
            ->assertRedirect(route('student.medical-follow-ups.show', $conversation));

        $conversation->refresh();
        $this->assertSame(MedicalConversation::STATUS_RESPONDED, $conversation->status);
        Notification::assertSentTo($admin, MedicalFollowUpNotification::class);
    }

    public function test_admin_can_mark_conversation_resolved_and_athlete_is_notified(): void
    {
        Notification::fake();
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();
        $conversation = MedicalConversation::create([
            'athlete_id' => $athlete->id,
            'created_by' => $admin->id,
            'subject' => 'Clearance',
            'category' => 'Medical Clearance',
            'status' => MedicalConversation::STATUS_RESPONDED,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.medical.follow-ups.status', $conversation), ['status' => MedicalConversation::STATUS_RESOLVED])
            ->assertRedirect();

        $this->assertSame(MedicalConversation::STATUS_RESOLVED, $conversation->fresh()->status);
        Notification::assertSentTo($athlete, MedicalFollowUpNotification::class);
    }

    public function test_unread_messages_are_tracked_per_participant(): void
    {
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();
        $conversation = MedicalConversation::create([
            'athlete_id' => $athlete->id,
            'created_by' => $admin->id,
            'subject' => 'Update',
            'category' => 'Health Assessment Update',
            'status' => MedicalConversation::STATUS_PENDING,
        ]);
        $conversation->messages()->create(['sender_id' => $admin->id, 'message' => 'Please reply.']);

        $this->assertTrue($conversation->fresh()->hasUnreadFor($athlete));
        $this->assertFalse($conversation->fresh()->hasUnreadFor($admin));

        $this->actingAs($athlete)->get(route('student.medical-follow-ups.show', $conversation))->assertOk();
        $this->assertFalse($conversation->fresh()->hasUnreadFor($athlete));

        $this->actingAs($athlete)->post(route('student.medical-follow-ups.reply', $conversation), ['message' => 'Done.']);
        $this->assertTrue($conversation->fresh()->hasUnreadFor($admin));
    }

    public function test_athlete_can_upload_private_attachment_and_admin_can_download_it(): void
    {
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();
        $conversation = MedicalConversation::create([
            'athlete_id' => $athlete->id,
            'created_by' => $admin->id,
            'subject' => 'Certificate',
            'category' => 'Medical Certificate Submission',
            'status' => MedicalConversation::STATUS_PENDING,
        ]);
        $conversation->messages()->create(['sender_id' => $admin->id, 'message' => 'Upload your certificate.']);

        $file = UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf');
        $this->actingAs($athlete)
            ->post(route('student.medical-follow-ups.reply', $conversation), [
                'message' => 'Attached.',
                'attachment' => $file,
            ])->assertRedirect();

        $message = MedicalMessage::whereNotNull('attachment_path')->firstOrFail();
        Storage::disk('private')->assertExists($message->attachment_path);
        $this->assertSame('certificate.pdf', $message->attachment_name);

        $this->actingAs($admin)
            ->get(route('admin.medical.follow-ups.attachments.download', [$conversation, $message]))
            ->assertOk();
    }

    public function test_other_athlete_cannot_access_or_download_someone_elses_conversation(): void
    {
        ['admin' => $admin, 'athlete' => $athlete, 'otherAthlete' => $otherAthlete] = $this->users();
        $conversation = MedicalConversation::create([
            'athlete_id' => $athlete->id,
            'created_by' => $admin->id,
            'subject' => 'Private',
            'category' => 'General Medical Inquiry',
            'status' => MedicalConversation::STATUS_PENDING,
        ]);
        $conversation->messages()->create(['sender_id' => $admin->id, 'message' => 'Confidential.']);

        $this->actingAs($otherAthlete)->get(route('student.medical-follow-ups.show', $conversation))->assertForbidden();
        $this->actingAs($otherAthlete)->post(route('student.medical-follow-ups.reply', $conversation), ['message' => 'x'])->assertForbidden();

        $file = UploadedFile::fake()->create('secret.pdf', 100, 'application/pdf');
        $path = $file->store('medical-follow-up-attachments', 'private');
        $message = $conversation->messages()->create([
            'sender_id' => $athlete->id,
            'message' => 'File',
            'attachment_path' => $path,
            'attachment_name' => 'secret.pdf',
            'attachment_type' => 'application/pdf',
        ]);

        $this->actingAs($otherAthlete)
            ->get(route('student.medical-follow-ups.attachments.download', [$conversation, $message]))
            ->assertForbidden();
    }

    public function test_notifications_do_not_expose_private_medical_message_content(): void
    {
        Notification::fake();
        ['admin' => $admin, 'athlete' => $athlete] = $this->users();

        $this->actingAs($admin)->post(route('admin.medical.follow-ups.store'), [
            'athlete_id' => $athlete->id,
            'category' => 'Medical Clearance',
            'subject' => 'Confidential clearance',
            'message' => 'Diagnosis details that must stay private.',
        ]);

        Notification::assertSentTo($athlete, MedicalFollowUpNotification::class, function (MedicalFollowUpNotification $notification, $channels, $notifiable) {
            $payload = $notification->toDatabase($notifiable)->data;
            $text = json_encode($payload);
            $this->assertStringNotContainsString('Diagnosis details', $text);
            $this->assertStringNotContainsString('must stay private', $text);
            $this->assertArrayHasKey('url', $payload);
            return true;
        });
    }
}
