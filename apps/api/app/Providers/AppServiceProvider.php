<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.hash('sha256', Str::lower((string) $request->input('email'))).'|'.$request->ip()),
        ]);
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/').'/admin/password#'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ]));
    }
}
