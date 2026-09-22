<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{Athlete,Entry,Race,User};
use App\Services\{ResultsService,TimingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
class EventReadinessTest extends TestCase {
    use RefreshDatabase;
    private function fixture():array {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);$this->actingAs($admin);
        $race=Race::create(['name'=>'Event','slug'=>'event','event_date'=>'2026-09-22','started_at'=>now()->subHour(),'created_by'=>$admin->id]);
        $athlete=Athlete::create(['first_name'=>'Test','last_name'=>'Person']);
        $entry=$race->entries()->create(['type'=>'solo','category'=>'Open']);
        foreach(['swim','bike','run'] as $i=>$discipline)$entry->members()->create(['athlete_id'=>$athlete->id,'discipline'=>$discipline,'position'=>$i+1]);
        return [$admin,$race,$entry,$athlete];
    }
    public function test_edit_is_audited_and_requires_a_reason():void {
        [$admin,$race,$entry,$athlete]=$this->fixture();
        $payload=['bib_number'=>'007','category'=>'Masters','status'=>'dnf','athletes'=>[$athlete->only(['id','first_name','last_name','email','club'])]];
        $this->put("/races/$race->id/participants/$entry->id",$payload)->assertSessionHasErrors('reason');
        $this->put("/races/$race->id/participants/$entry->id",$payload+['reason'=>'Withdrew during bike'])->assertSessionHasNoErrors();
        $this->assertSame('007',$entry->fresh()->bib_number);$this->assertSame('dnf',$entry->fresh()->status);
        $this->assertDatabaseHas('entry_changes',['entry_id'=>$entry->id,'user_id'=>$admin->id,'reason'=>'Withdrew during bike']);
    }
    public function test_athlete_and_unassigned_organizer_cannot_edit_participants_or_view_health():void {
        [$admin,$race,$entry]=$this->fixture();
        foreach([UserRole::Athlete,UserRole::Organizer] as $role){$this->actingAs(User::factory()->create(['role'=>$role]))->get("/races/$race->id/participants/$entry->id/edit")->assertForbidden();$this->get('/admin/health')->assertForbidden();}
    }
    public function test_result_status_excludes_places_but_preserves_times_and_filters_exports():void {
        Event::fake();[$admin,$race,$entry]=$this->fixture();
        $finish=$race->checkpoints()->create(['name'=>'Finish','code'=>'FINISH','kind'=>'finish','sequence'=>30]);
        app(TimingService::class)->correct($race,$entry,$finish,$admin,120000);
        $entry->update(['status'=>'dsq']);$row=app(ResultsService::class)->filtered($race)[0];
        $this->assertNull($row['place']);$this->assertSame(120000,$row['total_ms']);$this->assertSame('DSQ',$row['result_status']);
        $this->assertSame([],app(ResultsService::class)->filtered($race,['category'=>'Other']));
        $this->get("/races/$race->id/results.csv?status=DSQ")->assertOk()->assertDownload();
        $this->put("/races/$race->id/participants/$entry->id",['bib_number'=>null,'category'=>'Open','status'=>'dns','reason'=>'Incorrect status','athletes'=>$entry->members()->with('athlete')->get()->pluck('athlete')->unique('id')->map->only(['id','first_name','last_name','email','club'])->values()->all()])->assertSessionHasErrors('status');
    }
    public function test_offline_replay_can_use_its_original_checkpoint_after_selection_changes():void {
        Event::fake();[$admin,$race,$entry]=$this->fixture();$organizer=User::factory()->create(['role'=>UserRole::Organizer]);$organizer->races()->attach($race);
        $cp=$race->checkpoints()->create(['name'=>'Swim','code'=>'SWIM','kind'=>'transition','sequence'=>10]);
        $this->actingAs($organizer)->withSession(["checkpoint.$race->id"=>999])->postJson("/races/$race->id/timings",['entry_id'=>$entry->id,'checkpoint_id'=>$cp->id,'client_uuid'=>(string)\Illuminate\Support\Str::uuid(),'source'=>'offline','observed_at'=>now()->subSeconds(10)->toISOString()])->assertOk();
    }
}
