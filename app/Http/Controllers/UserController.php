<?php
namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255','unique:users,email'], 'password' => ['required','string','min:12'], 'role' => ['required', Rule::enum(UserRole::class)], 'athlete_id' => ['nullable','exists:athletes,id','unique:users,athlete_id'], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer','exists:races,id']]);
        if ($data['role'] === UserRole::Athlete->value && empty($data['athlete_id'])) return back()->withErrors(['athlete_id' => 'Athlete accounts must be linked to an athlete.']);
        $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make($data['password']), 'role' => $data['role'], 'athlete_id' => $data['athlete_id'] ?? null, 'force_password_change' => true]);
        if ($user->role === UserRole::Organizer) $user->races()->sync($data['race_ids'] ?? []);
        return back()->with('success', 'User account created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255', Rule::unique('users')->ignore($user->id)], 'role' => ['required', Rule::enum(UserRole::class)], 'is_active' => ['required','boolean'], 'athlete_id' => ['nullable','exists:athletes,id', Rule::unique('users','athlete_id')->ignore($user->id)], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer','exists:races,id'], 'password' => ['nullable','string','min:12']]);
        $user->fill(collect($data)->except(['race_ids','password'])->all());
        if (!empty($data['password'])) { $user->password = Hash::make($data['password']); $user->force_password_change = true; }
        $user->save();
        if ($user->role === UserRole::Organizer) $user->races()->sync($data['race_ids'] ?? []); else $user->races()->detach();
        return back()->with('success', 'User account updated.');
    }
}
