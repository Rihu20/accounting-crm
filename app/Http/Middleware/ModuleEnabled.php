<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(config('platform.modules.'.$module, false), 404);

        return $next($request);
    }
}
