<?php
namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Enums\Discipline;
use App\Enums\RaceStatus;
use App\Enums\TimingStatus;
use App\Models\Race;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RaceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Race::withCount('entries')->latest('event_date');
        if (!$request->user()->isAdmin()) $query->whereHas('organizers', fn ($q) => $q->whereKey($request->user()->id));
        return Inertia::render('Races/Index', [
            'races' => $query->get(), 'serverNow'=>now('UTC')->toISOString(),
            'deletedRaces' => $request->user()->isAdmin() ? Race::onlyTrashed()->latest('deleted_at')->get() : [],
        ]);
    }

    public function create(): Response { return Inertia::render('Races/Create'); }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'event_date' => ['required', 'date'], 'timezone' => ['required', 'timezone'], 'swim_km' => ['nullable', 'numeric', 'min:0'], 'bike_km' => ['nullable', 'numeric', 'min:0'], 'run_km' => ['nullable', 'numeric', 'min:0'], 'auto_finish' => ['nullable', 'boolean']]);
        $race = DB::transaction(function () use ($request, $data) {
            $race = Race::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
                'event_date' => $data['event_date'],
                'timezone' => $data['timezone'],
                'status' => RaceStatus::Draft,
                'settings' => ['swim_km' => $data['swim_km'] ?? 1, 'bike_km' => $data['bike_km'] ?? 35, 'run_km' => $data['run_km'] ?? 8, 'auto_finish' => $data['auto_finish'] ?? true],
                'created_by' => $request->user()->id,
            ]);
            $race->organizers()->syncWithoutDetaching([$request->user()->id]);
            $race->checkpoints()->createMany([
                ['name' => 'Race Start', 'code' => 'START', 'sequence' => 0, 'kind' => CheckpointKind::Start, 'discipline' => null, 'distance_km' => 0, 'is_required' => true],
                ['name' => 'Swim Exit', 'code' => 'SWIM_FINISH', 'sequence' => 10, 'kind' => CheckpointKind::Transition, 'discipline' => Discipline::Swim, 'distance_km' => $data['swim_km'] ?? 1, 'is_required' => true],
                ['name' => 'T1 (Bike Start)', 'code' => 'BIKE_START', 'sequence' => 20, 'kind' => CheckpointKind::Transition, 'discipline' => Discipline::Bike, 'distance_km' => 0, 'is_required' => true],
                ['name' => 'Bike Finish', 'code' => 'BIKE_FINISH', 'sequence' => 30, 'kind' => CheckpointKind::Transition, 'discipline' => Discipline::Bike, 'distance_km' => $data['bike_km'] ?? 35, 'is_required' => true],
                ['name' => 'T2 (Run Start)', 'code' => 'RUN_START', 'sequence' => 40, 'kind' => CheckpointKind::Transition, 'discipline' => Discipline::Run, 'distance_km' => 0, 'is_required' => true],
                ['name' => 'Finish', 'code' => 'RUN_FINISH', 'sequence' => 50, 'kind' => CheckpointKind::Finish, 'discipline' => Discipline::Run, 'distance_km' => $data['run_km'] ?? 8, 'is_required' => true],
            ]);
            return $race;
        });
        return redirect()->route('races.show', $race)->with('success', 'Race created with default triathlon checkpoints.');
    }

    public function show(Request $request, Race $race): Response
    {
        Gate::authorize('manage-race', $race);
        $race->load(['checkpoints' => fn ($query) => $query->orderBy('sequence'), 'organizers:id,name,email,is_active'])->loadCount('entries');
        $organizers = $request->user()->isAdmin() ? User::whereIn('role', ['admin', 'organizer'])->where('is_active', true)->orderBy('name')->get(['id','name','email']) : [];

        $participants = $race->entries()
            ->with([
                'members.athlete',
                'timings' => fn ($query) => $query->where('status', TimingStatus::Recorded->value)->select('id', 'entry_id', 'checkpoint_id'),
            ])
            ->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'status' => $entry->status,
                'bib_number' => $entry->bib_number,
                'type' => $entry->type->value,
                'name' => $entry->displayName(),
                'members' => $entry->members->map(fn ($member) => ['discipline' => $member->discipline->value, 'name' => $member->athlete->full_name])->values()->all(),
                'completed_checkpoint_ids' => $entry->timings->pluck('checkpoint_id')->values()->all(),
            ])->values();

        $recentTimings = $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->with(['entry.members.athlete', 'checkpoint:id,name', 'operator:id,name'])
            ->latest('recorded_at')
            ->limit(12)
            ->get();

        $completedCount = $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->whereHas('checkpoint', fn ($query) => $query->where('kind', 'finish'))
            ->distinct('entry_id')
            ->count('entry_id');

        return Inertia::render('Races/Show', [
            'race' => $race,
            'organizers' => $organizers,
            'participants' => $participants,
            'recentTimings' => $recentTimings,
            'completedCount' => $completedCount,
            'serverNow' => now('UTC')->toISOString(),
        ]);
    }

    public function update(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        if ($race->started_at) {
            throw ValidationException::withMessages([
                'race' => 'Race setup is locked after the race starts.',
            ]);
        }

        $allowedStatuses = [RaceStatus::Draft->value, RaceStatus::Ready->value];
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'event_date' => ['required', 'date'], 'timezone' => ['required', 'timezone'], 'status' => ['required', Rule::in($allowedStatuses)], 'swim_km'=>['sometimes','numeric','min:0.001'],'bike_km'=>['sometimes','numeric','min:0.001'],'run_km'=>['sometimes','numeric','min:0.001'],'auto_finish' => ['sometimes','boolean'], 'organizer_ids' => ['nullable', 'array'], 'organizer_ids.*' => ['integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['admin', 'organizer'])->where('is_active', true))]]);
        $settings=$race->settings??[];
        foreach(['swim_km'=>'SWIM_FINISH','bike_km'=>'BIKE_FINISH','run_km'=>'RUN_FINISH'] as $key=>$code){
            if(!array_key_exists($key,$data))continue;
            $settings[$key]=$data[$key];
        }
        if(array_key_exists('auto_finish',$data))$settings['auto_finish']=(bool)$data['auto_finish'];
        DB::transaction(function()use($race,$data,$settings){
            $race->update([...collect($data)->except(['organizer_ids','swim_km','bike_km','run_km','auto_finish'])->all(),'settings'=>$settings]);
            foreach(['swim_km'=>'SWIM_FINISH','bike_km'=>'BIKE_FINISH','run_km'=>'RUN_FINISH'] as $key=>$code)if(array_key_exists($key,$data))$race->checkpoints()->where('code',$code)->update(['distance_km'=>$data[$key]]);
        });
        if ($request->user()->isAdmin() && array_key_exists('organizer_ids', $data)) $race->organizers()->sync($data['organizer_ids'] ?: [$request->user()->id]);
        return back()->with('success', 'Race settings updated.');
    }

    public function destroy(Request $request, Race $race): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $race->delete();
        return redirect()->route('races.index')->with('success', 'Race moved to Deleted races. Its participants and timings are preserved and can be restored.');
    }

    public function restore(Request $request, Race $race): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $race->restore();
        return redirect()->route('races.show', $race)->with('success', 'Race restored.');
    }
}
