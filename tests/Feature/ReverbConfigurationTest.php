<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReverbConfigurationTest extends TestCase
{
    public function test_url_separators_in_a_reverb_key_are_rejected_without_exposing_credentials(): void
    {
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'base64:key/that/breaks/the/route=']);
        $this->artisan('timing:check-reverb')
            ->expectsOutput('REVERB_APP_KEY must contain only letters, digits, underscores or hyphens. It is a WebSocket URL identifier, not a base64 Laravel APP_KEY.')
            ->assertFailed();
    }

    public function test_independent_credentials_with_a_url_safe_key_pass(): void
    {
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'triathlon_123-test', 'reverb.apps.apps.0.secret' => 'independent-test-secret', 'reverb.apps.apps.0.app_id' => 'test-race']);
        $this->artisan('timing:check-reverb')->assertSuccessful();
    }
}
