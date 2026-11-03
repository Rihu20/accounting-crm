<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('shared-login', fn (Request $r) => [Limit::perMinute(10)->by($r->ip()), Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip())]);
        RateLimiter::for('staff-write', fn (Request $r) => Limit::perMinute(90)->by($r->user()?->id ?? $r->ip()));

    }
}
