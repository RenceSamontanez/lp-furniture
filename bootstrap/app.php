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
        $middleware->alias([
            'validated' => \App\Http\Middleware\CheckValidated::class,
        ]);

        $middleware->alias([
            'validated' => \App\Http\Middleware\CheckValidated::class,
            'admin'     => \App\Http\Middleware\CheckAdmin::class,
        ]);

        // Webhooks are POSTed by the payment providers' own servers,
        // not a browser with a session-bound CSRF token — without this,
        // both webhook routes 419 on every real request. Found this
        // gap while wiring up PayMongo's webhook (which actually does
        // something); fixing it for the existing Stripe one too since
        // it's the same one-line issue and was equally unreachable.
        $middleware->validateCsrfTokens(except: [
            'webhook/stripe',
            'webhook/paymongo',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();