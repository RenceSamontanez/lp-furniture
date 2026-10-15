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
            'validated'     => \App\Http\Middleware\CheckValidated::class,
            'admin'         => \App\Http\Middleware\CheckAdmin::class,
            'admin.timeout' => \App\Http\Middleware\CheckAdminTimeout::class,
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
        // A 419 means the submitted form's CSRF token no longer
        // matches the current session — in practice almost always
        // because the session itself expired (idle timeout, see
        // SESSION_LIFETIME / ADMIN_SESSION_TIMEOUT) while the page sat
        // open. Laravel's default "Page Expired" page doesn't explain
        // that, so render a friendlier redirect instead. This only
        // intercepts the CSRF exception — it doesn't touch any guard,
        // middleware, or login logic.
        //
        // Laravel's base Handler::prepareException() converts the
        // underlying TokenMismatchException into a plain
        // Symfony HttpException(419, ...) BEFORE renderable callbacks
        // are checked, so a callback type-hinted on
        // TokenMismatchException itself would never match — this has
        // to catch the converted HttpException and filter by status.
        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $message = 'Your session expired for your security. Please log in and try again.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 419);
            }

            $loginRoute = $request->is('admin/*') ? route('admin.login') : route('login');

            return redirect()->guest($loginRoute)->withErrors(['email' => $message]);
        });
    })->create();