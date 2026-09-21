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
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
        Gate::define('manage-race', fn (User $user, Race $race) => $user->isOrganizer() && $user->races()->whereKey($race->id)->exists());
        Broadcast::routes(['middleware' => ['web', 'auth']]);
        require base_path('routes/channels.php');
    }
}
