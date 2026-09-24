<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless($request->user() && collect($permissions)->contains(fn($permission)=>$request->user()->hasPermission($permission)),403,__('Your role does not allow this action.'));
        return $next($request);
    }
}
