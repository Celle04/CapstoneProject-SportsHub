<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'force.password.change' => \App\Http\Middleware\ForcePasswordChangeMiddleware::class,
            'administrator' => \App\Http\Middleware\EnsureAdministrator::class,
        ]);

        /*
         * Enforce the mandatory password change on every authenticated web
         * request, not only on the student portal route group. Without this a
         * forced-change athlete could keep using the one-time temporary
         * password on any route registered outside the group.
         */
        $middleware->appendToGroup('web', \App\Http\Middleware\ForcePasswordChangeMiddleware::class);

        // Security headers on every web response (CSP is report-only for now).
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeadersMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
