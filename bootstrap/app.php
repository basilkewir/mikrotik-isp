<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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

        // Operators authenticate at the admin login; the subscriber portal has
        // its own guard and never uses this redirect.
        $middleware->redirectGuestsTo(fn () => url('/login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

