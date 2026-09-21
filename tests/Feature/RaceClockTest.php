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

    public function test_race_start_sets_one_authoritative_timestamp(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name'=>'Test Triathlon','slug'=>'test-triathlon','event_date'=>'2026-10-01','timezone'=>'Europe/Brussels','status'=>RaceStatus::Ready,'created_by'=>$admin->id]);
        $started = app(RaceClockService::class)->start($race);
        $this->assertSame(RaceStatus::Running, $started->status);
        $this->assertNotNull($started->started_at);
        $this->expectException(ValidationException::class);
        app(RaceClockService::class)->start($started);
    }
}
