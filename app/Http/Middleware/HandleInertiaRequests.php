<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $this->keepUndeliveredFlash($request);

        $user = $request->user();

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'permissions' => $user->effectivePermissions(),
                    'athlete_id' => $user->athlete_id,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Race pages refresh in the background with partial reloads that never ask for
     * the flash message. Without this, a refresh that reaches the server between a
     * form's redirect and the page it redirects to consumes the message, and the
     * person never sees "Participant added." Messages that are still waiting to be
     * shown are kept for the next request; once a full page visit has shown them,
     * Laravel discards them as usual.
     */
    private function keepUndeliveredFlash(Request $request): void
    {
        if (! $request->hasSession() || ! $request->header('X-Inertia-Partial-Data')) {
            return;
        }

        $requested = explode(',', (string) $request->header('X-Inertia-Partial-Data'));
        if (! in_array('flash', $requested, true)) {
            $request->session()->reflash();
        }
    }
}
