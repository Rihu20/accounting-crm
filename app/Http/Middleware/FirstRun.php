<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class FirstRun
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local') && $request->is('admin*') && ! User::role('Accountant')->exists()) {
            return redirect('/setup');
        }

        return $next($request);
    }
}
