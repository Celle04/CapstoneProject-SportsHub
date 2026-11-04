<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_and_app_icons_are_ready_for_installation(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('manifest.webmanifest')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Sports Activity Hub', $manifest['name']);
        $this->assertSame('SportsHub', $manifest['short_name']);
        $this->assertSame('Sports Activity Management System for SNNHS.', $manifest['description']);
        $this->assertSame('./', $manifest['start_url']);
        $this->assertSame('./', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#10069F', $manifest['theme_color']);
        $this->assertSame('#F5F6FA', $manifest['background_color']);

        foreach ($manifest['icons'] as $icon) {
            $path = public_path($icon['src']);
            $this->assertFileExists($path);
            $dimensions = getimagesize($path);
            $this->assertNotFalse($dimensions);
            $this->assertSame($icon['sizes'], $dimensions[0].'x'.$dimensions[1]);
        }
    }

    public function test_landing_and_portal_layouts_include_install_controls(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('data-pwa-install', false)
            ->assertSee('Install SportsHub');

        $student = User::factory()->create(['role' => 'Student']);
        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-pwa-install', false)
            ->assertSee('Install App');

        $administrator = User::factory()->create(['role' => 'Administrator']);
        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-pwa-install', false)
            ->assertSee('Install App');
    }
}
