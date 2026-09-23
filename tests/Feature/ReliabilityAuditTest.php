<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Race;
use App\Models\User;
use App\Services\ParticipantImportService;
use App\Services\RaceClockService;
use App\Support\SpreadsheetText;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReliabilityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_outage_does_not_turn_a_saved_race_start_into_an_error(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Outage', 'slug' => 'outage', 'event_date' => '2026-09-22', 'created_by' => $user->id]);
        $race->entries()->create(['type'=>'solo']);
        $race->checkpoints()->create(['name'=>'Finish','code'=>'FINISH','sequence'=>50,'kind'=>'finish','is_active'=>true,'is_required'=>true]);
        Broadcast::shouldReceive('queue')->once()->andThrow(new BroadcastException('Simulated connection failure'));
        $started = app(RaceClockService::class)->start($race);
        $this->assertNotNull($started->started_at);
        $this->assertNotNull($race->fresh()->started_at);
    }

    public function test_malformed_json_rows_produce_validation_errors_instead_of_server_errors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import-');
        try {
            file_put_contents($path, '["not a participant object"]');
            $this->expectException(ValidationException::class);
            app(ParticipantImportService::class)->parse($path, 'bad.json');
        } finally { unlink($path); }
    }

    public function test_csv_text_cannot_be_interpreted_as_a_spreadsheet_formula(): void
    {
        foreach (['=1+1', '+cmd', '@SUM(A1)', '-1+2', '  =1+1', "\t=1+1"] as $text) {
            $this->assertSame("'".$text, SpreadsheetText::csv($text));
        }
        $this->assertSame('001', SpreadsheetText::csv('001'));
        $this->assertSame(123, SpreadsheetText::csv(123));
    }

    public function test_editing_an_administrator_preserves_race_assignments(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Race', 'slug' => 'race', 'event_date' => '2026-09-22', 'created_by' => $admin->id]);
        $admin->races()->attach($race);
        $this->actingAs($admin)->put('/users/'.$admin->id, ['name' => 'Updated Admin', 'email' => $admin->email, 'role' => 'admin', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($admin->races()->whereKey($race->id)->exists());
    }
}
