<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user() && ! $request->user()->is_active, 403, 'This account has been disabled.');

        return $next($request);
    }
}
