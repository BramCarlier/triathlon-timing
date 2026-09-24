<?php
namespace Tests\Feature;

use App\Enums\CheckpointKind;
use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Enums\RaceStatus;
use App\Enums\UserRole;
use App\Exceptions\TimingConflictException;
use App\Exceptions\TimingWarningException;
use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Race;
use App\Models\User;
use App\Services\TimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TimingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupRace(): array
    {
        Event::fake();
        $operator = User::factory()->create(['role' => UserRole::Official]);
        $race = Race::create(['name'=>'Test','slug'=>'test','event_date'=>'2026-10-01','timezone'=>'Europe/Brussels','status'=>RaceStatus::Running,'started_at'=>now()->subHour(),'created_by'=>$operator->id]);
        $operator->races()->attach($race);
        $race->checkpoints()->create(['name'=>'Start','code'=>'START','sequence'=>0,'kind'=>CheckpointKind::Start,'is_required'=>true]);
        $swim = $race->checkpoints()->create(['name'=>'Swim Finish','code'=>'SWIM','sequence'=>10,'kind'=>CheckpointKind::Transition,'discipline'=>Discipline::Swim,'is_required'=>true]);
        $bike = $race->checkpoints()->create(['name'=>'Bike Finish','code'=>'BIKE','sequence'=>20,'kind'=>CheckpointKind::Transition,'discipline'=>Discipline::Bike,'is_required'=>true]);
        $athlete = Athlete::create(['first_name'=>'Jane','last_name'=>'Doe']);
        $entry = Entry::create(['race_id'=>$race->id,'bib_number'=>'101','type'=>EntryType::Solo]);
        foreach ([Discipline::Swim,Discipline::Bike,Discipline::Run] as $i=>$discipline) $entry->members()->create(['athlete_id'=>$athlete->id,'discipline'=>$discipline,'position'=>$i+1]);
        return compact('operator','race','swim','bike','entry','athlete');
    }

    public function test_checkpoint_progression_warns_if_required_prior_checkpoint_is_missing(): void
    {
        $data = $this->setupRace();
        $this->expectException(TimingWarningException::class);
        app(TimingService::class)->record($data['race'],$data['entry'],$data['bike'],$data['operator'],['client_uuid'=>(string)\Illuminate\Support\Str::uuid(),'source'=>'online','observed_at'=>now()->toISOString()]);
    }

    public function test_record_is_idempotent_by_client_uuid_and_attributes_leg_athlete(): void
    {
        $data = $this->setupRace();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $first = app(TimingService::class)->record($data['race'],$data['entry'],$data['swim'],$data['operator'],['client_uuid'=>$uuid,'source'=>'online','observed_at'=>now()->toISOString()]);
        $second = app(TimingService::class)->record($data['race'],$data['entry'],$data['swim'],$data['operator'],['client_uuid'=>$uuid,'source'=>'online','observed_at'=>now()->toISOString()]);
        $this->assertSame($first->id,$second->id);
        $this->assertSame($data['athlete']->id,$first->athlete_id);
    }

    public function test_same_checkpoint_cannot_be_recorded_twice_with_different_uuid(): void
    {
        $data = $this->setupRace();
        app(TimingService::class)->record($data['race'],$data['entry'],$data['swim'],$data['operator'],['client_uuid'=>(string)\Illuminate\Support\Str::uuid(),'source'=>'online','observed_at'=>now()->toISOString()]);
        $this->expectException(TimingConflictException::class);
        app(TimingService::class)->record($data['race'],$data['entry'],$data['swim'],$data['operator'],['client_uuid'=>(string)\Illuminate\Support\Str::uuid(),'source'=>'online','observed_at'=>now()->toISOString()]);
    }

    public function test_manual_correction_voids_previous_timing_and_keeps_audit_history(): void
    {
        $data = $this->setupRace();
        $service = app(TimingService::class);
        $original = $service->record($data['race'], $data['entry'], $data['swim'], $data['operator'], [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'source' => 'online',
            'observed_at' => now()->toISOString(),
        ]);

        $replacement = $service->correct(
            $data['race'],
            $data['entry'],
            $data['swim'],
            $data['operator'],
            1_500_123,
            'Race director verified backup stopwatch.'
        );

        $this->assertSame('voided', $original->fresh()->status->value);
        $this->assertSame('recorded', $replacement->status->value);
        $this->assertSame('manual', $replacement->source->value);
        $this->assertSame(1_500_123, $replacement->elapsed_ms);
        $this->assertDatabaseCount('timing_records', 2);
    }

    public function test_offline_timing_captured_before_finish_can_sync_after_race_is_finished(): void
    {
        $data = $this->setupRace();
        $capturedAt = now()->subSeconds(10);
        $data['race']->forceFill([
            'status' => RaceStatus::Finished,
            'finished_at' => now()->subSeconds(5),
        ])->save();

        $timing = app(TimingService::class)->record($data['race'], $data['entry'], $data['swim'], $data['operator'], [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'source' => 'offline',
            'observed_at' => $capturedAt->toISOString(),
        ]);

        $this->assertSame('offline', $timing->source->value);
        $this->assertSame('recorded', $timing->status->value);
    }

    public function test_a_request_identifier_cannot_be_reused_for_another_participant(): void
    {
        $data = $this->setupRace();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        app(TimingService::class)->record($data['race'], $data['entry'], $data['swim'], $data['operator'], ['client_uuid' => $uuid, 'source' => 'online']);
        $other = Entry::create(['race_id' => $data['race']->id, 'bib_number' => '102', 'type' => EntryType::Solo]);
        $this->expectException(TimingConflictException::class);
        app(TimingService::class)->record($data['race'], $other, $data['swim'], $data['operator'], ['client_uuid' => $uuid, 'source' => 'online']);
    }

    public function test_disabled_required_checkpoints_do_not_block_progression(): void
    {
        $data = $this->setupRace();
        $data['swim']->update(['is_active' => false]);
        $timing = app(TimingService::class)->record($data['race'], $data['entry'], $data['bike'], $data['operator'], ['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'source' => 'online']);
        $this->assertSame($data['bike']->id, $timing->checkpoint_id);
    }

    public function test_station_requests_cannot_bypass_clock_checks_with_manual_source(): void
    {
        $data = $this->setupRace();
        $this->actingAs($data['operator'])->postJson('/races/'.$data['race']->id.'/timings', [
            'entry_id' => $data['entry']->id, 'checkpoint_id' => $data['swim']->id,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'source' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

}
