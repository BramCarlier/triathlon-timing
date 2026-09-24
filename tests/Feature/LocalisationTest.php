<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Race;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_locale_persists_and_unsupported_locales_are_rejected(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
        $this->from('/login')->post('/locale', ['locale'=>'nl'])
            ->assertRedirect('/login')->assertSessionHas('locale', 'nl')->assertCookie('locale', 'nl');
        $this->get('/login')->assertSee('lang="nl"', false)
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'nl'));
        $this->post('/locale', ['locale'=>'fr'])->assertSessionHasErrors('locale')->assertSessionHas('locale', 'nl');
    }

    public function test_cookie_restores_locale_without_a_session(): void
    {
        $this->withCookie('locale', 'nl')->get('/login')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'nl'));
    }

    public function test_password_setup_does_not_block_language_selection(): void
    {
        $user=User::factory()->create(['force_password_change'=>true]);
        $this->actingAs($user)->from('/account/password')->post('/locale', ['locale'=>'nl'])
            ->assertRedirect('/account/password')->assertSessionHas('locale', 'nl');
    }

    public function test_validation_authentication_and_reset_messages_are_dutch(): void
    {
        $this->withSession(['locale'=>'nl'])->post('/login', [])->assertSessionHasErrors([
            'email'=>'Het veld e-mailadres is verplicht.',
            'password'=>'Het veld wachtwoord is verplicht.',
        ]);
        $this->post('/login', ['email'=>'missing@example.test','password'=>'invalid'])
            ->assertSessionHasErrors(['email'=>'De ingevulde aanmeldgegevens zijn ongeldig.']);
        $this->post('/reset-password', ['token'=>'invalid','email'=>'missing@example.test','password'=>'long-enough-password','password_confirmation'=>'long-enough-password'])
            ->assertSessionHasErrors(['email'=>'We kunnen geen gebruiker met dat e-mailadres vinden.']);
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonPath('message', 'Het veld e-mailadres is verplicht. (en nog 1 fout)');
    }

    public function test_public_pages_errors_and_exports_use_selected_locale_without_changing_race_data(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $this->actingAs($admin)->withSession(['locale'=>'nl'])->post('/races', ['name'=>'Locale Race','timezone'=>'Europe/Brussels'])->assertRedirect();
        $race=Race::where('name','Locale Race')->firstOrFail();
        $this->assertEquals([1,35,8], array_map(fn ($key) => $race->settings[$key], ['swim_km','bike_km','run_km']));
        $this->assertSame('Swim Exit', $race->checkpoints()->where('code','SWIM_FINISH')->first()->name);
        $csv=$this->get("/races/{$race->id}/results.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Plaats', $csv);
        $this->assertStringContainsString('Zwemuitgang', $csv);
        $this->post('/logout');
        $race->update(['public_results_token'=>'locale-results','results_published_at'=>now()]);
        $this->withSession(['locale'=>'nl'])->get("/race/{$race->public_timing_token}")
            ->assertInertia(fn (Assert $page) => $page->where('locale','nl')->where('auth.user',null));
        $this->get('/live/locale-results')->assertInertia(fn (Assert $page) => $page->where('locale','nl')->where('publicMode',true));
        $this->get('/race/not-a-real-token')->assertNotFound()->assertSee('Niet gevonden');
        $this->get('/not-a-real-page')->assertNotFound()->assertSee('Niet gevonden');
    }

    public function test_invitation_and_password_reset_emails_are_translated(): void
    {
        app()->setLocale('nl');
        $user=User::factory()->create(['name'=>'Alex','role'=>UserRole::Official]);
        $invitation=(new AccountInvitation('token'))->toMail($user);
        $reset=(new ResetPassword('token'))->toMail($user);
        $this->assertSame('Je Triathlon Timing-account', $invitation->subject);
        $this->assertSame('Stel je wachtwoord opnieuw in', $reset->subject);
        $this->assertStringContainsString('verloopt over', implode(' ', $reset->outroLines));
    }

    public function test_static_backend_messages_have_dutch_translations(): void
    {
        $catalogue=json_decode(file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            preg_match_all("/\\b__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $this->assertArrayHasKey(str_replace(["\\'",'\\\\'], ["'",'\\'], $key), $catalogue, $file->getRelativePathname());
            }
        }
    }
}
