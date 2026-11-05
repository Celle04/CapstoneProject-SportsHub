<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_forwarded_https_and_client_ip_are_trusted(): void
    {
        Route::get('/proxy-check', fn () => response()->json([
            'ip' => request()->ip(),
            'url' => URL::to('/'),
        ]));

        $this->get('/proxy-check', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.42',
        ])->assertOk()
            ->assertJsonPath('ip', '203.0.113.42')
            ->assertJsonPath('url', 'https://localhost');
    }
}
