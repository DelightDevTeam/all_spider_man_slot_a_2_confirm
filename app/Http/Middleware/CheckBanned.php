<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next): Response
    // {
    //     if (Auth::check() && Auth::user()->status == 0 ) {
    //         Auth::logout(); 
    //         return redirect()->route('login')->with('error', 'You are banned. Please contact the administrator for more information.');
    //     }
    //     return $next($request);
    // }

    public function handle(Request $request, Closure $next): Response
{
    // Ensure the authenticated user is an agent and banned (status == 0)
    if (Auth::check() && Auth::user()->status == 2) {
        Auth::logout();
        return redirect()->route('showLogin')->with('error', 'You are banned. Please contact the administrator.');
    }

    return $next($request);
}

}
