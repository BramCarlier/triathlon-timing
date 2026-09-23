<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\CheckpointAssignment;
use App\Models\Race;
use App\Models\User;
use App\Services\AccountInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RaceOfficialController extends Controller
{
    public function store(Request $request, Race $race, AccountInvitationService $invitations): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);
        $data = $request->validate([
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where(fn ($query) => $query->where('race_id', $race->id)->where('kind', '!=', 'start')->where('is_active', true))],
            'mode' => ['required', Rule::in(['existing', 'new'])],
            'user_id' => [
                'nullable',
                'required_if:mode,existing',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::Organizer->value)
                    ->where('is_active', true)),
            ],
            'name' => ['nullable', 'required_if:mode,new', 'string', 'max:255'],
            'email' => ['nullable', 'required_if:mode,new', 'email', 'max:255', Rule::unique('users', 'email')],
            'delivery' => ['nullable', 'required_if:mode,new', Rule::in(['email', 'manual'])],
            'password' => ['nullable', Rule::requiredIf(fn () => $request->input('mode') === 'new' && $request->input('delivery') === 'manual'), 'string', 'min:12'],
        ]);

        $emailInvite = ($data['mode'] === 'new' && ($data['delivery'] ?? 'email') === 'email');
        if ($emailInvite && !$invitations->configured()) {
            throw ValidationException::withMessages([
                'delivery' => 'Email sending is not configured. Choose a temporary password instead.',
            ]);
        }

        $official = DB::transaction(function () use ($data, $race, $emailInvite) {
            if ($data['mode'] === 'existing') {
                $official = User::where('role', UserRole::Organizer->value)
                    ->where('is_active', true)
                    ->findOrFail($data['user_id']);
            } else {
                $official = User::create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => Hash::make($emailInvite ? Str::random(64) : $data['password']),
                    'role' => UserRole::Organizer,
                    'force_password_change' => true,
                ]);
            }

            $official->races()->syncWithoutDetaching([$race->id]);
            CheckpointAssignment::updateOrCreate(
                ['race_id' => $race->id, 'user_id' => $official->id],
                ['checkpoint_id' => $data['checkpoint_id']],
            );

            return $official;
        });

        if ($emailInvite && !$invitations->send($official)) {
            return back()->with('error', 'Official created and assigned, but the invitation email could not be sent. You can resend it from Users.');
        }

        return back()->with('success', $data['mode'] === 'new'
            ? 'Official created and assigned to the checkpoint.'
            : 'Official assigned to the checkpoint.');
    }

    public function destroy(Request $request, Race $race, User $official): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);
        abort_unless($official->role === UserRole::Organizer, 404);

        CheckpointAssignment::where('race_id', $race->id)
            ->where('user_id', $official->id)
            ->delete();
        $official->races()->detach($race->id);

        return back()->with('success', 'Official removed from this race.');
    }

    private function ensureSetupUnlocked(Race $race): void
    {
        if ($race->started_at) {
            throw ValidationException::withMessages([
                'race' => 'Official assignments are locked after the race starts.',
            ]);
        }
    }
}
