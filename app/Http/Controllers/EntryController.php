<?php
namespace App\Http\Controllers;

use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Models\Athlete;
use App\Models\Entry;
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
            'bib_number' => ['required','string','max:32', Rule::unique('entries')->where('race_id', $race->id)],
            'type' => ['required', Rule::enum(EntryType::class)],
            'team_name' => ['nullable','string','max:255','required_if:type,relay'],
            'category' => ['nullable','string','max:100'],
            'members' => ['required','array'],
            'members.*.discipline' => ['required', Rule::enum(Discipline::class)],
            'members.*.first_name' => ['required','string','max:100'],
            'members.*.last_name' => ['required','string','max:100'],
            'members.*.email' => ['nullable','email','max:255'],
            'members.*.club' => ['nullable','string','max:255'],
        ]);

        DB::transaction(function () use ($data, $race) {
            $entry = $race->entries()->create(['bib_number' => $data['bib_number'], 'type' => $data['type'], 'team_name' => $data['team_name'] ?? null, 'category' => $data['category'] ?? null]);
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

    public function destroy(Race $race, Entry $entry): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($entry->race_id === $race->id, 404);
        abort_if($entry->timings()->exists(), 422, 'Entries with timings cannot be deleted.');
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
