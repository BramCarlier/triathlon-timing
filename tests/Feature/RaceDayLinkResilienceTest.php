<?php

namespace Tests\Feature;

use App\Enums\EntryStatus;
use App\Enums\TimingStatus;
use App\Enums\UserRole;
use App\Events\TimingRecorded;
use App\Models\Athlete;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use App\Models\User;
use App\Services\ResultsService;
use App\Services\TimingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RaceDayLinkResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Race $race;

    private Checkpoint $swim;

    private Checkpoint $finish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->race = Race::create([
            'name' => 'Link Race', 'slug' => 'link-race', 'event_date' => '2026-11-12',
            'started_at' => now()->subHour(), 'status' => 'running', 'created_by' => $this->admin->id,
        ]);
        $this->race->staff()->attach($this->admin);
        $this->swim = $this->race->checkpoints()->create(['name' => 'Swim Exit', 'code' => 'SWIM', 'sequence' => 10, 'kind' => 'transition', 'discipline' => 'swim', 'is_required' => true, 'is_active' => true]);
        $this->finish = $this->race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'sequence' => 50, 'kind' => 'finish', 'discipline' => 'run', 'is_required' => true, 'is_active' => true]);
    }

    private function entry(string $name, ?string $bib = null): Entry
    {
        $athlete = Athlete::create(['first_name' => $name, 'last_name' => 'Tester']);
        $entry = $this->race->entries()->create(['type' => 'solo', 'bib_number' => $bib]);
        foreach (['swim', 'bike', 'run'] as $position => $discipline) {
            $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $position + 1]);
        }

        return $entry;
    }

    private function tap(Entry $entry, Checkpoint $checkpoint, array $extra = [], ?string $token = null)
    {
        return $this->postJson('/race/'.($token ?? $this->race->public_timing_token).'/timings', [
            'entry_id' => $entry->id, 'checkpoint_id' => $checkpoint->id, 'client_uuid' => (string) Str::uuid(), ...$extra,
        ]);
    }

    public function test_a_tap_saved_on_the_device_keeps_its_observed_time_when_uploaded_later(): void
    {
        $entry = $this->entry('Queued');
        $observed = now('UTC')->subMinutes(4);

        $this->tap($entry, $this->swim, ['source' => 'offline', 'observed_at' => $observed->toISOString()])->assertOk();

        $timing = TimingRecord::firstOrFail();
        $this->assertSame('offline', $timing->source->value);
        $this->assertNull($timing->operator_id);
        $expected = $observed->getTimestampMs() - $this->race->started_at->getTimestampMs();
        $this->assertEqualsWithDelta($expected, $timing->elapsed_ms, 1);
    }

    public function test_online_taps_still_ignore_device_clocks_that_are_far_off(): void
    {
        $entry = $this->entry('Skewed');
        $this->tap($entry, $this->swim, ['observed_at' => now('UTC')->subMinutes(4)->toISOString()])->assertOk();

        $timing = TimingRecord::firstOrFail();
        $this->assertGreaterThan(59 * 60 * 1000, $timing->elapsed_ms, 'A 4-minute-old online timestamp is replaced by the server time.');
    }

    public function test_saved_taps_older_than_the_upload_window_are_refused(): void
    {
        $entry = $this->entry('Stale');
        $this->tap($entry, $this->swim, ['source' => 'offline', 'observed_at' => now('UTC')->subMinutes(31)->toISOString()])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);
        $this->assertSame(0, TimingRecord::count());
    }

    public function test_the_tapping_device_can_undo_within_the_window_and_only_with_its_identifier(): void
    {
        $entry = $this->entry('Undo');
        $uuid = (string) Str::uuid();
        $token = $this->race->public_timing_token;
        $this->tap($entry, $this->swim, ['client_uuid' => $uuid])->assertOk()->assertJsonStructure(['undo_until']);

        $this->postJson("/race/{$token}/timings/".Str::uuid().'/undo')->assertNotFound();
        $this->postJson("/race/{$token}/timings/{$uuid}/undo")->assertOk();
        $this->assertSame(TimingStatus::Voided, TimingRecord::firstOrFail()->status);

        // A new tap is possible after the undo.
        $this->tap($entry, $this->swim)->assertOk();
    }

    public function test_undo_is_refused_after_the_window(): void
    {
        $entry = $this->entry('Late');
        $uuid = (string) Str::uuid();
        $this->tap($entry, $this->swim, ['client_uuid' => $uuid])->assertOk();
        $this->travel(61)->seconds();

        $this->postJson("/race/{$this->race->public_timing_token}/timings/{$uuid}/undo")->assertStatus(409);
        $this->assertSame(TimingStatus::Recorded, TimingRecord::firstOrFail()->status);
    }

    public function test_undo_cannot_touch_station_timings(): void
    {
        $entry = $this->entry('Station');
        $uuid = (string) Str::uuid();
        app(TimingService::class)->record($this->race, $entry, $this->swim, $this->admin, ['client_uuid' => $uuid]);

        $this->postJson("/race/{$this->race->public_timing_token}/timings/{$uuid}/undo")->assertNotFound();
    }

    public function test_public_recent_timings_do_not_reveal_client_identifiers(): void
    {
        $entry = $this->entry('Private');
        $this->tap($entry, $this->swim)->assertOk();

        $this->get("/race/{$this->race->public_timing_token}")->assertInertia(fn (Assert $page) => $page
            ->has('recentTimings', 1)
            ->missing('recentTimings.0.client_uuid')
            ->missing('recentTimings.0.operator'));
    }

    public function test_organizer_can_switch_off_and_rotate_the_link(): void
    {
        $entry = $this->entry('Rotate');
        $old = $this->race->public_timing_token;

        $this->actingAs($this->admin)->put("/races/{$this->race->id}/race-day-link", ['enabled' => false])->assertSessionHasNoErrors();
        $this->tap($entry, $this->swim, [], $old)->assertForbidden();
        $this->get("/race/{$old}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('race.timing_enabled', false)
            ->has('participants', 0));

        $this->put("/races/{$this->race->id}/race-day-link", ['enabled' => true]);
        $this->post("/races/{$this->race->id}/race-day-link/rotate")->assertSessionHasNoErrors();
        $new = $this->race->fresh()->public_timing_token;

        $this->assertNotSame($old, $new);
        $this->assertNotNull($this->race->fresh()->public_timing_rotated_at);
        $this->get("/race/{$old}")->assertNotFound();
        $this->tap($entry, $this->swim, [], $new)->assertOk();
    }

    public function test_officials_cannot_control_the_link(): void
    {
        $official = User::factory()->create(['role' => UserRole::Official]);
        $this->race->staff()->attach($official);

        $this->actingAs($official)->post("/races/{$this->race->id}/race-day-link/rotate")->assertForbidden();
        $this->actingAs($official)->put("/races/{$this->race->id}/race-day-link", ['enabled' => false])->assertForbidden();
    }

    public function test_server_time_endpoint_reports_milliseconds(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $now = $this->getJson('/time')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('now_ms');
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThan($before + 5000, $now);
    }

    public function test_retrying_a_tap_does_not_broadcast_it_again(): void
    {
        $entry = $this->entry('Retry');
        Event::fake([TimingRecorded::class]);
        $uuid = (string) Str::uuid();

        $this->tap($entry, $this->swim, ['client_uuid' => $uuid])->assertOk();
        $this->tap($entry, $this->swim, ['client_uuid' => $uuid])->assertOk();

        Event::assertDispatchedTimes(TimingRecorded::class, 1);
    }

    public function test_the_database_refuses_a_second_active_timing_for_the_same_checkpoint(): void
    {
        $entry = $this->entry('Guard');
        $row = ['race_id' => $this->race->id, 'entry_id' => $entry->id, 'checkpoint_id' => $this->swim->id, 'recorded_at' => now(), 'elapsed_ms' => 1, 'source' => 'online'];
        TimingRecord::create([...$row, 'client_uuid' => (string) Str::uuid(), 'status' => 'voided']);
        TimingRecord::create([...$row, 'client_uuid' => (string) Str::uuid(), 'status' => 'voided']);
        TimingRecord::create([...$row, 'client_uuid' => (string) Str::uuid(), 'status' => 'recorded']);

        $this->expectException(QueryException::class);
        TimingRecord::create([...$row, 'client_uuid' => (string) Str::uuid(), 'status' => 'recorded']);
    }

    public function test_results_show_provisional_places_while_the_race_runs(): void
    {
        $leader = $this->entry('Leader', '1');
        $chaser = $this->entry('Chaser', '2');
        $resting = $this->entry('Resting', '3');
        app(TimingService::class)->correct($this->race, $chaser, $this->swim, $this->admin, 70_000);
        app(TimingService::class)->correct($this->race, $leader, $this->swim, $this->admin, 60_000);

        $rows = collect(app(ResultsService::class)->filtered($this->race->fresh()));

        $this->assertSame([$leader->id, $chaser->id, $resting->id], $rows->pluck('id')->all());
        $this->assertSame([1, 2, null], $rows->pluck('provisional_place')->all());
        $this->assertSame([null, null, null], $rows->pluck('place')->all());

        app(TimingService::class)->correct($this->race, $chaser, $this->finish, $this->admin, 3_000_000);
        $rows = collect(app(ResultsService::class)->filtered($this->race->fresh()));
        $this->assertSame($chaser->id, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['place']);
        $this->assertSame(2, $rows[1]['provisional_place']);
    }

    public function test_entries_sort_by_natural_bib_order_with_bibless_entries_first(): void
    {
        foreach (['10', '2', null, '1', '100'] as $bib) {
            $this->entry('Bib'.($bib ?? 'none'), $bib);
        }

        $this->assertSame([null, '1', '2', '10', '100'], $this->race->entries()->orderByBib()->pluck('bib_number')->all());
    }

    public function test_entry_status_is_an_enum(): void
    {
        $entry = $this->entry('Enum');
        $entry->update(['status' => 'dsq']);

        $this->assertSame(EntryStatus::Dsq, $entry->fresh()->status);
        $this->assertSame('DSQ', $entry->fresh()->status->resultLabel());
    }
}
