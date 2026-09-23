<?php
// Included only by the guarded, isolated browser-test seeder.
use App\Models\{User,Race,Athlete,Entry,TimingRecord,AccessRole};
use Illuminate\Support\Str;
$admin=User::where('email','admin@example.test')->firstOrFail();
$athlete=Athlete::forceCreate(['id'=>9001,'first_name'=>str_repeat('Alexandria',7),'last_name'=>'Van der Championship','email'=>str_repeat('long',20).'@example.test']);
User::factory()->create(['id'=>9001,'name'=>str_repeat('Official',12),'email'=>'responsive-official@example.test','password'=>'browser-test-password-123','role'=>'organizer']);
User::factory()->create(['id'=>9002,'name'=>'Responsive Athlete','email'=>'responsive-athlete@example.test','password'=>'browser-test-password-123','role'=>'athlete','athlete_id'=>$athlete->id]);
AccessRole::create(['name'=>str_repeat('Checkpoint',8),'description'=>str_repeat('RoleDescription',20),'permissions'=>['timings.record']]);
foreach ([9001=>'running',9002=>'draft',9003=>'finished'] as $id=>$status) {
    $race=Race::forceCreate(['id'=>$id,'name'=>str_repeat('TriathlonChampionship',4).' '.$status,'slug'=>'responsive-'.$id,'event_date'=>'2026-09-23','created_by'=>$admin->id,'status'=>$status,'started_at'=>$status==='draft'?null:now()->subHours(2),'finished_at'=>$status==='finished'?now():null,'settings'=>['swim_km'=>1,'bike_km'=>35,'run_km'=>8],'public_results_token'=>$id===9001?'responsive-public':null,'results_published_at'=>$id===9001?now():null]);
    $race->organizers()->attach([$admin->id,9001]);
    $race->checkpoints()->create(['name'=>'Race Start','code'=>'START','sequence'=>0,'kind'=>'start','is_active'=>true]);
    $cp=$race->checkpoints()->create(['name'=>'Swim Exit '.str_repeat('Checkpoint',6),'code'=>'SWIM','sequence'=>10,'kind'=>'transition','discipline'=>'swim','distance_km'=>1,'is_active'=>true]);
    $finish=$race->checkpoints()->create(['name'=>'Finish','code'=>'FINISH','sequence'=>50,'kind'=>'finish','discipline'=>'run','distance_km'=>8,'is_active'=>true]);
    if($id!==9001)continue;
    $entry=Entry::forceCreate(['id'=>9001,'race_id'=>$id,'bib_number'=>str_repeat('12345678',4),'type'=>'solo','category'=>str_repeat('Category',10)]);
    foreach(['swim','bike','run'] as $sport)$entry->members()->create(['athlete_id'=>$athlete->id,'discipline'=>$sport]);
    foreach([$cp,$finish] as $point)TimingRecord::create(['client_uuid'=>(string)Str::uuid(),'race_id'=>$id,'entry_id'=>$entry->id,'checkpoint_id'=>$point->id,'athlete_id'=>$athlete->id,'operator_id'=>$admin->id,'recorded_at'=>now(),'elapsed_ms'=>$point->id===$finish->id?7200012:1000234,'source'=>'online','status'=>'recorded']);
    // A relay without a bib tests the widest station labels and multi-member results.
    $relay=Entry::create(['race_id'=>$id,'bib_number'=>null,'type'=>'relay','team_name'=>str_repeat('TheLongestRelayTeamName',4),'category'=>'Relay']);
    foreach(['swim','bike','run'] as $sport) {
        $member=Athlete::create(['first_name'=>ucfirst($sport),'last_name'=>str_repeat('Championship',5)]);
        $relay->members()->create(['athlete_id'=>$member->id,'discipline'=>$sport]);
    }
}
