<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'Administrator',
            'status' => 'Active',
        ]);
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => 'Student',
            'status' => 'Active',
        ]);
    }

    public function test_student_cannot_access_admin_pages(): void
    {
        $student = $this->student();

        $routes = [
            ['GET', '/dashboard'],
            ['GET', '/admin/calendar'],
            ['GET', '/applications'],
            ['GET', '/sports'],
            ['GET', '/athletes'],
            ['GET', '/coaches'],
            ['GET', '/events'],
            ['GET', '/admin/medical'],
            ['GET', '/admin/messages'],
            ['GET', '/admin/achievements'],
            ['GET', '/admin/certificate-requests'],
            ['GET', '/admin/attendance'],
            ['GET', '/reports'],
            ['GET', '/reports/export?report=athletes'],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->actingAs($student)
                ->call($method, $uri)
                ->assertForbidden();
        }
    }

    public function test_student_cannot_perform_admin_actions(): void
    {
        $student = $this->student();

        $actions = [
            ['POST', '/admin/messages'],
            ['POST', '/admin/attendance/sessions'],
            ['POST', '/admin/medical/incidents'],
        ];

        foreach ($actions as [$method, $uri]) {
            $response = $this->actingAs($student)->call($method, $uri);
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri} should be forbidden for students");
        }
    }

    public function test_student_cannot_access_admin_resource_routes_with_existing_records(): void
    {
        $student = $this->student();
        $athlete = User::factory()->create(['role' => 'Student', 'status' => 'Active']);

        $this->actingAs($student)->post("/athletes/{$athlete->id}/password-reset")->assertForbidden();
        $this->actingAs($student)->get("/athletes/{$athlete->id}/edit")->assertForbidden();
    }

    public function test_administrator_can_access_admin_pages(): void
    {
        $admin = $this->admin();

        $routes = [
            '/dashboard',
            '/admin/calendar',
            '/applications',
            '/sports',
            '/athletes',
            '/coaches',
            '/events',
            '/admin/medical',
            '/admin/messages',
            '/admin/achievements',
            '/admin/certificate-requests',
            '/admin/attendance',
            '/reports',
        ];

        foreach ($routes as $uri) {
            $response = $this->actingAs($admin)->get($uri);
            $this->assertNotSame(403, $response->getStatusCode(), "Administrator was forbidden from {$uri}");
        }
    }

    public function test_guest_is_redirected_to_login_for_admin_pages(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/admin/messages')->assertRedirect(route('login'));
    }
}
