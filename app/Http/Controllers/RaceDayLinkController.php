<?php

namespace App\Http\Controllers;

use App\Models\Race;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Organizer controls for the account-free race-day timing link. */
class RaceDayLinkController extends Controller
{
    public function update(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $race->forceFill(['public_timing_enabled' => $data['enabled']])->save();

        return back()->with('success', $data['enabled']
            ? __('Race-day link switched on.')
            : __('Race-day link switched off. Devices using it can no longer record times.'));
    }

    public function rotate(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403);

        $race->rotatePublicTimingToken();

        return back()->with('success', __('New race-day link created. The old link no longer works; share the new one with your timers.'));
    }
}
