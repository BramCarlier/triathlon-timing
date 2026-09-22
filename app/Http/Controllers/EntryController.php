<?php
namespace App\Http\Controllers;

use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Models\Athlete;
use App\Models\Entry;
use App\Models\EntryChange;
use Illuminate\Validation\ValidationException;
use App\Models\Race;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EntryController extends Controller
{
    public function index(Request $request, Race $race): Response
    {
        Gate::authorize('manage-race', $race);
        $search = trim((string) $request->query('search'));
        $entries = $race->entries()->with('members.athlete')->when($search, function ($q) use ($search) {
            $q->where(function ($query) use ($search) {
                $query->where('bib_number', 'like', "%{$search}%")
                    ->orWhere('team_name', 'like', "%{$search}%")
                    ->orWhereHas('members.athlete', fn ($athlete) => $athlete->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        })->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')->paginate(50)->withQueryString();
        return Inertia::render('Participants/Index', ['race' => $race, 'entries' => $entries, 'search' => $search]);
    }

    public function store(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $data = $request->validate([
            'bib_number' => ['nullable','string','max:32', Rule::unique('entries')->where('race_id', $race->id)],
            'type' => ['required', Rule::enum(EntryType::class)],
            'team_name' => ['nullable','string','max:255','required_if:type,relay'],
            'category' => ['nullable','string','max:100'],
            'members' => ['required','array','min:1'],
            'members.*.discipline' => ['required', Rule::enum(Discipline::class)],
            'members.*.first_name' => ['required','string','max:100'],
            'members.*.last_name' => ['required','string','max:100'],
            'members.*.email' => ['nullable','email','max:255'],
            'members.*.club' => ['nullable','string','max:255'],
        ]);

        DB::transaction(function () use ($data, $race) {
            $entry = $race->entries()->create(['bib_number' => $data['bib_number'] ?? null, 'type' => $data['type'], 'team_name' => $data['team_name'] ?? null, 'category' => $data['category'] ?? null]);
            $members = collect($data['members']);
            if ($data['type'] === EntryType::Solo->value) {
                $person = $members->first();
                $athlete = $this->athlete($person);
                foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
            } else {
                foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) {
                    $person = $members->firstWhere('discipline', $discipline->value);
                    abort_unless($person, 422, "Relay needs a {$discipline->value} athlete.");
                    $entry->members()->create(['athlete_id' => $this->athlete($person)->id, 'discipline' => $discipline, 'position' => $index + 1]);
                }
            }
        });
        return back()->with('success', 'Participant added.');
    }

    public function edit(Race $race, Entry $entry): Response
    {
        Gate::authorize('manage-race',$race);abort_unless($entry->race_id===$race->id,404);
        return Inertia::render('Participants/Edit',['race'=>$race,'entry'=>$entry->load('members.athlete'),
            'changes'=>EntryChange::where('entry_id',$entry->id)->with('user:id,name')->latest('id')->limit(50)->get()]);
    }

    public function update(Request $request,Race $race,Entry $entry): RedirectResponse
    {
        Gate::authorize('manage-race',$race);abort_unless($entry->race_id===$race->id,404);
        $data=$request->validate([
            'bib_number'=>['nullable','string','max:32',Rule::unique('entries')->where('race_id',$race->id)->ignore($entry->id)],
            'team_name'=>[$entry->type===EntryType::Relay?'required':'nullable','string','max:255'],
            'category'=>['nullable','string','max:100'],'status'=>['required',Rule::in(['registered','dns','dnf','dsq'])],
            'reason'=>['required','string','min:3','max:1000'],
            'athletes'=>['required','array'],'athletes.*.id'=>['required','integer','distinct'],
            'athletes.*.first_name'=>['required','string','max:100'],'athletes.*.last_name'=>['required','string','max:100'],
            'athletes.*.email'=>['nullable','email','max:255'],'athletes.*.club'=>['nullable','string','max:255'],
        ]);
        DB::transaction(function()use($request,$race,$entry,$data){
            $entry=Entry::lockForUpdate()->findOrFail($entry->id);
            $snapshot=fn()=>['entry'=>$entry->only(['bib_number','team_name','category','status']),
                'athletes'=>$entry->members()->with('athlete')->get()->pluck('athlete')->unique('id')->values()->map->only(['id','first_name','last_name','email','club'])->all()];
            $before=$snapshot();
            $ids=$entry->members()->pluck('athlete_id')->unique()->sort()->values()->all();
            $provided=collect($data['athletes'])->pluck('id')->map(fn($id)=>(int)$id)->sort()->values()->all();
            if($ids!==$provided)throw ValidationException::withMessages(['athletes'=>'Edit the existing athletes; changing relay membership requires organizer review.']);
            if($data['status']==='dns'&&$entry->timings()->exists())throw ValidationException::withMessages(['status'=>'This participant has timing history. Use DNF or disqualification instead of DNS.']);
            foreach($data['athletes'] as $person){
                $email=empty($person['email'])?null:strtolower($person['email']);
                if($email&&Athlete::where('email',$email)->where('id','!=',$person['id'])->exists())throw ValidationException::withMessages(['athletes'=>'That email belongs to another athlete.']);
                $athlete=Athlete::lockForUpdate()->findOrFail($person['id']);
                $athlete->fill(['first_name'=>$person['first_name'],'last_name'=>$person['last_name'],'email'=>$email,'club'=>$person['club']??null]);
                if($athlete->isDirty()&&!$request->user()->isAdmin()&&$athlete->memberships()->whereHas('entry.race',fn($query)=>$query->whereDoesntHave('organizers',fn($users)=>$users->whereKey($request->user()->id)))->exists()) {
                    throw ValidationException::withMessages(['athletes'=>'This athlete also belongs to another organizer’s race. Ask an administrator to change their shared profile. You can still edit this entry’s bib, category and status.']);
                }
                $athlete->save();
            }
            $entry->update(collect($data)->only(['bib_number','team_name','category','status'])->all());
            EntryChange::create(['entry_id'=>$entry->id,'user_id'=>$request->user()->id,'before'=>$before,'after'=>$snapshot(),'reason'=>$data['reason']]);
        });
        return back()->with('success','Participant updated. The change and reason are saved in the audit history.');
    }

    public function destroy(Race $race, Entry $entry): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($entry->race_id === $race->id, 404);
        if($entry->timings()->exists())throw ValidationException::withMessages(['entry'=>'Entries with timings cannot be deleted. Use a result status to retain their history.']);
        $entry->delete();
        return back()->with('success', 'Participant removed.');
    }

    private function athlete(array $data): Athlete
    {
        $email = !empty($data['email']) ? strtolower($data['email']) : null;
        if ($email && ($existing = Athlete::where('email', $email)->first())) return $existing;
        return Athlete::create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $email, 'club' => $data['club'] ?? null]);
    }
}
