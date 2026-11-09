<?php

namespace Tests\Feature;

use App\Jobs\SendEmailReminderRecipientJob;
use App\Mail\EmailReminderMail;
use App\Models\EmailReminder;
use App\Models\EmailReminderRecipient;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * @return array{0: User, 1: Sport, 2: User, 3: User}
     */
    private function context(): array
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'status' => 'Active',
        ]);

        $sport = Sport::create([
            'name' => 'Basketball',
            'classification' => 'Team Sport',
            'description' => 'Court sport',
            'status' => 'Active',
        ]);

        $athlete = User::factory()->create([
            'role' => 'Student',
            'name' => 'Juan Dela Cruz',
            'student_id' => 'S-2048',
            'sport_id' => $sport->id,
            'status' => 'Active',
            'email' => 'juan@example.com',
        ]);

        $other = User::factory()->create([
            'role' => 'Student',
            'name' => 'Maria Santos',
            'student_id' => 'S-2049',
            'status' => 'Active',
            'email' => 'maria@example.com',
        ]);

        return [$admin, $sport, $athlete, $other];
    }

    public function test_guests_and_students_cannot_access_email_reminders(): void
    {
        [$admin, $sport, $athlete] = $this->context();

        $this->get(route('admin.email-reminders'))->assertRedirect(route('login'));
        $this->actingAs($athlete)->get(route('admin.email-reminders'))->assertForbidden();
        $this->actingAs($athlete)->get(route('admin.email-reminders.create'))->assertForbidden();
        $this->actingAs($athlete)->post(route('admin.email-reminders.store'), [])->assertForbidden();
    }

    public function test_admin_can_view_the_email_reminder_history_page(): void
    {
        [$admin] = $this->context();

        $this->actingAs($admin)
            ->get(route('admin.email-reminders'))
            ->assertOk()
            ->assertSee('Email Reminder History')
            ->assertSee('Compose Reminder');
    }

    public function test_admin_can_queue_a_reminder_for_all_registered_athletes_with_valid_emails(): void
    {
        Queue::fake();
        [$admin, $sport, $athlete, $other] = $this->context();

        $response = $this->actingAs($admin)->post(route('admin.email-reminders.store'), [
            'category' => 'General Reminder',
            'subject' => 'Practice Reminder',
            'message' => 'Please attend practice tomorrow.',
            'recipient_type' => 'all',
            'relevant_date' => '2026-11-12',
            'relevant_time' => '15:00',
        ]);

        $reminder = EmailReminder::firstOrFail();
        $response->assertRedirect(route('admin.email-reminders.show', $reminder));

        $this->assertDatabaseHas('email_reminders', [
            'id' => $reminder->id,
            'admin_id' => $admin->id,
            'subject' => 'Practice Reminder',
            'recipient_type' => 'all',
            'status' => 'queued',
            'recipient_count' => 2,
        ]);

        $this->assertSame(2, EmailReminderRecipient::where('email_reminder_id', $reminder->id)->count());
        Queue::assertPushed(SendEmailReminderRecipientJob::class, 2);
    }

    public function test_admin_can_select_recipients_by_sport_team_and_individual_athletes(): void
    {
        Queue::fake();
        [$admin, $sport, $athlete, $other] = $this->context();
        $secondSport = Sport::create(['name' => 'Volleyball', 'classification' => 'Team Sport', 'description' => 'Net sport', 'status' => 'Active']);
        $volleyballAthlete = User::factory()->create(['role' => 'Student', 'sport_id' => $secondSport->id, 'email' => 'v@example.com', 'status' => 'Active']);

        $this->actingAs($admin)->post(route('admin.email-reminders.store'), [
            'category' => 'Training Schedule Reminder',
            'subject' => 'Basketball Training',
            'message' => 'Training details.',
            'recipient_type' => 'sport',
            'sport_id' => $sport->id,
        ])->assertRedirect();
        $this->assertSame(1, EmailReminder::latest('id')->first()->recipient_count);

        $this->actingAs($admin)->post(route('admin.email-reminders.store'), [
            'category' => 'Sports Event Reminder',
            'subject' => 'Volleyball Team',
            'message' => 'Team details.',
            'recipient_type' => 'team',
            'sport_id' => $secondSport->id,
        ])->assertRedirect();
        $this->assertSame(1, EmailReminder::latest('id')->first()->recipient_count);

        $this->actingAs($admin)->post(route('admin.email-reminders.store'), [
            'category' => 'Competition Reminder',
            'subject' => 'Selected Athletes',
            'message' => 'Selected details.',
            'recipient_type' => 'selected',
            'recipient_ids' => [$athlete->id, $volleyballAthlete->id],
        ])->assertRedirect();
        $this->assertSame(2, EmailReminder::latest('id')->first()->recipient_count);
    }

    public function test_preview_renders_a_personalized_email_without_sending(): void
    {
        Mail::fake();
        [$admin, $sport, $athlete] = $this->context();

        $this->actingAs($admin)
            ->post(route('admin.email-reminders.preview'), [
                'category' => 'Medical Check-up Reminder',
                'subject' => 'Medical Checkup',
                'message' => 'Please report for your checkup.',
                'recipient_type' => 'individual',
                'athlete_id' => $athlete->id,
                'relevant_date' => '2026-11-20',
            ])
            ->assertOk()
            ->assertSee('Medical Checkup')
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Please report for your checkup.')
            ->assertSee('School Administration');

        Mail::assertNothingSent();
    }

    public function test_admin_can_send_a_test_email(): void
    {
        Mail::fake();
        [$admin] = $this->context();

        $this->actingAs($admin)
            ->post(route('admin.email-reminders.test'), ['test_email' => 'test@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(EmailReminderMail::class, fn (EmailReminderMail $mail) => $mail->hasTo('test@example.com'));
    }

    public function test_sending_requires_valid_content_and_matching_recipient_selection(): void
    {
        Queue::fake();
        [$admin] = $this->context();

        $this->actingAs($admin)
            ->from(route('admin.email-reminders.create'))
            ->post(route('admin.email-reminders.store'), [
                'category' => 'General Reminder',
                'recipient_type' => 'all',
            ])
            ->assertRedirect(route('admin.email-reminders.create'))
            ->assertSessionHasErrors(['subject', 'message']);

        $this->assertDatabaseCount('email_reminders', 0);
        Queue::assertNothingPushed();
    }

    public function test_failed_recipients_can_be_retried(): void
    {
        Queue::fake();
        [$admin, $sport, $athlete] = $this->context();

        $reminder = EmailReminder::create([
            'admin_id' => $admin->id,
            'category' => 'General Reminder',
            'subject' => 'Retry Test',
            'message' => 'Body',
            'recipient_type' => 'all',
            'status' => 'partial',
            'recipient_count' => 1,
            'sent_count' => 0,
            'failed_count' => 1,
            'queued_at' => now(),
        ]);

        EmailReminderRecipient::create([
            'email_reminder_id' => $reminder->id,
            'athlete_id' => $athlete->id,
            'email' => $athlete->email,
            'status' => 'failed',
            'error_message' => 'SMTP error',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.email-reminders.retry', $reminder))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('email_reminder_recipients', [
            'email_reminder_id' => $reminder->id,
            'status' => 'pending',
            'error_message' => null,
        ]);
        $this->assertSame('queued', $reminder->fresh()->status);
        Queue::assertPushed(SendEmailReminderRecipientJob::class);
    }
}
