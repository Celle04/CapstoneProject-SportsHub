<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_is_available_to_guests(): void
    {
        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Information We Collect')
            ->assertSee('Contact Information')
            ->assertDontSee('data-legal-theme-toggle', false)
            ->assertSee('Back to Home');
    }

    public function test_terms_and_conditions_are_available_to_guests(): void
    {
        $this->get(route('terms-and-conditions'))
            ->assertOk()
            ->assertSee('Terms &amp; Conditions', false)
            ->assertSee('Attendance and Participation Records')
            ->assertSee('Back to Home');
    }

    public function test_public_landing_page_links_to_both_policies(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(route('privacy-policy'))
            ->assertSee(route('terms-and-conditions'));
    }

    public function test_application_form_links_to_policies_and_requires_agreement(): void
    {
        $this->get(route('application.create'))
            ->assertOk()
            ->assertSee('<select name="grade" required>', false)
            ->assertSee('value="Grade 7"', false)
            ->assertSee('value="Grade 12"', false)
            ->assertSee('name="policy_accepted"', false)
            ->assertSee('required', false)
            ->assertSee(route('privacy-policy'))
            ->assertSee(route('terms-and-conditions'));
    }
}
