<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Race;
use App\Models\User;
use App\Services\ParticipantImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OptionalBibTest extends TestCase
{
    use RefreshDatabase;

    private function race(): Race
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        return Race::create(['name' => 'Test', 'slug' => 'test', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
    }

    public function test_manual_entries_can_omit_bibs_but_supplied_bibs_stay_unique(): void
    {
        $race = $this->race();
        $entry = ['type' => 'solo', 'members' => [['discipline' => 'swim', 'first_name' => 'No', 'last_name' => 'Bib']]];
        $this->post("/races/{$race->id}/participants", $entry)->assertSessionHasNoErrors();
        $this->post("/races/{$race->id}/participants", [...$entry, 'bib_number' => ''])->assertSessionHasNoErrors();
        $this->post("/races/{$race->id}/participants", [...$entry, 'bib_number' => '101'])->assertSessionHasNoErrors();
        $this->post("/races/{$race->id}/participants", [...$entry, 'bib_number' => '101'])->assertSessionHasErrors('bib_number');
        $this->assertSame(2, $race->entries()->whereNull('bib_number')->count());
        $this->get("/races/{$race->id}/station")->assertInertia(fn (Assert $page) => $page->has('participants', 3)->where('participants.0.name', 'No Bib')->where('participants.0.bib_number', null));
        $this->get("/races/{$race->id}/results.csv")->assertOk()->assertDownload();
    }

    public function test_import_keeps_separate_bibless_solos_and_groups_a_bibless_relay(): void
    {
        $race = $this->race();
        $rows = [
            ['first_name' => 'First', 'last_name' => 'Solo', 'type' => 'solo'],
            ['first_name' => 'Second', 'last_name' => 'Solo', 'type' => 'solo', 'bib' => ''],
            ['first_name' => 'Swim', 'last_name' => 'Member', 'type' => 'relay', 'team_name' => 'No Bib Team', 'discipline' => 'swim'],
            ['first_name' => 'Bike', 'last_name' => 'Member', 'type' => 'relay', 'team_name' => 'No Bib Team', 'discipline' => 'bike'],
            ['first_name' => 'Run', 'last_name' => 'Member', 'type' => 'relay', 'team_name' => 'No Bib Team', 'discipline' => 'run'],
        ];
        $service = app(ParticipantImportService::class);
        $preview = $service->preview($rows);
        $this->assertSame(3, $preview['entry_count']);
        $this->assertSame(2, $preview['solo_count']);
        $this->assertSame(1, $preview['relay_count']);
        $this->assertSame([], $preview['warnings']);
        $this->assertSame(['entries' => 3, 'athletes' => 5], $service->import($race, $rows));
        $this->assertSame(3, $race->entries()->whereNull('bib_number')->count());
        $this->assertSame(3, $race->entries()->where('type', 'relay')->first()->members()->count());
    }

    public function test_duplicate_solo_bibs_fail_atomically_instead_of_dropping_a_row(): void
    {
        $race = $this->race();
        try {
            app(ParticipantImportService::class)->import($race, [
                ['first_name' => 'One', 'last_name' => 'Solo'],
                ['first_name' => 'Two', 'last_name' => 'Solo', 'bib' => '101'],
                ['first_name' => 'Three', 'last_name' => 'Solo', 'bib' => '101'],
            ]);
            $this->fail('Duplicate bibs should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Duplicate solo entry', $exception->getMessage());
        }
        $this->assertDatabaseCount('entries', 0);
        $this->assertDatabaseCount('athletes', 0);
    }

    public function test_csv_without_a_bib_column_parses_and_imports_every_row(): void
    {
        $race = $this->race();
        $path = tempnam(sys_get_temp_dir(), 'triathlon-csv-');
        try {
            file_put_contents($path, "type,first_name,last_name\nsolo,First,Person\nsolo,Second,Person\n");
            $service = app(ParticipantImportService::class);
            $rows = $service->parse($path, 'participants.csv');
            $this->assertSame(['entries' => 2, 'athletes' => 2], $service->import($race, $rows));
        } finally {
            unlink($path);
        }
    }
}
