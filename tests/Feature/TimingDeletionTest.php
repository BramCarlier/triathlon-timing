<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\{Athlete, Entry, Race, User};
use App\Services\{ResultsService, TimingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimingDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $race = Race::create(['name' => 'Deletion', 'slug' => 'deletion', 'event_date' => '2026-09-27', 'started_at' => now()->subHour(), 'created_by' => $admin->id]);
        $athlete = Athlete::create(['first_name' => 'Test', 'last_name' => 'Runner']);
        $entry = $race->entries()->create(['type' => 'solo']);
        $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => 'swim', 'position' => 1]);
        $swim = $race->checkpoints()->create(['name' => 'Swim', 'code' => 'SWIM', 'kind' => 'transition', 'sequence' => 10]);
        $finish = $race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'kind' => 'finish', 'sequence' => 20]);
        $service = app(TimingService::class);
        $first = $service->correct($race, $entry, $swim, $admin, 10000);
        $last = $service->correct($race, $entry, $finish, $admin, 20000);
        return compact('admin', 'race', 'athlete', 'entry', 'swim', 'finish', 'first', 'last');
    }

    public function test_delete_removes_only_selected_time_and_updates_results_without_reopening_clock(): void
    {
        extract($this->fixture());
        $race->update(['finished_at' => now()]);
        $finishedAt = $race->fresh()->finished_at;
        $this->post("/races/$race->id/timings/$last->id/void")->assertSessionHasNoErrors();
        $this->assertSame('voided', $last->fresh()->status->value);
        $this->assertSame($admin->id, $last->fresh()->voided_by);
        $this->assertSame('recorded', $first->fresh()->status->value);
        $row = app(ResultsService::class)->rows($race)[0];
        $this->assertNull($row['total_ms']);
        $this->assertSame(10000, $row['splits'][0]['elapsed_ms']);
        $this->assertEquals($finishedAt, $race->fresh()->finished_at);
        $notes = $last->fresh()->notes;
        $this->postJson("/races/$race->id/timings/$last->id/void")->assertOk();
        $this->assertSame($notes, $last->fresh()->notes);
    }

    public function test_dns_clears_all_times_only_for_this_entry_and_does_not_restore_them(): void
    {
        extract($this->fixture());
        $other = $race->entries()->create(['type' => 'solo']);
        $otherTime = app(TimingService::class)->correct($race, $other, $swim, $admin, 15000);
        $otherRace = Race::create(['name' => 'Other', 'slug' => 'other', 'event_date' => '2026-09-28', 'started_at' => now()->subHour(), 'created_by' => $admin->id]);
        $otherEntry = $otherRace->entries()->create(['type' => 'solo']);
        $otherEntry->members()->create(['athlete_id' => $athlete->id, 'discipline' => 'swim', 'position' => 1]);
        $otherCheckpoint = $otherRace->checkpoints()->create(['name' => 'Swim', 'code' => 'SWIM', 'kind' => 'transition', 'sequence' => 10]);
        $otherRaceTime = app(TimingService::class)->correct($otherRace, $otherEntry, $otherCheckpoint, $admin, 16000);

        $this->put("/races/$race->id/participants/$entry->id", ['status' => 'dns'])->assertSessionHasNoErrors();
        $this->assertSame('dns', $entry->fresh()->status);
        $this->assertSame(0, $entry->timings()->where('status', 'recorded')->count());
        $this->assertSame(2, $entry->timings()->where('status', 'voided')->where('voided_by', $admin->id)->count());
        $this->assertSame('recorded', $otherTime->fresh()->status->value);
        $this->assertSame('recorded', $otherRaceTime->fresh()->status->value);
        $row = collect(app(ResultsService::class)->live($race))->firstWhere('id', $entry->id);
        $this->assertSame('DNS', $row['result_status']);
        $this->assertNull($row['latest_elapsed_ms']);
        $this->assertNull($row['place']);
        $this->post("/races/$race->id/corrections", ['entry_id' => $entry->id, 'checkpoint_id' => $swim->id, 'elapsed_ms' => 1000])->assertSessionHasErrors('entry_id');
        foreach (['online', 'offline'] as $source) {
            $this->postJson("/races/$race->id/timings", ['entry_id' => $entry->id, 'checkpoint_id' => $swim->id, 'operator_id' => $admin->id, 'client_uuid' => (string) Str::uuid(), 'source' => $source])->assertConflict();
        }
        $this->postJson("/races/$race->id/timings", ['entry_id' => $entry->id, 'checkpoint_id' => $swim->id, 'client_uuid' => $first->client_uuid, 'source' => 'online'])->assertConflict();
        $this->put("/races/$race->id/participants/$entry->id", ['status' => 'registered'])->assertSessionHasNoErrors();
        $this->assertSame(0, $entry->timings()->where('status', 'recorded')->count());
        $this->postJson("/races/$race->id/timings", ['entry_id' => $entry->id, 'checkpoint_id' => $swim->id, 'client_uuid' => (string) Str::uuid(), 'source' => 'online'])->assertOk();
    }

    public function test_dns_before_start_clears_legacy_times_and_dnf_dsq_keep_times(): void
    {
        extract($this->fixture());
        foreach (['dnf', 'dsq'] as $status) {
            $this->put("/races/$race->id/participants/$entry->id", ['status' => $status])->assertSessionHasNoErrors();
            $this->assertSame(2, $entry->timings()->where('status', 'recorded')->count());
        }
        $race->update(['started_at' => null]);
        $this->put("/races/$race->id/participants/$entry->id", ['status' => 'dns', 'athletes' => [$athlete->only(['id', 'first_name', 'last_name', 'email', 'club'])]])->assertSessionHasNoErrors();
        $this->assertSame(0, $entry->timings()->where('status', 'recorded')->count());
    }

    public function test_delete_enforces_race_and_operator_permissions(): void
    {
        extract($this->fixture());
        $otherRace = Race::create(['name' => 'Other', 'slug' => 'other', 'event_date' => '2026-09-28', 'created_by' => $admin->id]);
        $this->postJson("/races/$otherRace->id/timings/$first->id/void")->assertNotFound();
        $official = User::factory()->create(['role' => UserRole::Official]);
        $official->races()->attach($race);
        $this->actingAs($official)->postJson("/races/$race->id/timings/$first->id/void")->assertForbidden();
        $own = app(TimingService::class)->correct($race, $entry, $swim, $official, 12000);
        $this->postJson("/races/$race->id/timings/$own->id/void")->assertOk();
        $this->actingAs(User::factory()->create(['role' => UserRole::Athlete]))->postJson("/races/$race->id/timings/$last->id/void")->assertForbidden();
        $this->assertSame('recorded', $last->fresh()->status->value);
    }
}
