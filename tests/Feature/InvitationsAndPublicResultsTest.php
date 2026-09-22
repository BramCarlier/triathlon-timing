<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Race,Athlete};
use App\Notifications\AccountInvitation;
use App\Services\{AccountInvitationService,ResultsService,TimingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification,Password,Hash,Event};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
class InvitationsAndPublicResultsTest extends TestCase {
 use RefreshDatabase;
 private function admin():User { return User::factory()->create(['role'=>UserRole::Admin]); }
 public function test_invited_user_sets_password_once_and_can_then_sign_in():void {
  Notification::fake();config(['mail.default'=>'smtp']);$admin=$this->admin();
  $this->actingAs($admin)->post('/users',['name'=>'Invited','email'=>'INVITED@example.test','role'=>'organizer','delivery'=>'email'])->assertSessionHasNoErrors();
  $user=User::where('email','invited@example.test')->firstOrFail();$token=null;
  Notification::assertSentTo($user,AccountInvitation::class,function($notice)use(&$token,$user){$token=$notice->token;$this->assertStringContainsString('welcome=1',$notice->toMail($user)->actionUrl);return true;});
  $this->assertTrue($user->force_password_change);$this->assertNotNull($user->invitation_sent_at);
  $this->post('/logout');
  $payload=['token'=>$token,'email'=>$user->email,'password'=>'my-chosen-password-123','password_confirmation'=>'my-chosen-password-123'];
  $this->post('/reset-password',$payload)->assertRedirect('/login')->assertSessionHasNoErrors();
  $this->assertFalse($user->fresh()->force_password_change);$this->assertTrue(Hash::check($payload['password'],$user->fresh()->password));
  $this->post('/reset-password',$payload)->assertSessionHasErrors('email');
  $this->post('/login',['email'=>$user->email,'password'=>$payload['password']])->assertRedirect('/dashboard');
  $this->get('/races')->assertOk();
 }
 public function test_resending_invalidates_old_link_and_cannot_invite_disabled_users():void {
  Notification::fake();config(['mail.default'=>'smtp']);$admin=$this->admin();$user=User::factory()->create(['role'=>'organizer','force_password_change'=>true]);
  $old=Password::createToken($user);$this->actingAs($admin)->post("/users/$user->id/invitation")->assertSessionHas('success');
  $this->assertFalse(Password::tokenExists($user,$old));
  $user->update(['is_active'=>false]);$this->post("/users/$user->id/invitation")->assertUnprocessable();
  $this->actingAs(User::factory()->create(['role'=>'organizer']))->post("/users/$user->id/invitation")->assertForbidden();
 }
 public function test_invitations_expire_and_log_mail_is_not_claimed_as_delivery():void {
  config(['mail.default'=>'log']);$admin=$this->admin();$this->actingAs($admin)->post('/users',['name'=>'Invite','email'=>'invite@example.test','role'=>'organizer','delivery'=>'email'])->assertSessionHasErrors('delivery');
  $this->assertDatabaseMissing('users',['email'=>'invite@example.test']);
  $user=User::factory()->create(['force_password_change'=>true]);$token=Password::createToken($user);$this->travel(61)->minutes();$this->assertFalse(Password::tokenExists($user,$token));
 }
 public function test_password_reset_does_not_claim_log_delivery():void {
  config(['mail.default'=>'log']);
  $this->post('/forgot-password',['email'=>'someone@example.test'])->assertSessionHasErrors('email');
 }
 public function test_failed_invitation_keeps_account_for_retry_without_claiming_sent():void {
  config(['mail.default'=>'smtp']);Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP transport failed'));
  $this->actingAs($this->admin())->post('/users',['name'=>'Retry','email'=>'retry@example.test','role'=>'organizer','delivery'=>'email'])->assertSessionHas('error');
  $user=User::where('email','retry@example.test')->firstOrFail();$this->assertNull($user->invitation_sent_at);$this->assertTrue($user->force_password_change);
 }
 public function test_temporary_password_must_be_changed_and_cannot_be_reused():void {
  $user=User::factory()->create(['role'=>'organizer','password'=>Hash::make('temporary-password-123'),'force_password_change'=>true]);
  $this->actingAs($user)->get('/races')->assertRedirect('/account/password');
  $this->put('/account/password',['current_password'=>'temporary-password-123','password'=>'temporary-password-123','password_confirmation'=>'temporary-password-123'])->assertSessionHasErrors('password');
  $this->put('/account/password',['current_password'=>'temporary-password-123','password'=>'replacement-password-456','password_confirmation'=>'replacement-password-456'])->assertRedirect('/dashboard');
  $this->assertFalse($user->fresh()->force_password_change);
 }
 public function test_publication_is_opt_in_scoped_and_revocable_without_private_data():void {
  $admin=$this->admin();$race=Race::create(['name'=>'Public race','slug'=>'public-race','event_date'=>'2026-09-23','created_by'=>$admin->id]);
  $organizer=User::factory()->create(['role'=>'organizer']);
  $this->actingAs($organizer)->post("/races/$race->id/publication",['published'=>true])->assertForbidden();
  $organizer->races()->attach($race);$this->post("/races/$race->id/publication",['published'=>true])->assertSessionHasNoErrors();
  $token=$race->fresh()->public_results_token;$this->assertNotNull($token);
  $this->post('/logout');$this->get("/live/$token")->assertOk()->assertHeader('X-Robots-Tag','noindex, nofollow')->assertInertia(fn(Assert $page)=>$page->component('Results/Index')->where('publicMode',true)->missing('race.created_by')->missing('race.organizers')->missing('race.public_results_token')->where('auth.user',null));
  $this->get("/races/$race->id/results.csv")->assertRedirect('/login');
  $this->actingAs($admin)->post("/races/$race->id/publication",['published'=>false]);$this->post('/logout');$this->get("/live/$token")->assertNotFound();
  $this->actingAs($admin)->post("/races/$race->id/publication",['published'=>true]);$this->assertNotSame($token,$race->fresh()->public_results_token);
  $newToken=$race->fresh()->public_results_token;$race->delete();$this->post('/logout');$this->get("/live/$newToken")->assertNotFound();
 }
 public function test_gaps_respect_filtered_leader_ties_and_nonfinishers():void {
  Event::fake();$admin=$this->admin();$race=Race::create(['name'=>'Gaps','slug'=>'gaps','event_date'=>'2026-09-23','started_at'=>now()->subHour(),'created_by'=>$admin->id]);
  $finish=$race->checkpoints()->create(['name'=>'Finish','code'=>'FINISH','kind'=>'finish','sequence'=>50]);
  foreach([[10000,'Open','registered'],[10001,'Masters','registered'],[10001,'Masters','registered'],[9000,'Masters','dsq']] as [$time,$category,$status]){
   $entry=$race->entries()->create(['type'=>'solo','category'=>$category,'status'=>$status]);app(TimingService::class)->correct($race,$entry,$finish,$admin,$time);
  }
  $rows=app(ResultsService::class)->filtered($race);$this->assertSame([0,1,1,null],array_column($rows,'gap_ms'));$this->assertSame([1,2,2,null],array_column($rows,'place'));
  $masters=app(ResultsService::class)->filtered($race,['category'=>'Masters']);$this->assertSame([0,0,null],array_column($masters,'gap_ms'));$this->assertSame([1,1,null],array_column($masters,'place'));
 }
 public function test_distance_edits_update_standard_checkpoints_and_lock_after_start():void {
  $admin=$this->admin();$this->actingAs($admin)->post('/races',['name'=>'Distance','event_date'=>'2026-09-23','timezone'=>'Europe/Brussels','swim_km'=>1,'bike_km'=>35,'run_km'=>8]);$race=Race::firstOrFail();
  $data=['name'=>$race->name,'event_date'=>'2026-09-23','timezone'=>'Europe/Brussels','status'=>'draft','swim_km'=>1.5,'bike_km'=>40,'run_km'=>10];
  $this->put("/races/$race->id",$data)->assertSessionHasNoErrors();$this->assertEquals(40,$race->fresh()->settings['bike_km']);$this->assertEquals(40,$race->checkpoints()->where('code','BIKE_FINISH')->first()->distance_km);
  $race->update(['started_at'=>now(),'status'=>'running']);$data['status']='running';$data['bike_km']=41;$this->put("/races/$race->id",$data)->assertSessionHasErrors('bike_km');$this->assertEquals(40,$race->fresh()->settings['bike_km']);
 }
}
