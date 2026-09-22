<?php
namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', ['users' => User::with(['athlete:id,first_name,last_name', 'races:id,name'])->orderBy('name')->get(), 'athletes' => Athlete::doesntHave('user')->orderBy('last_name')->limit(500)->get(['id','first_name','last_name','email']), 'races' => Race::latest('event_date')->get(['id','name','event_date'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255','unique:users,email'], 'password' => ['required','string','min:12'], 'role' => ['required', Rule::enum(UserRole::class)], 'athlete_id' => ['nullable','exists:athletes,id','unique:users,athlete_id'], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer', Rule::exists('races', 'id')->whereNull('deleted_at')]]);
        if ($data['role'] === UserRole::Athlete->value && empty($data['athlete_id'])) return back()->withErrors(['athlete_id' => 'Athlete accounts must be linked to an athlete.']);
        $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make($data['password']), 'role' => $data['role'], 'athlete_id' => $data['role'] === UserRole::Athlete->value ? $data['athlete_id'] : null, 'force_password_change' => true]);
        if ($user->role === UserRole::Organizer) $user->races()->sync($data['race_ids'] ?? []);
        return back()->with('success', 'User account created.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Edit', [
            'account' => $user->load('races:id,name'),
            'athletes' => Athlete::where(fn ($query) => $query->doesntHave('user')->orWhere('id', $user->athlete_id))->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'email']),
            'races' => Race::latest('event_date')->get(['id', 'name', 'event_date']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255', Rule::unique('users')->ignore($user->id)], 'role' => ['required', Rule::enum(UserRole::class)], 'is_active' => ['required','boolean'], 'athlete_id' => ['nullable','required_if:role,athlete','exists:athletes,id', Rule::unique('users','athlete_id')->ignore($user->id)], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer', Rule::exists('races', 'id')->whereNull('deleted_at')], 'password' => ['nullable','string','min:12']]);
        DB::transaction(function () use ($user, $data) {
            // Lock the administrator set so concurrent edits cannot remove the last one.
            $admins = User::where('role', UserRole::Admin->value)->orderBy('id')->lockForUpdate()->get();
            $account = User::lockForUpdate()->findOrFail($user->id);
            if ($account->isAdmin() && $account->is_active && ($data['role'] !== UserRole::Admin->value || !$data['is_active'])
                && !$admins->contains(fn ($admin) => $admin->id !== $account->id && $admin->is_active)) {
                throw ValidationException::withMessages(['role' => 'Keep at least one active administrator. Create or enable another administrator before changing this account.']);
            }
            $account->fill(collect($data)->except(['race_ids','password'])->all());
            $account->athlete_id = $data['role'] === UserRole::Athlete->value ? $data['athlete_id'] : null;
            if (!empty($data['password'])) { $account->password = Hash::make($data['password']); $account->force_password_change = true; }
            $account->save();
            if ($account->role === UserRole::Organizer) $account->races()->sync($data['race_ids'] ?? []); elseif ($account->role === UserRole::Athlete) $account->races()->detach();
        });
        return redirect()->route($request->user()->id === $user->id && !$user->fresh()->isAdmin() ? 'dashboard' : 'users.index')->with('success', 'User account updated.');
    }
}
