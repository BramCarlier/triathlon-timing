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

        $email = strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        $existingByEmail = $email !== '' ? User::where('email', $email)->first() : null;
        $reusableExisting = $existingByEmail
            && in_array($existingByEmail->role, [UserRole::Admin, UserRole::Official], true)
            && $existingByEmail->is_active;
        $creating = !$request->filled('user_id') && !$reusableExisting;

        $data = $request->validate([
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where(fn ($query) => $query->where('race_id', $race->id)->where('kind', '!=', 'start')->where('is_active', true))],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', [UserRole::Admin->value, UserRole::Official->value])
                    ->where('is_active', true)),
            ],
            'name' => ['nullable', Rule::requiredIf(!$request->filled('user_id')), 'string', 'max:255'],
            'email' => ['nullable', Rule::requiredIf(!$request->filled('user_id')), 'email', 'max:255'],
            'delivery' => ['nullable', Rule::requiredIf($creating), Rule::in(['email', 'manual'])],
            'password' => ['nullable', Rule::requiredIf($creating && $request->input('delivery') === 'manual'), 'string', 'min:12'],
        ]);

        if ($existingByEmail && !$reusableExisting && !$request->filled('user_id')) {
            throw ValidationException::withMessages([
                'email' => 'That email already belongs to an account that cannot be assigned as an Official.',
            ]);
        }

        $emailInvite = $creating && ($data['delivery'] ?? 'email') === 'email';
        if ($emailInvite && !$invitations->configured()) {
            throw ValidationException::withMessages([
                'delivery' => 'Email sending is not configured. Choose a temporary password instead.',
            ]);
        }

        $created = false;
        $official = DB::transaction(function () use ($data, $race, $emailInvite, $existingByEmail, $reusableExisting, &$created) {
            if (!empty($data['user_id'])) {
                $official = User::whereIn('role', [UserRole::Admin->value, UserRole::Official->value])
                    ->where('is_active', true)
                    ->findOrFail($data['user_id']);
            } elseif ($reusableExisting && $existingByEmail) {
                $official = $existingByEmail;
            } else {
                $official = User::create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => Hash::make($emailInvite ? Str::random(64) : $data['password']),
                    'role' => UserRole::Official,
                    'force_password_change' => true,
                ]);
                $created = true;
            }

            $official->races()->syncWithoutDetaching([$race->id]);
            CheckpointAssignment::updateOrCreate(
                ['race_id' => $race->id, 'user_id' => $official->id],
                ['checkpoint_id' => $data['checkpoint_id']],
            );

            return $official;
        });

        if ($emailInvite && !$invitations->send($official)) {
            return back()->with('error', 'Official created and assigned, but the invitation email could not be sent. You can resend it from People.');
        }

        return back()->with('success', $created
            ? 'Official created and assigned to the checkpoint.'
            : 'Official assigned to the checkpoint.');
    }

    public function destroy(Request $request, Race $race, User $official): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);
        abort_unless(in_array($official->role, [UserRole::Admin, UserRole::Official], true), 404);

        CheckpointAssignment::where('race_id', $race->id)
            ->where('user_id', $official->id)
            ->delete();

        if (!$official->isAdmin()) {
            $official->races()->detach($race->id);
        }

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
