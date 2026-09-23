<?php
namespace Tests\Feature;

use App\Enums\RaceStatus;
use App\Enums\UserRole;
use App\Models\Race;
use App\Models\User;
use App\Services\RaceClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RaceClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_timestamps_include_utc_and_finish_is_stable(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Clock', 'slug' => 'clock', 'event_date' => '2026-09-21', 'timezone' => 'Europe/Brussels', 'created_by' => $admin->id]);
        $this->makeStartable($race);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-21T12:00:00.123Z'));
        app(RaceClockService::class)->start($race);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-21T12:05:10.456Z'));
        $finished = app(RaceClockService::class)->finish($race->fresh());
        $serialized = $finished->toArray();
        $this->assertSame('2026-09-21T12:00:00.123000Z', $serialized['started_at']);
        $this->assertSame('2026-09-21T12:05:10.456000Z', $serialized['finished_at']);
        $this->travel(2)->hours();
        $again = app(RaceClockService::class)->finish($finished);
        $this->assertSame($serialized['finished_at'], $again->toArray()['finished_at']);
        $this->actingAs($admin)->get("/races/{$race->id}/control")->assertInertia(fn (Assert $page) => $page
            ->where('race.started_at', $serialized['started_at'])
            ->where('race.finished_at', $serialized['finished_at']));
        $this->travelBack();
    }

    public function test_station_presence_returns_the_authoritative_finish_timestamp(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Finished', 'slug' => 'finished', 'event_date' => '2026-09-22', 'timezone' => 'Europe/Brussels', 'created_by' => $admin->id, 'status' => RaceStatus::Finished, 'started_at' => '2026-09-22 12:00:00.123', 'finished_at' => '2026-09-22 12:10:00.456']);
        $checkpoint = $race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'sequence' => 30, 'kind' => 'finish']);
        $this->actingAs($admin)->postJson("/races/{$race->id}/presence", [
            'device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'checkpoint_id' => $checkpoint->id, 'pending_count' => 0,
        ])->assertOk()->assertJsonPath('finished_at', '2026-09-22T12:10:00.456000Z')->assertJsonPath('status', 'finished');
    }

    public function test_incomplete_race_cannot_start_and_lock_setup(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name'=>'Incomplete','slug'=>'incomplete','event_date'=>'2026-10-01','timezone'=>'Europe/Brussels','status'=>RaceStatus::Ready,'created_by'=>$admin->id]);

        try {
            app(RaceClockService::class)->start($race);
            $this->fail('Race without athletes should not start.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('race', $e->errors());
        }
        $this->assertNull($race->fresh()->started_at);

        $race->entries()->create(['type'=>'solo']);
        try {
            app(RaceClockService::class)->start($race);
            $this->fail('Race without a finish checkpoint should not start.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('race', $e->errors());
        }
        $this->assertNull($race->fresh()->started_at);
    }

    public function test_race_start_sets_one_authoritative_timestamp(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name'=>'Test Triathlon','slug'=>'test-triathlon','event_date'=>'2026-10-01','timezone'=>'Europe/Brussels','status'=>RaceStatus::Ready,'created_by'=>$admin->id]);
        $this->makeStartable($race);
        $started = app(RaceClockService::class)->start($race);
        $this->assertSame(RaceStatus::Running, $started->status);
        $this->assertNotNull($started->started_at);
        $this->expectException(ValidationException::class);
        app(RaceClockService::class)->start($started);
    }
    private function makeStartable(Race $race): void
    {
        $race->entries()->create(['type'=>'solo']);
        $race->checkpoints()->create([
            'name'=>'Finish',
            'code'=>'FINISH',
            'sequence'=>50,
            'kind'=>'finish',
            'is_active'=>true,
            'is_required'=>true,
        ]);
    }
}
