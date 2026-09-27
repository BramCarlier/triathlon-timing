<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response { return Inertia::render('Auth/Login'); }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'remember' => ['nullable', 'boolean']]);
        $credentials['email'] = strtolower($credentials['email']);
        // Public timing and leaderboard traffic must never consume login attempts.
        $throttleKey = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ValidationException::withMessages(['email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($throttleKey),
            ])]);
        }
        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);
        $user = User::where('email', strtolower($credentials['email']))->first();
        if (!$user || !$user->is_active || !Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $remember)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages(['email' => __('The provided credentials are invalid.')]);
        }
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
