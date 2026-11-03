<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        /*
         * Medical records hold sensitive student information, so the module
         * splits "who may open the medical module" from "who may read the
         * private details". Administrators hold both today; a future coach or
         * coordinator role can be granted the module without ever being given
         * findings, restrictions, notes or the uploaded documents.
         */
        Gate::define('view-medical-details', fn (User $user) => $user->role === 'Administrator');

        /*
         * Slow down password guessing on the sign-in screen. The identifier
         * and the network source are limited separately, so one account cannot
         * be hammered and one address cannot be used to spray many accounts.
         * A shared school network is covered by the per-address ceiling.
         */
        RateLimiter::for('login', function (Request $request) {
            $identifier = Str::lower(trim((string) $request->input('username')));

            return [
                Limit::perMinute(5)->by('identifier|'.$identifier.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });
    }
}
