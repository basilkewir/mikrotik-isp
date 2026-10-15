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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Monetbil server-to-server webhook — authenticated via signature + checkPayment.
        $middleware->validateCsrfTokens(except: [
            'payments/monetbil/notify',
        ]);

        // No admin auth yet — unauthenticated users are sent to the subscriber portal login.
        $middleware->redirectGuestsTo(fn () => url('/portal/login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

