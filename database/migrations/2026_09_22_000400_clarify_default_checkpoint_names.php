<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Rename only exact built-in labels; IDs, order, custom names and timing history stay intact.
        foreach ([
            ['SWIM_FINISH','swim','transition','Swim Finish','Swim Exit'],
            ['BIKE_START','bike','transition','Swim–bike transition (Bike Start)','T1 (Bike Start)'],
            ['RUN_START','run','transition','Bike–run transition (Run Start)','T2 (Run Start)'],
            ['RUN_FINISH','run','finish','Run Finish','Finish'],
        ] as [$code,$sport,$kind,$old,$new]) {
            DB::table('checkpoints')->where('code',$code)->where('discipline',$sport)->where('kind',$kind)->where('name',$old)->update(['name'=>$new]);
        }
    }
    public function down(): void { /* Presentation-only rename; do not overwrite subsequent organizer edits. */ }
};
