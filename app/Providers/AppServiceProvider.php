<?php

namespace App\Providers;

use App\Mail\GmailApiTransport;
use App\Models\Race;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Mail::extend('gmail', fn (array $config = []) => new GmailApiTransport(config('services.gmail', [])));
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
        Gate::define('manage-race', fn (User $user, Race $race) => $user->isRaceStaff() && $user->races()->whereKey($race->id)->exists());
        // Timers at one venue often share an IP (venue Wi-Fi, carrier NAT), so the
        // race-day limit is per device when the browser sends its device id.
        RateLimiter::for('race-day', fn (Request $request) => Limit::perMinute(600)->by(
            $request->ip().'|'.substr((string) $request->header('X-Device-Id', ''), 0, 64)
        ));
        Broadcast::routes(['middleware' => ['web', 'auth']]);
        require base_path('routes/channels.php');
    }
}
