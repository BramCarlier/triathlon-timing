<?php
namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Race;
use App\Services\ParticipantImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantImportController extends Controller
{
    public function create(Race $race): Response|RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        if ($race->started_at) {
            return redirect()->route('races.show', $race)->with('error', 'Participant registration is locked after the race starts.');
        }
        return Inertia::render('Participants/Import', ['race' => $race]);
    }

    public function preview(Request $request, Race $race, ParticipantImportService $importer): Response
    {
        Gate::authorize('manage-race', $race);
        $this->ensureRegistrationOpen($race);
        $request->validate(['file' => ['required','file','max:10240','mimes:csv,txt,json,xlsx,xls,ods']]);
        $file = $request->file('file');
        $token = (string) Str::uuid();
        $path = $file->storeAs('imports', $token.'.'.$file->getClientOriginalExtension(), 'local');
        $rows = $importer->parse(Storage::disk('local')->path($path), $file->getClientOriginalName());
        $preview = $importer->preview($rows);
        $batch = ImportBatch::create(['token' => $token, 'race_id' => $race->id, 'user_id' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'stored_path' => $path, 'row_count' => count($rows), 'preview' => $preview, 'warnings' => $preview['warnings'], 'expires_at' => now()->addHours(6)]);
        return Inertia::render('Participants/Import', ['race' => $race, 'batch' => $batch]);
    }

    public function confirm(Request $request, Race $race, ImportBatch $batch, ParticipantImportService $importer): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $this->ensureRegistrationOpen($race);
        abort_unless($batch->race_id === $race->id && $batch->user_id === $request->user()->id && $batch->status === 'preview' && (!$batch->expires_at || $batch->expires_at->isFuture()), 404);
        $rows = $importer->parse(Storage::disk('local')->path($batch->stored_path), $batch->original_name);
        $result = $importer->import($race, $rows);
        $batch->update(['status' => 'imported']);
        Storage::disk('local')->delete($batch->stored_path);
        return redirect()->route('races.entries.index', $race)->with('success', "Imported {$result['entries']} entries and {$result['athletes']} new athletes.");
    }


    private function ensureRegistrationOpen(Race $race): void
    {
        if ($race->started_at) {
            throw ValidationException::withMessages([
                'race' => 'Participant registration is locked after the race starts.',
            ]);
        }
    }

    public function template()
    {
        $csv = "bib,type,team_name,first_name,last_name,email,discipline,category,club\n101,solo,,Jane,Doe,jane@example.com,,Open,Tri Club\n200,relay,Fast Three,Sam,Swimmer,sam@example.com,swim,Relay,\n200,relay,Fast Three,Ben,Biker,ben@example.com,bike,Relay,\n200,relay,Fast Three,Rae,Runner,rae@example.com,run,Relay,\n";
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="triathlon-participant-template.csv"']);
    }
}
