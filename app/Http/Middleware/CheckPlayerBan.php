<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPlayerBan
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
{
    if (Auth::check() && Auth::user()->status == 0) {
        Auth::logout();
        return response()->json([
            'message' => 'You are banned. Please contact the administrator for more information.'
        ], 403); // Forbidden status
    }

    return $next($request);
}

}
