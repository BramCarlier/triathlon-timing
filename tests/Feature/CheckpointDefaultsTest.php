<?php
namespace Tests\Feature;

use App\Models\{Race, User};
use App\Services\{ResultsService, TimingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckpointDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_race_uses_swim_bike_run_with_separate_transition_times(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin)->post('/races', ['name'=>'New course','event_date'=>'2026-09-27','timezone'=>'Europe/Brussels','swim_km'=>1,'run_km'=>8,'bike_km'=>35])->assertSessionHasNoErrors();
        $race = Race::firstOrFail();
        $checkpoints = $race->checkpoints()->get();
        $this->assertSame(['Race Start','Swim Exit','T1 (Bike Start)','Bike Finish','T2 (Run Start)','Finish'], $checkpoints->pluck('name')->all());
        $this->assertSame(['START','SWIM_FINISH','BIKE_START','BIKE_FINISH','RUN_START','RUN_FINISH'], $checkpoints->pluck('code')->all());
        $this->assertSame(['start','transition','transition','transition','transition','finish'], $checkpoints->map(fn($cp)=>$cp->kind->value)->all());
        $this->assertSame([null,'swim','bike','bike','run','run'], $checkpoints->map(fn($cp)=>$cp->discipline?->value)->all());
        $this->assertSame([0.0,1.0,0.0,35.0,0.0,8.0], $checkpoints->map(fn($cp)=>(float)$cp->distance_km)->all());
        $race->update(['started_at'=>now()->subHour()]);
        $entry=$race->entries()->create(['type'=>'solo']);
        $elapsed=[600000,660000,1860000,1980000,5580000];
        foreach($checkpoints->skip(1)->values() as $i=>$checkpoint){
            app(TimingService::class)->correct($race,$entry,$checkpoint,$admin,$elapsed[$i]);
            $row=app(ResultsService::class)->rows($race)[0];
            $this->assertSame($i===4,$row['finished']);
        }
        $this->assertSame([600000,60000,1200000,120000,3600000],array_column($row['splits'],'split_ms'));
        $this->assertSame(5580000,$row['total_ms']);
    }

    public function test_checkpoint_code_is_generated_and_duplicate_names_get_unique_codes(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $race=Race::create(['name'=>'Custom','slug'=>'custom','event_date'=>'2026-09-27','created_by'=>$admin->id]);
        $data=['name'=>'Run 4 km','discipline'=>'run','kind'=>'split','distance_km'=>4,'is_required'=>true,'is_active'=>true];
        $this->actingAs($admin)->post("/races/$race->id/checkpoints",$data+['sequence'=>25])->assertSessionHasNoErrors();
        $this->post("/races/$race->id/checkpoints",$data+['sequence'=>26])->assertSessionHasNoErrors();
        $this->assertSame(['RUN_4_KM','RUN_4_KM_2'],$race->checkpoints()->pluck('code')->all());
        $this->post("/races/$race->id/checkpoints",$data+['sequence'=>26])->assertSessionHasErrors('sequence');
    }
    public function test_label_upgrade_preserves_custom_names_and_checkpoint_identity(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $race=Race::create(['name'=>'Existing','slug'=>'existing','event_date'=>'2026-09-27','created_by'=>$admin->id]);
        $default=$race->checkpoints()->create(['name'=>'Swim Finish','code'=>'SWIM_FINISH','kind'=>'transition','discipline'=>'swim','sequence'=>10,'distance_km'=>1]);
        $custom=$race->checkpoints()->create(['name'=>'Bridge bike start','code'=>'BIKE_START','kind'=>'transition','discipline'=>'bike','sequence'=>20,'distance_km'=>0]);
        $migration=require database_path('migrations/2026_09_22_000400_clarify_default_checkpoint_names.php');$migration->up();
        $this->assertSame('Swim Exit',$default->fresh()->name);
        $this->assertSame('Bridge bike start',$custom->fresh()->name);
        $this->assertSame([$default->id,$custom->id],$race->checkpoints()->pluck('id')->all());
        $this->assertSame(10,$default->fresh()->sequence);
    }

}
