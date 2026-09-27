<?php

namespace Tests\Feature;

use App\Models\Race;
use App\Models\User;
use App\Services\ResultsPdfService;
use App\Services\ResultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResultsPdfTest extends TestCase
{
    use RefreshDatabase;

    private function race(): Race
    {
        return Race::create([
            'name' => 'Halle Triathlon', 'slug' => 'halle-triathlon',
            'event_date' => '2026-09-27', 'timezone' => 'Europe/Brussels',
            'created_by' => User::factory()->create(['role' => 'admin'])->id,
            'public_results_token' => 'published-race', 'results_published_at' => now(),
        ]);
    }

    public function test_guests_can_download_complete_landscape_pdf_despite_screen_filters(): void
    {
        $race = $this->race();
        for ($index = 1; $index <= 5; $index++) {
            $race->checkpoints()->create(['name' => 'Checkpoint '.$index, 'code' => 'CP'.$index, 'kind' => 'split', 'sequence' => $index]);
        }
        $this->mock(ResultsService::class, function ($mock) use ($race) {
            // No second (filter) argument may be supplied to the export query.
            $mock->shouldReceive('filtered')->once()->withArgs(fn (...$args) => count($args) === 1 && $args[0]->is($race))->andReturn([]);
        });

        $response = $this->get('/live/published-race/results.pdf?type=solo&category=Masters&status=DNS');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="halle-triathlon-results.pdf"')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0\.000 0\.000 841\.890 595\.280\]/', $response->getContent());
    }

    public function test_pdf_is_revoked_when_unpublished_or_deleted_and_tokens_are_scoped(): void
    {
        $race = $this->race();
        $this->get('/live/unknown/results.pdf')->assertNotFound();
        $this->get('/live/'.$race->public_timing_token.'/results.pdf')->assertNotFound();
        $race->update(['results_published_at' => null]);
        $this->get('/live/published-race/results.pdf')->assertNotFound();
        $race->update(['results_published_at' => now()]);
        $race->delete();
        $this->get('/live/published-race/results.pdf')->assertNotFound();
    }

    public function test_internal_export_requires_export_permission_and_race_access(): void
    {
        $race = $this->race();
        $this->get("/races/$race->id/results.pdf")->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'athlete']))->get("/races/$race->id/results.pdf")->assertForbidden();
        $official = User::factory()->create(['role' => 'organizer']);
        $this->actingAs($official)->get("/races/$race->id/results.pdf")->assertForbidden();
        $race->staff()->attach($official);
        $role = \App\Models\AccessRole::create(['name' => 'No exports', 'permissions' => []]);
        $official->update(['access_role_id' => $role->id]);
        $this->get("/races/$race->id/results.pdf")->assertForbidden();
        $this->actingAs($race->creator)->get("/races/$race->id/results.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_public_results_offer_a_pdf_link_and_wide_tables_keep_all_columns_on_each_page(): void
    {
        $race = $this->race();
        for ($index = 1; $index <= 12; $index++) {
            $race->checkpoints()->create(['name' => 'Checkpoint '.$index, 'code' => 'CP'.$index, 'kind' => 'split', 'sequence' => $index]);
        }
        for ($index = 1; $index <= 45; $index++) {
            $race->entries()->create(['type' => 'relay', 'team_name' => 'Team '.$index, 'bib_number' => $index, 'status' => 'dnf']);
        }
        $this->get('/live/published-race')->assertInertia(fn (Assert $page) => $page
            ->where('pdfUrl', '/live/published-race/results.pdf')->has('results', 45));
        $response = $this->get('/live/published-race/results.pdf')->assertOk();
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0\.000 0\.000 1400\.000 595\.280\]/', $response->getContent());
        preg_match('/\/Type \/Pages\s.*?\/Count (\d+)/s', $response->getContent(), $matches);
        $this->assertGreaterThan(1, (int) ($matches[1] ?? 0));
    }

    public function test_pdf_view_preserves_names_statuses_splits_and_missing_checkpoints_in_dutch(): void
    {
        $race = $this->race();
        $race->entries()->create(['type' => 'relay', 'team_name' => 'Élodie & <Team>', 'status' => 'dns']);
        $race->checkpoints()->create(['name' => 'Finish', 'code' => 'FINISH', 'kind' => 'finish', 'sequence' => 1]);
        app()->setLocale('nl');
        $rows = app(ResultsService::class)->filtered($race);
        $html = view('results.pdf', [
            'tableWidth' => 801.89, 'race' => $race, 'rows' => $rows, 'checkpoints' => $race->checkpoints,
            'duration' => ResultsPdfService::formatDuration(...), 'generatedAt' => '27/09/2026 13:00 CEST',
        ])->render();
        $this->assertStringContainsString('Volledige uitslag', $html);
        $this->assertStringContainsString('Élodie &amp; &lt;Team&gt;', $html);
        $this->assertStringContainsString('DNS', $html);
        $this->assertStringNotContainsString('00:00:00.000', $html);
        $this->assertSame('01:02:03.004', ResultsPdfService::formatDuration(3723004));
        $this->assertSame('-', ResultsPdfService::formatDuration(null));
    }
}
