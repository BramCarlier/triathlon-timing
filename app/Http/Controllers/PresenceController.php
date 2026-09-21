<?php
namespace App\Http\Controllers;

use App\Models\OperatorPresence;
use App\Models\Race;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PresenceController extends Controller
{
    public function ping(Request $request, Race $race): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        $data = $request->validate(['device_uuid' => ['required','uuid'], 'checkpoint_id' => ['required', Rule::exists('checkpoints','id')->where('race_id', $race->id)], 'pending_count' => ['required','integer','min:0','max:10000']]);
        OperatorPresence::updateOrCreate(['device_uuid' => $data['device_uuid'], 'race_id' => $race->id], ['user_id' => $request->user()->id, 'checkpoint_id' => $data['checkpoint_id'], 'pending_count' => $data['pending_count'], 'last_seen_at' => now()]);
        return response()->json(['ok' => true, 'server_now' => now('UTC')->toISOString(), 'started_at' => $race->started_at?->toISOString(), 'finished_at' => $race->finished_at?->toISOString(), 'status' => $race->status->value]);
    }
}
