<?php
namespace App\Http\Controllers;

use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Models\Athlete;
use App\Models\Entry;
use App\Models\EntryChange;
use Illuminate\Validation\ValidationException;
use App\Models\Race;
use App\Services\AthleteLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EntryController extends Controller
{
    public function index(Request $request, Race $race, AthleteLookupService $athletes): Response
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403);
        $search = trim((string) $request->query('search'));
        $entries = $race->entries()->with('members.athlete')->when($search, function ($q) use ($search) {
            $q->where(function ($query) use ($search) {
                $query->where('bib_number', 'like', "%{$search}%")
                    ->orWhere('team_name', 'like', "%{$search}%")
                    ->orWhereHas('members.athlete', fn ($athlete) => $athlete->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        })->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')->paginate(50)->withQueryString();
        return Inertia::render('Participants/Index', ['race' => $race, 'entries' => $entries, 'search' => $search, 'athleteOptions' => $athletes->options($race)]);
    }

    public function athleteSearch(Request $request, Race $race, AthleteLookupService $athletes): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json([
            'athletes' => $athletes->options(
                $race,
                (string) $request->query('q', ''),
                (string) $request->query('bib', ''),
            ),
        ]);
    }

    public function store(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403);
        $this->ensureRegistrationOpen($race);
        $data = $request->validate([
            'bib_number' => ['nullable','string','max:32', Rule::unique('entries')->where('race_id', $race->id)],
            'type' => ['required', Rule::enum(EntryType::class)],
            'team_name' => ['nullable','string','max:255','required_if:type,relay'],
            'category' => ['nullable','string','max:100'],
            'members' => ['required','array','min:1'],
            'members.*.discipline' => ['required', Rule::enum(Discipline::class)],
            'members.*.athlete_id' => ['nullable','integer',Rule::exists('athletes','id')],
            'members.*.first_name' => ['required','string','max:100'],
            'members.*.last_name' => ['nullable','string','max:100'],
            'members.*.email' => ['nullable','email','max:255'],
            'members.*.club' => ['nullable','string','max:255'],
        ]);

        DB::transaction(function () use ($data, $race) {
            $entry = $race->entries()->create(['bib_number' => $data['bib_number'] ?? null, 'type' => $data['type'], 'team_name' => $data['team_name'] ?? null, 'category' => $data['category'] ?? null]);
            $members = collect($data['members']);
            if ($data['type'] === EntryType::Solo->value) {
                $person = $members->first();
                $athlete = $this->athlete($person, $race);
                foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
            } else {
                foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) {
                    $person = $members->firstWhere('discipline', $discipline->value);
                    abort_unless($person, 422, __('Relay needs a :discipline athlete.', ['discipline'=>__($discipline->label())]));
                    $entry->members()->create(['athlete_id' => $this->athlete($person, $race)->id, 'discipline' => $discipline, 'position' => $index + 1]);
                }
            }
        });
        return back()->with('success', __('Participant added.'));
    }

    public function edit(Race $race, Entry $entry): Response
    {
        Gate::authorize('manage-race',$race);abort_unless($entry->race_id===$race->id,404);abort_unless(request()->user()->isAdmin(),403);
        return Inertia::render('Participants/Edit',[
            'race'=>$race,
            'entry'=>$entry->load('members.athlete.user:id,athlete_id,email,is_active,force_password_change'),
            'mailConfigured'=>app(\App\Services\AccountInvitationService::class)->configured(),
            'changes'=>EntryChange::where('entry_id',$entry->id)->with('user:id,name')->latest('id')->limit(50)->get(),
        ]);
    }

    public function update(Request $request,Race $race,Entry $entry): RedirectResponse
    {
        Gate::authorize('manage-race',$race);abort_unless($entry->race_id===$race->id,404);abort_unless($request->user()->isAdmin(),403);
        if ($race->started_at) return $this->updateResultStatus($request, $race, $entry);
        $data=$request->validate([
            'bib_number'=>['nullable','string','max:32',Rule::unique('entries')->where('race_id',$race->id)->ignore($entry->id)],
            'team_name'=>[$entry->type===EntryType::Relay?'required':'nullable','string','max:255'],
            'category'=>['nullable','string','max:100'],'status'=>['required',Rule::in(['registered','dns','dnf','dsq'])],
            'reason'=>['nullable','string','min:3','max:1000'],
            'athletes'=>['required','array'],'athletes.*.id'=>['required','integer','distinct'],
            'athletes.*.first_name'=>['required','string','max:100'],'athletes.*.last_name'=>['nullable','string','max:100'],
            'athletes.*.email'=>['nullable','email','max:255'],'athletes.*.club'=>['nullable','string','max:255'],
        ]);
        DB::transaction(function()use($request,$race,$entry,$data){
            $entry=Entry::lockForUpdate()->findOrFail($entry->id);
            $snapshot=fn()=>['entry'=>$entry->only(['bib_number','team_name','category','status']),
                'athletes'=>$entry->members()->with('athlete')->get()->pluck('athlete')->unique('id')->values()->map->only(['id','first_name','last_name','email','club'])->all()];
            $before=$snapshot();
            $ids=$entry->members()->pluck('athlete_id')->unique()->sort()->values()->all();
            $provided=collect($data['athletes'])->pluck('id')->map(fn($id)=>(int)$id)->sort()->values()->all();
            if($ids!==$provided)throw ValidationException::withMessages(['athletes'=>__('Edit the existing athletes; changing relay membership requires official review.')]);
            if($data['status']==='dns'&&$entry->timings()->exists())throw ValidationException::withMessages(['status'=>__('This participant has timing history. Use DNF or disqualification instead of DNS.')]);
            foreach($data['athletes'] as $person){
                $email=empty($person['email'])?null:strtolower($person['email']);
                if($email&&Athlete::where('email',$email)->where('id','!=',$person['id'])->exists())throw ValidationException::withMessages(['athletes'=>'That email belongs to another athlete.']);
                $athlete=Athlete::lockForUpdate()->findOrFail($person['id']);
                $athlete->fill(['first_name'=>$person['first_name'],'last_name'=>$person['last_name'],'email'=>$email,'club'=>$person['club']??null]);
                $athlete->save();
            }
            $entry->update(collect($data)->only(['bib_number','team_name','category','status'])->all());
            EntryChange::create(['entry_id'=>$entry->id,'user_id'=>$request->user()->id,'before'=>$before,'after'=>$snapshot(),'reason'=>$data['reason'] ?: 'Participant details updated']);
        });
        return back()->with('success',__('Participant updated. The change is saved in the history.'));
    }

    public function destroy(Race $race, Entry $entry): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($entry->race_id === $race->id, 404); abort_unless(request()->user()->isAdmin(),403);
        $this->ensureRegistrationOpen($race);
        if($entry->timings()->exists())throw ValidationException::withMessages(['entry'=>__('Entries with timings cannot be deleted. Use a result status to retain their history.')]);
        $entry->delete();
        return back()->with('success', __('Participant removed.'));
    }


    private function updateResultStatus(Request $request, Race $race, Entry $entry): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['registered','dns','dnf','dsq'])],
            'reason' => ['required','string','min:3','max:1000'],
        ]);

        DB::transaction(function () use ($request, $entry, $data) {
            $entry = Entry::lockForUpdate()->findOrFail($entry->id);
            $snapshot = fn () => [
                'entry' => $entry->only(['bib_number','team_name','category','status']),
                'athletes' => $entry->members()->with('athlete')->get()->pluck('athlete')->unique('id')->values()->map->only(['id','first_name','last_name','email','club'])->all(),
            ];
            $before = $snapshot();

            if ($data['status'] === 'dns' && $entry->timings()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'This participant has timing history. Use DNF or disqualification instead of DNS.',
                ]);
            }

            $entry->update(['status' => $data['status']]);
            EntryChange::create([
                'entry_id' => $entry->id,
                'user_id' => $request->user()->id,
                'before' => $before,
                'after' => $snapshot(),
                'reason' => $data['reason'],
            ]);
        });

        return back()->with('success', 'Participant result status updated. The reason is saved in the audit history.');
    }

    private function ensureRegistrationOpen(Race $race): void
    {
        if ($race->started_at) {
            throw ValidationException::withMessages([
                'race' => 'Participant registration is locked after the race starts.',
            ]);
        }
    }

    private function athlete(array $data, Race $race): Athlete
    {
        $athlete = !empty($data['athlete_id'])
            ? Athlete::findOrFail((int) $data['athlete_id'])
            : null;

        $email = !empty($data['email']) ? strtolower(trim((string) $data['email'])) : null;
        if (!$athlete && $email) $athlete = Athlete::where('email', $email)->first();

        if ($athlete) {
            $alreadyRegistered = $athlete->memberships()
                ->whereHas('entry', fn ($query) => $query->where('race_id', $race->id))
                ->exists();

            if ($alreadyRegistered) {
                throw ValidationException::withMessages([
                    'members' => "{$athlete->full_name} is already registered in this race. Reuse is intended for a different race.",
                ]);
            }

            return $athlete;
        }

        return Athlete::create([
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'email' => $email,
            'club' => $data['club'] ?? null,
        ]);
    }
}
