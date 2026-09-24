<?php
namespace App\Providers;

use App\Models\Race;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        \Illuminate\Support\Facades\Mail::extend('gmail', fn (array $config = []) => new \App\Mail\GmailApiTransport(config('services.gmail', [])));
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
        Gate::define('manage-race', fn (User $user, Race $race) => $user->isRaceStaff() && $user->races()->whereKey($race->id)->exists());
        Broadcast::routes(['middleware' => ['web', 'auth']]);
        require base_path('routes/channels.php');
    }
}
