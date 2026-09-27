<?php

namespace Tests\Feature;

use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_race_and_results_refreshes_do_not_block_login(): void
    {
        $user = User::factory()->create();
        $race = Race::create([
            'name' => 'Login regression', 'slug' => 'login-regression',
            'event_date' => today(), 'created_by' => $user->id,
            'public_results_token' => 'login-results', 'results_published_at' => now(),
        ]);
        for ($i = 0; $i < 12; $i++) {
            $this->get('/race/'.$race->public_timing_token)->assertOk();
            $this->get('/live/login-results')->assertOk();
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_attempts_are_limited_case_insensitively_with_a_dutch_retry_message_and_expire(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $i % 2 ? strtoupper($user->email) : $user->email,
                'password' => 'wrong-password',
            ])->assertRedirect('/login')->assertSessionHasErrors('email');
        }

        $this->withSession(['locale' => 'nl'])->from('/login')->post('/login', [
            'email' => $user->email, 'password' => 'password-password',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'email' => 'Te veel aanmeldpogingen. Probeer het over 60 seconden opnieuw.',
        ]);
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_attempts_do_not_block_another_account_on_the_same_network(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_previous_failures_and_does_not_consume_attempts(): void
    {
        $user = User::factory()->create();
        for ($cycle = 0; $cycle < 2; $cycle++) {
            for ($i = 0; $i < 9; $i++) {
                $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                    ->assertSessionHasErrors('email');
            }
            $this->post('/login', ['email' => $user->email, 'password' => 'password-password'])
                ->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/login');
        }
    }

    public function test_inactive_users_cannot_log_in_with_a_correct_password(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
