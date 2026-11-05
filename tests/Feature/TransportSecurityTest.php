<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TransportSecurityTest extends TestCase
{
    public function test_session_cookie_defaults_are_configured(): void
    {
        // http_only and same_site are always on; secure follows the environment
        // so a plain-HTTP local install still works.
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertArrayHasKey('secure', config('session'));
    }

    public function test_urls_use_https_when_production_app_url_is_https(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://localhost']);

        // Re-run the provider boot with the production environment in place.
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/student/attendance'));

        URL::forceScheme('http');
    }

    public function test_production_mode_forces_https_even_when_app_url_is_http(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'http://localhost']);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/student/attendance'));

        URL::forceScheme('http');
    }
}
