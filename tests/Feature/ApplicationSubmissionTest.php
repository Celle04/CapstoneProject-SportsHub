<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Sport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('private');
        Sport::create(['name' => 'Basketball', 'classification' => 'Team Sport', 'description' => 'Court game']);
    }

    private function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'student_id' => '2026-0001',
            'grade' => 'Grade 11',
            'gender' => 'Male',
            'email' => 'juan@example.com',
            'sport_id' => Sport::firstOrFail()->id,
            'policy_accepted' => '1',
            'medical_certificate' => UploadedFile::fake()->create('medical.pdf', 20, 'application/pdf'),
            'birth_certificate' => UploadedFile::fake()->create('birth.pdf', 20, 'application/pdf'),
            'parent_consent' => UploadedFile::fake()->create('consent.pdf', 20, 'application/pdf'),
        ], $overrides);
    }

    public function test_complete_application_with_required_documents_is_saved(): void
    {
        $response = $this->post(route('application.store'), $this->applicationPayload());

        $response->assertRedirect(route('application.create'));
        $application = Application::firstOrFail();
        $this->assertDatabaseHas('applications', [
            'name' => 'Juan Dela Cruz', 'student_id' => '2026-0001', 'email' => 'juan@example.com',
            'sport' => 'Basketball', 'status' => 'Pending',
            'privacy_accepted' => 1, 'terms_accepted' => 1,
        ]);
        $this->assertNotNull($application->policy_accepted_at);
        $this->assertTrue($application->privacy_accepted);
        $this->assertTrue($application->terms_accepted);
        Storage::disk('private')->assertExists($application->medical_certificate_path);
        Storage::disk('private')->assertExists($application->birth_certificate_path);
        Storage::disk('private')->assertExists($application->parent_consent_path);
    }

    public function test_incomplete_or_invalid_application_is_not_saved(): void
    {
        $response = $this->from(route('application.create'))->post(route('application.store'), $this->applicationPayload([
            'email' => 'not-an-email', 'sport_id' => 999, 'parent_consent' => null,
        ]));

        $response->assertRedirect(route('application.create'));
        $response->assertSessionHasErrors(['email', 'sport_id', 'parent_consent']);
        $this->assertSame(0, Application::count());
    }

    public function test_application_requires_privacy_and_terms_agreement(): void
    {
        $this->from(route('application.create'))
            ->post(route('application.store'), $this->applicationPayload(['policy_accepted' => null]))
            ->assertRedirect(route('application.create'))
            ->assertSessionHasErrors([
                'policy_accepted' => 'You must agree to the Privacy Policy and Terms & Conditions before submitting.',
            ]);

        $this->assertSame(0, Application::count());
    }

    public function test_public_applications_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('application.store'), $this->applicationPayload([
                'student_id' => '2026-'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT),
                'email' => "applicant{$attempt}@example.com",
            ]))->assertRedirect(route('application.create'));
        }

        $this->post(route('application.store'), $this->applicationPayload([
            'student_id' => '2026-9999',
            'email' => 'applicant-last@example.com',
        ]))->assertStatus(429);

        $this->assertSame(5, Application::count());
    }
}
