<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckValidated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->user()->is_validated) {

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your account is not yet verified.'
                ], 403);
            }

            // Other Services is a request, not an order: say so, and remember where to send the customer back to.
            if ($request->routeIs('services.request*')) {
                session(['validation_return' => 'services.request']);

                return redirect()->route('validation.show');
            }

            return redirect()->route('validation.show')
                ->with('warning', 'Please verify your identity before placing an order.');
        }

        return $next($request);
    }
}