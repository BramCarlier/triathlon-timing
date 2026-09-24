<?php
namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use App\Services\AccountInvitationService;
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
    public function index(Request $request): Response
    {
        $q=mb_substr(trim((string)$request->query('q','')),0,100);
        $users=User::with(['athlete:id,first_name,last_name','races:id,name','accessRole:id,name'])->when($q!=='',fn($query)=>$query->where(fn($match)=>$match->where('name','like',"%$q%")->orWhere('email','like',"%$q%")))->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
        return Inertia::render('Users/Index', ['accessRoles'=>\App\Models\AccessRole::orderBy('name')->get(), 'users'=>$users,'filters'=>['q'=>$q], 'mailConfigured'=>app(\App\Services\AccountInvitationService::class)->configured(), 'races'=>Race::latest('event_date')->get(['id','name','event_date'])]);
    }

    public function athletes(Request $request): \Illuminate\Http\JsonResponse
    {
        $data=$request->validate(['q'=>['nullable','string','max:100'],'page'=>['nullable','integer','min:1'],'account_id'=>['nullable','integer','exists:users,id']]);
        $linked=isset($data['account_id'])?User::findOrFail($data['account_id'])->athlete_id:null;
        $q=trim($data['q']??'');
        $athletes=Athlete::where(fn($query)=>$query->doesntHave('user')->when($linked,fn($query)=>$query->orWhere('id',$linked)))
            ->when($q!=='',fn($query)=>$query->where(fn($match)=>$match->where('first_name','like',"%$q%")->orWhere('last_name','like',"%$q%")->orWhere('email','like',"%$q%")))
            ->orderBy('last_name')->orderBy('id')->paginate(25,['id','first_name','last_name','email']);
        return response()->json($athletes);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255','unique:users,email'], 'delivery'=>['nullable','in:email,manual'], 'password' => ['nullable','required_if:delivery,manual','string','min:12'], 'role' => ['required', Rule::enum(UserRole::class)], 'access_role_id'=>['nullable','integer','exists:access_roles,id'], 'athlete_id' => ['nullable','exists:athletes,id','unique:users,athlete_id'], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer', Rule::exists('races', 'id')->whereNull('deleted_at')]]);
        if ($data['role'] === UserRole::Athlete->value && empty($data['athlete_id'])) return back()->withErrors(['athlete_id' => 'Athlete accounts must be linked to an athlete.']);
        $emailInvite=($data['delivery']??(empty($data['password'])?'email':'manual'))==='email';
        if($emailInvite && !app(\App\Services\AccountInvitationService::class)->configured()) return back()->withErrors(['delivery'=>'Email sending is not configured. Connect email sending first, or choose a temporary password and share it yourself.']);
        $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make($emailInvite ? \Illuminate\Support\Str::random(64) : $data['password']), 'role' => $data['role'], 'access_role_id'=>$data['role']==='organizer'?($data['access_role_id']??null):null, 'athlete_id' => $data['role'] === UserRole::Athlete->value ? $data['athlete_id'] : null, 'force_password_change' => true]);
        if ($user->role === UserRole::Organizer) $user->races()->sync($data['race_ids'] ?? []);
        if($emailInvite && !app(\App\Services\AccountInvitationService::class)->send($user)) return back()->with('error','Account created, but the invitation could not be sent. Check email settings and use Resend invitation.');
        return back()->with('success', $emailInvite ? 'Account created. Invitation accepted by the mail server; ask the recipient to check their inbox and spam folder.' : 'Account created. Share the temporary password privately; it must be changed at first login.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Edit', [
            'accessRoles'=>\App\Models\AccessRole::orderBy('name')->get(),
            'mailConfigured'=>app(\App\Services\AccountInvitationService::class)->configured(),
            'account' => $user->load('races:id,name'),
            'linkedAthlete' => $user->athlete?->only(['id','first_name','last_name','email']),
            'races' => Race::latest('event_date')->get(['id', 'name', 'event_date']),
        ]);
    }

    public function invite(User $user): RedirectResponse
    {
        abort_unless($user->is_active && $user->force_password_change,422,'Only active accounts awaiting password setup can be invited.');
        $sent=app(\App\Services\AccountInvitationService::class)->send($user);
        return back()->with($sent?'success':'error',$sent?'Invitation accepted by the mail server. The previous setup link is no longer valid.':'Invitation could not be sent. Check email settings and retry.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);
        $data = $request->validate(['name' => ['required','string','max:255'], 'email' => ['required','email','max:255', Rule::unique('users')->ignore($user->id)], 'role' => ['required', Rule::enum(UserRole::class)], 'access_role_id'=>['nullable','integer','exists:access_roles,id'], 'is_active' => ['required','boolean'], 'athlete_id' => ['nullable','required_if:role,athlete','exists:athletes,id', Rule::unique('users','athlete_id')->ignore($user->id)], 'race_ids' => ['nullable','array'], 'race_ids.*' => ['integer', Rule::exists('races', 'id')->whereNull('deleted_at')], 'password' => ['nullable','string','min:12']]);
        DB::transaction(function () use ($user, $data) {
            // Lock the administrator set so concurrent edits cannot remove the last one.
            $admins = User::where('role', UserRole::Admin->value)->orderBy('id')->lockForUpdate()->get();
            $account = User::lockForUpdate()->findOrFail($user->id);
            if ($account->isAdmin() && $account->is_active && ($data['role'] !== UserRole::Admin->value || !$data['is_active'])
                && !$admins->contains(fn ($admin) => $admin->id !== $account->id && $admin->is_active)) {
                throw ValidationException::withMessages(['role' => 'Keep at least one active organizer (admin). Create or enable another organizer (admin) before changing this account.']);
            }
            $account->fill(collect($data)->except(['race_ids','password'])->all());
            $account->access_role_id = $data['role']==='organizer'?($data['access_role_id']??null):null;
            $account->athlete_id = $data['role'] === UserRole::Athlete->value ? $data['athlete_id'] : null;
            if (!empty($data['password'])) { $account->password = Hash::make($data['password']); $account->force_password_change = true; $account->invitation_sent_at=null; \Illuminate\Support\Facades\Password::deleteToken($account); }
            $account->save();
            if ($account->role === UserRole::Organizer) $account->races()->sync($data['race_ids'] ?? []); elseif ($account->role === UserRole::Athlete) $account->races()->detach();
        });
        return redirect()->route($request->user()->id === $user->id && !$user->fresh()->isAdmin() ? 'dashboard' : 'users.index')->with('success', 'User account updated.');
    }

    public function inviteAthlete(Athlete $athlete, AccountInvitationService $invitations): RedirectResponse
    {
        $email = strtolower(trim((string) $athlete->email));
        if ($email === '') {
            throw ValidationException::withMessages(['athlete' => 'Add an email address to this athlete before sending a results invitation.']);
        }

        $existing = $athlete->user;
        if ($existing) {
            if (!$existing->is_active) {
                throw ValidationException::withMessages(['athlete' => 'This athlete already has a disabled account. Re-enable it from People.']);
            }
            if (!$existing->force_password_change) {
                return back()->with('success', 'This athlete already has an active account for viewing results.');
            }
            if (!$invitations->configured()) {
                throw ValidationException::withMessages(['athlete' => 'Email sending is not configured. Manage this account from People instead.']);
            }
            return back()->with($invitations->send($existing) ? 'success' : 'error', $invitations->send($existing)
                ? 'A fresh results invitation was sent.'
                : 'The results invitation could not be sent. Check email settings and retry.');
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['athlete' => 'That email already belongs to another account. Resolve it from People before inviting this athlete.']);
        }
        if (!$invitations->configured()) {
            throw ValidationException::withMessages(['athlete' => 'Email sending is not configured. Manage this account from People instead.']);
        }

        $user = User::create([
            'name' => $athlete->full_name,
            'email' => $email,
            'password' => Hash::make(\Illuminate\Support\Str::random(64)),
            'role' => UserRole::Athlete,
            'athlete_id' => $athlete->id,
            'force_password_change' => true,
        ]);

        if (!$invitations->send($user)) {
            return back()->with('error', 'Athlete account created, but the results invitation could not be sent. You can resend it from People.');
        }

        return back()->with('success', 'Athlete invited to view their races and results.');
    }

}
