<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlashSurvivesBackgroundRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_background_refresh_does_not_consume_a_message_that_has_not_been_shown(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create(['name' => 'Flash Race', 'slug' => 'flash-race', 'event_date' => '2026-11-13', 'status' => 'draft', 'created_by' => $admin->id]);
        $race->staff()->attach($admin);
        $this->actingAs($admin);

        $version = $this->get("/races/{$race->id}")->viewData('page')['version'];
        $refresh = fn () => $this->get("/races/{$race->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Races/Show',
            'X-Inertia-Partial-Data' => 'race,serverNow',
        ])->assertOk();

        $this->put("/races/{$race->id}/race-day-link", ['enabled' => false])->assertRedirect();

        // A background refresh reaches the server before the redirected page does.
        $refresh();
        $refresh();

        $this->get("/races/{$race->id}")->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Race-day link switched off. Devices using it can no longer record times.'));

        // Once shown, the message is gone: later refreshes and visits do not bring it back.
        $refresh();
        $this->get("/races/{$race->id}")->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
    }
}
