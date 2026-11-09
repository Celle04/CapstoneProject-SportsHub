<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationStatusTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('private');
    }

    public function test_submission_generates_reference_number_and_confirmation(): void
    {
        $sport = Sport::create(['name' => 'Basketball', 'classification' => 'Team', 'description' => 'Court sport']);

        $response = $this->post(route('application.store'), [
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'student_id' => '2026-0001',
            'grade' => 'Grade 11',
            'gender' => 'Male',
            'email' => 'juan@example.com',
            'sport_id' => $sport->id,
            'policy_accepted' => '1',
            'medical_certificate' => UploadedFile::fake()->create('medical.pdf', 20, 'application/pdf'),
            'birth_certificate' => UploadedFile::fake()->create('birth.pdf', 20, 'application/pdf'),
            'parent_consent' => UploadedFile::fake()->create('consent.pdf', 20, 'application/pdf'),
        ]);

        $response->assertRedirect(route('application.create'));
        $response->assertSessionHas('submitted', true);
        $response->assertSessionHas('application_reference');

        $application = Application::firstOrFail();
        $this->assertNotEmpty($application->reference_number);
        $this->assertSame($application->reference_number, session('application_reference'));
        $this->assertDatabaseHas('application_status_histories', [
            'application_id' => $application->id,
            'status' => 'Pending',
        ]);

        $this->get(route('application.create'))
            ->assertOk()
            ->assertSee('Your application reference number')
            ->assertSee('Copy reference number')
            ->assertSee($application->reference_number);
    }

    public function test_public_status_check_requires_reference_and_matching_email(): void
    {
        $application = $this->makeApplication(['email' => 'applicant@example.com']);

        $this->get(route('application.status'))->assertOk()->assertSee('Check Application Status');

        $this->post(route('application.status.check'), [
            'reference_number' => $application->reference_number,
            'email' => 'wrong@example.com',
        ])->assertOk()->assertViewHas('notFound', true)->assertDontSee($application->name);

        $this->post(route('application.status.check'), [
            'reference_number' => $application->reference_number,
            'email' => 'applicant@example.com',
        ])->assertOk()
            ->assertViewHas('result')
            ->assertSee($application->status)
            ->assertSee($application->reference_number)
            ->assertDontSee('Student ID')
            ->assertDontSee($application->student_id ?? '___never___');
    }

    public function test_admin_can_mark_under_review_approve_reject_and_request_revision_with_history(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $application = $this->makeApplication(['status' => 'Pending']);

        $this->actingAs($admin)->post(route('applications.under-review', $application), [
            'review_notes' => 'Checking documents.',
            'admin_instructions' => 'Please wait for review.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Review', $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertDatabaseHas('application_status_histories', ['application_id' => $application->id, 'status' => 'Under Review', 'changed_by' => $admin->id]);

        $this->actingAs($admin)->post(route('applications.request-documents', $application), [
            'documents' => ['medical'],
            'message' => 'Please submit a clearer medical certificate.',
            'admin_instructions' => 'Visit the SNNHS Sports Office if needed.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Requires Revision', $application->status);
        $this->assertSame('Please submit a clearer medical certificate.', $application->review_notes);
        $this->assertSame('Visit the SNNHS Sports Office if needed.', $application->admin_instructions);
        $this->assertSame(['medical'], $application->documents_requested);

        $application->update([
            'medical_document_status' => 'Verified',
            'birth_document_status' => 'Verified',
            'consent_document_status' => 'Verified',
        ]);

        $this->actingAs($admin)->post(route('applications.approve', $application), [
            'review_notes' => 'Approved.',
            'admin_instructions' => 'Please visit the SNNHS Sports Office for further instructions.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Approved', $application->status);
        $this->assertDatabaseHas('application_status_histories', ['application_id' => $application->id, 'status' => 'Approved']);

        $rejected = $this->makeApplication(['status' => 'Pending']);
        $rejected->update([
            'medical_document_status' => 'Verified',
            'birth_document_status' => 'Verified',
            'consent_document_status' => 'Verified',
        ]);

        $this->actingAs($admin)->post(route('applications.reject', $rejected), [
            'rejection_reason' => 'Eligibility requirements not met',
            'admin_instructions' => 'You may ask the sports office for guidance.',
        ])->assertRedirect();

        $rejected->refresh();
        $this->assertSame('Rejected', $rejected->status);
        $this->assertSame('Eligibility requirements not met', $rejected->rejection_reason);
    }

    public function test_rejection_and_revision_require_explanations(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $application = $this->makeApplication(['status' => 'Pending']);

        $this->actingAs($admin)->post(route('applications.reject', $application), [
            'review_notes' => 'No reason',
        ])->assertSessionHasErrors('rejection_reason');

        $this->actingAs($admin)->post(route('applications.request-documents', $application), [
            'documents' => ['birth'],
        ])->assertSessionHasErrors('message');

        $this->actingAs($admin)->patch(route('applications.update', $application), [
            'status' => 'Requires Revision',
            'admin_instructions' => 'Office visit required',
        ])->assertSessionHasErrors('review_notes');
    }

    public function test_linked_student_receives_in_app_notification_for_status_changes(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $student = User::factory()->create(['role' => 'Student', 'student_id' => '2026-7777', 'email' => 'student@example.com']);
        $application = $this->makeApplication([
            'student_id' => $student->student_id,
            'email' => $student->email,
            'status' => 'Pending',
            'athlete_id' => $student->id,
        ]);

        $this->actingAs($admin)->post(route('applications.under-review', $application))->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $student->id,
            'type' => 'App\\Notifications\\StudentUpdateNotification',
        ]);

        $notification = $student->fresh()->notifications()->latest()->first();
        $this->assertStringContainsString('under review', strtolower((string) ($notification->data['message'] ?? '')));
        $this->assertSame(route('student.application'), $notification->data['url'] ?? null);
        $this->assertStringNotContainsString('medical', strtolower(json_encode($notification->data)));
        $this->assertStringNotContainsString('birth', strtolower(json_encode($notification->data)));
    }

    public function test_approved_application_can_only_create_one_athlete_account_flow(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $application = $this->makeApplication(['status' => 'Approved']);

        $this->actingAs($admin)->post(route('athletes.store'), [
            'application_id' => $application->id,
            'username' => 'athlete.one',
            'password' => 'TemporaryPass1',
            'password_confirmation' => 'TemporaryPass1',
        ])->assertRedirect(route('athletes.index'));

        $this->actingAs($admin)->post(route('athletes.store'), [
            'application_id' => $application->id,
            'username' => 'athlete.two',
            'password' => 'TemporaryPass1',
            'password_confirmation' => 'TemporaryPass1',
        ])->assertSessionHasErrors();

        $this->assertSame(1, User::where('username', 'athlete.one')->count());
        $this->assertDatabaseMissing('users', ['username' => 'athlete.two']);
    }

    private function makeApplication(array $overrides = []): Application
    {
        $sport = Sport::firstOrCreate(['name' => 'Basketball'], ['classification' => 'Team', 'description' => 'Court sport']);

        return Application::create(array_merge([
            'name' => 'Ana Santos',
            'student_id' => '2026-1001',
            'grade' => 'Grade 11',
            'gender' => 'Female',
            'email' => 'ana@example.com',
            'sport' => 'Basketball',
            'sport_id' => $sport->id,
            'status' => 'Pending',
            'medical_certificate_path' => 'medical.pdf',
            'birth_certificate_path' => 'birth.pdf',
            'parent_consent_path' => 'consent.pdf',
            'medical_document_status' => 'Verified',
            'birth_document_status' => 'Verified',
            'consent_document_status' => 'Verified',
        ], $overrides));
    }
}
