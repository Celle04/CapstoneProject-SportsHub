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
        $middleware->alias([
            'force.password.change' => \App\Http\Middleware\ForcePasswordChangeMiddleware::class,
        ]);

        /*
         * Enforce the mandatory password change on every authenticated web
         * request, not only on the student portal route group. Without this a
         * forced-change athlete could keep using the one-time temporary
         * password on any route registered outside the group.
         */
        $middleware->appendToGroup('web', \App\Http\Middleware\ForcePasswordChangeMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
