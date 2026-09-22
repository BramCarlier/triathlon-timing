<?php

namespace Tests\Feature;

use App\Mail\GmailApiTransport;
use App\Models\User;
use App\Services\AccountInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class GmailApiMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'gmail',
            'mail.from.address' => 'event@gmail.com',
            'services.gmail' => ['client_id'=>'test-client', 'client_secret'=>'test-secret', 'refresh_token'=>'test-refresh', 'sender'=>'event@gmail.com'],
        ]);
        Http::preventStrayRequests();
    }

    private function fakeDelivery(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token'=>'test-access', 'expires_in'=>3600]),
            'gmail.googleapis.com/*' => Http::response(['id'=>'message-123']),
        ]);
    }

    public function test_mail_transport_sends_mime_over_https_with_oauth_and_blind_recipients(): void
    {
        $this->fakeDelivery();
        Mail::html('<p>Hello race team</p>', function ($mail) {
            $mail->to('recipient@example.test')->bcc('blind@example.test')->subject('Race invitation')->attachData('race data','race.txt');
        });
        Http::assertSent(fn (Request $request) => $request->url()==='https://oauth2.googleapis.com/token'
            && $request['grant_type']==='refresh_token' && $request['refresh_token']==='test-refresh');
        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/messages/send')) return false;
            $this->assertSame('https://gmail.googleapis.com/gmail/v1/users/event%40gmail.com/messages/send', $request->url());
            $this->assertTrue($request->hasHeader('Authorization','Bearer test-access'));
            $this->assertDoesNotMatchRegularExpression('/[+=\/]/',$request['raw']);
            $mime=base64_decode(strtr($request['raw'],'-_','+/'));
            foreach (['recipient@example.test','Bcc: blind@example.test','Race invitation','Hello race team','race.txt'] as $text) $this->assertStringContainsString($text,$mime);
            return true;
        });
        Http::assertSentCount(2);
    }

    public function test_actual_invitation_and_forgot_password_use_gmail(): void
    {
        $this->fakeDelivery();
        $user=User::factory()->create(['force_password_change'=>true]);
        $this->assertTrue(app(AccountInvitationService::class)->send($user));
        $this->assertNotNull($user->fresh()->invitation_sent_at);
        // Use another user so the broker's reset throttle does not suppress delivery.
        $other=User::factory()->create();
        $this->post('/forgot-password',['email'=>$other->email])->assertSessionHas('success');
        Http::assertSentCount(4);
    }

    public function test_missing_configuration_and_sender_mismatch_disable_invites(): void
    {
        $this->assertTrue(app(AccountInvitationService::class)->configured());
        config(['services.gmail.refresh_token'=>'']);
        $this->assertFalse(app(AccountInvitationService::class)->configured());
        config(['services.gmail.refresh_token'=>'test-refresh','mail.from.address'=>'other@gmail.com']);
        $this->assertFalse(app(AccountInvitationService::class)->configured());
        $this->expectException(TransportException::class);
        Mail::raw('Test',fn ($mail)=>$mail->to('recipient@example.test')->subject('Test'));
    }

    public function test_revoked_authorization_reports_safe_diagnostic_and_sends_nothing(): void
    {
        Http::fake(['oauth2.googleapis.com/token'=>Http::response(['error'=>'invalid_grant','error_description'=>'SECRET MUST NOT APPEAR'],400)]);
        $this->artisan('timing:check-mail')->expectsOutput('Google authorization expired or was revoked. Reconnect the sending account.')->assertFailed();
        Http::assertSentCount(1);
        $user=User::factory()->create(['force_password_change'=>true]);
        $this->assertFalse(app(AccountInvitationService::class)->send($user));
        $this->assertNull($user->fresh()->invitation_sent_at);
    }

    public function test_quota_error_is_sanitized_and_is_not_retried(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test-access']),
            'gmail.googleapis.com/*'=>Http::response(['error'=>['message'=>'SECRET MUST NOT APPEAR']],429),
        ]);
        try {
            Mail::raw('Test',fn ($mail)=>$mail->to('recipient@example.test')->subject('Test'));
            $this->fail('Expected a transport error');
        } catch (TransportException $exception) {
            $this->assertSame('Google sending quota was reached. Try again later.',$exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(2);
    }

    public function test_diagnostic_refreshes_token_without_sending_email(): void
    {
        $this->fakeDelivery();
        $this->artisan('timing:check-mail')->expectsOutput('Google token refresh succeeded. No email was sent. Test an invitation to verify sender access and delivery.')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertInstanceOf(GmailApiTransport::class,Mail::mailer()->getSymfonyTransport());
    }
}
