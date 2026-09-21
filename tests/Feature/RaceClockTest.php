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
use Tests\TestCase;

class RaceClockTest extends TestCase
{
    use RefreshDatabase;

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
