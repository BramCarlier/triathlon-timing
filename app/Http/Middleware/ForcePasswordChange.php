<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->force_password_change && !$request->routeIs('password.edit', 'password.update', 'logout')) {
            return redirect()->route('password.edit')->with('error', 'Change your temporary password before continuing.');
        }
        return $next($request);
    }
}
