<?php
namespace Tests\Concurrency;
use App\Models\{Race,User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
class TimingConcurrencyTest extends TestCase {
    use DatabaseMigrations;
    private function contend(bool $sameId):array {
        $user=User::factory()->create(['role'=>'admin']);$race=Race::create(['name'=>'Concurrent','slug'=>'concurrent','event_date'=>'2026-09-22','started_at'=>now()->subMinute(),'created_by'=>$user->id]);
        $entry=$race->entries()->create(['type'=>'solo']);$cp=$race->checkpoints()->create(['name'=>'Swim','code'=>'SWIM','sequence'=>10,'kind'=>'transition']);$uuid=(string)Str::uuid();
        // Hold the entry lock until both child processes are running. They must
        // serialize on the same row after release, as two checkpoint devices do.
        \Illuminate\Support\Facades\DB::beginTransaction();\App\Models\Entry::lockForUpdate()->findOrFail($entry->id);
        $workers=[];
        for($i=0;$i<2;$i++){$process=new Process([PHP_BINARY,base_path('tests/Concurrency/record.php'),(string)$race->id,(string)$entry->id,(string)$cp->id,(string)$user->id,$sameId?$uuid:(string)Str::uuid()],base_path());$process->setTimeout(20);$process->start();$workers[]=$process;}
        usleep(300000);\Illuminate\Support\Facades\DB::commit();
        $outputs=[];foreach($workers as $worker){$worker->wait();$this->assertTrue($worker->isSuccessful(),$worker->getErrorOutput().$worker->getOutput());$outputs[]=trim($worker->getOutput());}
        $this->assertDatabaseCount('timing_records',1);return $outputs;
    }
    public function test_concurrent_retries_return_the_same_saved_timing():void {$out=$this->contend(true);$this->assertSame($out[0],$out[1]);$this->assertStringStartsWith('saved:',$out[0]);}
    public function test_two_distinct_taps_create_one_timing_and_one_conflict():void {$out=$this->contend(false);$this->assertCount(1,array_filter($out,fn($s)=>$s==='conflict'));$this->assertCount(1,array_filter($out,fn($s)=>str_starts_with($s,'saved:')));}
}
