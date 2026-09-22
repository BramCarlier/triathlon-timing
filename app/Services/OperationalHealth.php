<?php
namespace App\Services;
use App\Models\OperatorPresence;
use App\Models\Race;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
class OperationalHealth {
    public function checks():array {
        $checks=[];
        try {DB::select('select 1');$checks[]=['name'=>'Database','ok'=>true,'detail'=>'Connection available'];}
        catch(\Throwable){return [['name'=>'Database','ok'=>false,'detail'=>'Connection failed. Inspect server logs.']];}
        foreach(['scheduler'=>'Scheduler','queue'=>'Queue worker'] as $key=>$name){$at=Cache::get('health.'.$key);$checks[]=['name'=>$name,'ok'=>$at&&now()->diffInSeconds(\Carbon\Carbon::parse($at),true)<180,'detail'=>$at?'Last heartbeat: '.$at:'No heartbeat received yet'];}
        $reverb=Cache::get('health.reverb');$checks[]=['name'=>'Reverb','ok'=>$reverb&&$reverb['ok']&&now()->diffInSeconds(\Carbon\Carbon::parse($reverb['checked_at']),true)<600,'detail'=>$reverb?'Last handshake check: '.$reverb['checked_at']:'Awaiting scheduled handshake check'];
        $failed=DB::table('failed_jobs')->count();$checks[]=['name'=>'Failed queue jobs','ok'=>$failed===0,'detail'=>$failed.' retained failures'];
        $free=@disk_free_space(storage_path());$checks[]=['name'=>'Storage space','ok'=>$free!==false&&$free>1073741824,'detail'=>$free===false?'Unable to read free space':round($free/1073741824,1).' GB free'];
        $mail=config('mail.default');$checks[]=['name'=>'Email delivery configuration','ok'=>!in_array($mail,['log','array',null],true),'detail'=>in_array($mail,['log','array',null],true)?'A delivering mail provider still needs configuration.':'Mail transport configured; verify delivery with a real reset email.'];
        $races=Race::where('status','running')->pluck('id');$active=OperatorPresence::whereIn('race_id',$races)->where('last_seen_at','>=',now()->subMinutes(2))->count();
        $covered=OperatorPresence::whereIn('race_id',$races)->where('last_seen_at','>=',now()->subMinutes(2))->distinct()->count('race_id');
        $checks[]=['name'=>'Running race stations','ok'=>$races->count()===$covered,'detail'=>$races->count().' running races; '.$active.' stations seen in the last two minutes; '.($races->count()-$covered).' races without a recent station'];
        return $checks;
    }
}
