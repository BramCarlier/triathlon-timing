<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Entry;
use App\Models\EntryChange;
use App\Models\Race;
use App\Models\User;
use App\Services\ResultsService;
use App\Services\TimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AthleteEditingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $race = Race::create(['name' => 'Bib editing', 'slug' => 'bib-editing', 'event_date' => '2026-09-27', 'created_by' => $admin->id]);
        $athlete = Athlete::create(['first_name' => 'Alex', 'last_name' => '']);
        $entry = $race->entries()->create(['type' => 'solo']);
        foreach (['swim', 'bike', 'run'] as $index => $discipline) {
            $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
        }

        return [$admin, $race, $entry, $athlete];
    }

    public function test_first_name_only_athlete_can_receive_change_and_clear_a_bib(): void
    {
        [$admin, $race, $entry, $athlete] = $this->fixture();
        $otherRace = Race::create(['name' => 'Other race', 'slug' => 'other-race', 'event_date' => '2026-10-01', 'created_by' => $admin->id]);
        $otherEntry = $otherRace->entries()->create(['type' => 'solo', 'bib_number' => '007']);
        $otherEntry->members()->create(['athlete_id' => $athlete->id, 'discipline' => 'swim', 'position' => 1]);
        $memberships = $entry->members()->pluck('id')->all();

        foreach (['007', '042', null] as $bib) {
            $this->put("/races/{$race->id}/participants/{$entry->id}", [
                'bib_number' => $bib,
                'status' => 'registered',
                'athletes' => [['id' => $athlete->id, 'first_name' => 'Alex', 'last_name' => '']],
            ])->assertSessionHasNoErrors();
            $this->assertSame($bib, $entry->fresh()->bib_number);
            $this->assertSame('', $athlete->fresh()->last_name);
        }

        $this->assertSame('007', $otherEntry->fresh()->bib_number);
        $this->assertSame($memberships, $entry->members()->pluck('id')->all());
        $this->assertDatabaseCount('athletes', 1);
        $this->assertDatabaseCount('entry_changes', 3);
        $this->assertSame('007', EntryChange::orderBy('id')->first()->after['entry']['bib_number']);
    }

    public function test_bib_edits_during_and_after_a_race_preserve_recorded_times(): void
    {
        Event::fake();
        [$admin, $race, $entry, $athlete] = $this->fixture();
        $race->update(['started_at' => now()->subHour(), 'status' => 'running']);
        $finish = $race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'kind' => 'finish', 'sequence' => 30]);
        app(TimingService::class)->correct($race, $entry, $finish, $admin, 120000);
        $timings = $entry->timings()->get()->toArray();

        foreach ([false, true] as $finished) {
            $race->update(['finished_at' => $finished ? now() : null, 'status' => $finished ? 'finished' : 'running']);
            $bib = $finished ? '042' : '007';
            $this->put("/races/{$race->id}/participants/{$entry->id}", [
                'bib_number' => $bib, 'status' => 'registered', 'reason' => 'Bib corrected at check-in',
                'athletes' => [['id' => $athlete->id, 'first_name' => 'Ignored']],
            ])->assertSessionHasNoErrors();
            $this->assertSame($bib, $entry->fresh()->bib_number);
            $this->assertSame('Alex', $athlete->fresh()->first_name);
            $this->assertSame($timings, $entry->timings()->get()->toArray());
            $result = app(ResultsService::class)->rows($race)[0];
            $this->assertSame($bib, $result['bib_number']);
            $this->assertSame(120000, $result['total_ms']);
        }

        $change = EntryChange::latest('id')->first();
        $this->assertSame('007', $change->before['entry']['bib_number']);
        $this->assertSame('042', $change->after['entry']['bib_number']);
    }

    public function test_duplicate_bibs_are_rejected_before_and_after_start(): void
    {
        [, $race, $entry, $athlete] = $this->fixture();
        $race->entries()->create(['type' => 'solo', 'bib_number' => '007']);
        foreach ([false, true] as $started) {
            $race->update(['started_at' => $started ? now() : null]);
            $this->put("/races/{$race->id}/participants/{$entry->id}", [
                'bib_number' => '007', 'status' => 'registered', 'reason' => 'Assign bib',
                'athletes' => [$athlete->only(['id', 'first_name', 'last_name'])],
            ])->assertSessionHasErrors('bib_number');
            $this->assertNull($entry->fresh()->bib_number);
        }
        $this->assertDatabaseCount('entry_changes', 0);
    }

    public function test_status_only_correction_keeps_the_existing_bib(): void
    {
        [, $race, $entry] = $this->fixture();
        $race->update(['started_at' => now()]);
        $entry->update(['bib_number' => '007']);
        $this->put("/races/{$race->id}/participants/{$entry->id}", [
            'status' => 'dnf', 'reason' => 'Withdrew during bike',
        ])->assertSessionHasNoErrors();
        $this->assertSame('007', $entry->fresh()->bib_number);
        $this->assertSame('dnf', $entry->fresh()->status);
    }

    public function test_an_entry_cannot_be_edited_through_another_race(): void
    {
        [$admin, $race, $entry] = $this->fixture();
        $otherRace = Race::create(['name' => 'Other race', 'slug' => 'other-race', 'event_date' => '2026-10-01', 'created_by' => $admin->id]);
        $this->put("/races/{$otherRace->id}/participants/{$entry->id}", [
            'bib_number' => '007', 'status' => 'registered', 'reason' => 'Assign bib',
        ])->assertNotFound();
        $this->assertNull($entry->fresh()->bib_number);
    }
}
