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

            return redirect()->route('validation.show')
                ->with('warning', 'Please verify your identity before placing an order.');
        }

        return $next($request);
    }
}