<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\TimingConflictException;
use App\Models\{EntryChange, Race, User};
use App\Services\{RaceClockService, ResultsService, TimingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticDnfTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Event::fake([\App\Events\RaceFinished::class, \App\Events\TimingRecorded::class, \App\Events\TimingVoided::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Automatic DNF', 'slug' => 'automatic-dnf', 'event_date' => '2026-09-27', 'status' => 'running', 'started_at' => now()->subHour(), 'created_by' => $admin->id]);
        $swim = $race->checkpoints()->create(['name' => 'Swim', 'code' => 'SWIM', 'kind' => 'transition', 'sequence' => 10]);
        $finish = $race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'kind' => 'finish', 'sequence' => 20]);
        $entry = $race->entries()->create(['type' => 'solo', 'metadata' => ['custom' => 'preserved']]);
        return compact('admin', 'race', 'swim', 'finish', 'entry');
    }

    public function test_finishing_marks_only_competing_entries_without_an_active_finish_and_keeps_their_times(): void
    {
        extract($this->fixture());
        $service = app(TimingService::class);
        $swimTime = $service->correct($race, $entry, $swim, $admin, 10000);
        $finisher = $race->entries()->create(['type' => 'relay', 'team_name' => 'Finished trio']);
        $service->correct($race, $finisher, $finish, $admin, 20000);
        $untimed = $race->entries()->create(['type' => 'solo']);
        $voided = $race->entries()->create(['type' => 'solo']);
        $oldFinish = $service->correct($race, $voided, $finish, $admin, 21000);
        $service->void($oldFinish, $admin);
        $excluded = [];
        foreach (['dns', 'dnf', 'dsq'] as $status) $excluded[$status] = $race->entries()->create(['type' => 'solo', 'status' => $status]);
        $otherRace = Race::create(['name' => 'Other', 'slug' => 'other', 'event_date' => '2026-09-28', 'created_by' => $admin->id]);
        $otherEntry = $otherRace->entries()->create(['type' => 'solo']);

        $this->actingAs($admin)->post("/races/$race->id/finish")->assertSessionHasNoErrors();
        foreach ([$entry, $untimed, $voided] as $unfinished) {
            $this->assertTrue($unfinished->fresh()->isAutomaticDnf());
            $this->assertDatabaseHas('entry_changes', ['entry_id' => $unfinished->id, 'user_id' => $admin->id, 'reason' => 'Marked DNF automatically: race finished without a finish time.']);
        }
        $this->assertSame('preserved', $entry->fresh()->metadata['custom']);
        $this->assertSame('registered', $finisher->fresh()->status);
        foreach ($excluded as $status => $unchanged) {
            $this->assertSame($status, $unchanged->fresh()->status);
            $this->assertFalse($unchanged->fresh()->isAutomaticDnf());
        }
        $this->assertSame('registered', $otherEntry->fresh()->status);
        $this->assertSame('recorded', $swimTime->fresh()->status->value);
        $row = collect(app(ResultsService::class)->filtered($race->fresh()))->firstWhere('id', $entry->id);
        $this->assertSame('DNF', $row['result_status']);
        $this->assertSame(10000, $row['splits'][0]['elapsed_ms']);
        $this->assertNull($row['total_ms']);
        $finishedAt = $race->fresh()->finished_at;
        $this->post("/races/$race->id/finish")->assertSessionHasNoErrors();
        $this->assertSame(3, EntryChange::count());
        $this->assertEquals($finishedAt, $race->fresh()->finished_at);
    }

    public function test_a_finish_captured_offline_before_closing_restores_only_automatic_dnf(): void
    {
        extract($this->fixture());
        $capturedAt = now()->subSeconds(2);
        app(RaceClockService::class)->finish($race, $admin);
        $finishedAt = $race->fresh()->finished_at;
        $this->travel(10)->seconds();
        $timing = app(TimingService::class)->record($race, $entry, $finish, $admin, [
            'client_uuid' => (string) Str::uuid(), 'source' => 'offline', 'observed_at' => $capturedAt->toISOString(),
        ]);
        $this->assertSame('recorded', $timing->status->value);
        $this->assertSame('registered', $entry->fresh()->status);
        $this->assertArrayNotHasKey('automatic_dnf', $entry->fresh()->metadata);
        $this->assertSame('FINISHED', app(ResultsService::class)->filtered($race->fresh())[0]['result_status']);
        $this->assertEquals($finishedAt, $race->fresh()->finished_at);
        $this->assertSame(2, EntryChange::count());
    }

    public function test_late_offline_split_keeps_dnf_and_a_post_finish_observation_is_rejected(): void
    {
        extract($this->fixture());
        $capturedAt = now()->subSeconds(2);
        app(RaceClockService::class)->finish($race, $admin);
        $this->travel(10)->seconds();
        app(TimingService::class)->record($race, $entry, $swim, $admin, ['client_uuid' => (string) Str::uuid(), 'source' => 'offline', 'observed_at' => $capturedAt->toISOString()]);
        $this->assertTrue($entry->fresh()->isAutomaticDnf());
        $this->expectException(TimingConflictException::class);
        app(TimingService::class)->record($race, $entry, $finish, $admin, ['client_uuid' => (string) Str::uuid(), 'source' => 'offline', 'observed_at' => now()->toISOString()]);
    }

    public function test_finish_correction_restores_automatic_dnf_but_preserves_explicit_dnf(): void
    {
        extract($this->fixture());
        $manualDnf = $race->entries()->create(['type' => 'solo', 'status' => 'dnf']);
        app(RaceClockService::class)->finish($race, $admin);
        app(TimingService::class)->correct($race, $entry, $finish, $admin, 20000);
        app(TimingService::class)->correct($race, $manualDnf, $finish, $admin, 21000);
        $this->assertSame('registered', $entry->fresh()->status);
        $this->assertSame('dnf', $manualDnf->fresh()->status);
    }

    public function test_explicit_status_changes_cancel_automatic_recovery(): void
    {
        extract($this->fixture());
        $capturedAt = now()->subSeconds(2);
        app(RaceClockService::class)->finish($race, $admin);
        $this->actingAs($admin)->put("/races/$race->id/participants/$entry->id", ['status' => 'registered'])->assertSessionHasNoErrors();
        $this->put("/races/$race->id/participants/$entry->id", ['status' => 'dnf'])->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->isAutomaticDnf());
        $this->expectException(TimingConflictException::class);
        app(TimingService::class)->record($race, $entry, $finish, $admin, ['client_uuid' => (string) Str::uuid(), 'source' => 'offline', 'observed_at' => $capturedAt->toISOString()]);
    }
}
