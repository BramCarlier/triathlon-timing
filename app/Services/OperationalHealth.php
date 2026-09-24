<?php
namespace App\Services;

use App\Models\OperatorPresence;
use App\Models\Race;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationalHealth
{
    public function checks(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks[] = ['name'=>__('Database'),'ok'=>true,'detail'=>__('Connection available')];
        } catch (\Throwable) {
            return [['name'=>__('Database'),'ok'=>false,'detail'=>__('Connection failed. Inspect server logs.')]];
        }

        foreach (['scheduler'=>'Scheduler','queue'=>'Queue worker'] as $key=>$name) {
            $at = Cache::get('health.'.$key);
            $checks[] = [
                'name' => __($name),
                'ok' => $at && now()->diffInSeconds(\Carbon\Carbon::parse($at), true) < 180,
                'detail' => $at ? __('Last heartbeat: :time', ['time'=>$at]) : __('No heartbeat received yet'),
            ];
        }

        $reverb = Cache::get('health.reverb');
        $checks[] = [
            'name' => 'Reverb',
            'ok' => $reverb && $reverb['ok'] && now()->diffInSeconds(\Carbon\Carbon::parse($reverb['checked_at']), true) < 600,
            'detail' => $reverb ? __('Last handshake check: :time', ['time'=>$reverb['checked_at']]) : __('Awaiting scheduled handshake check'),
        ];

        $failed = DB::table('failed_jobs')->count();
        $checks[] = ['name'=>__('Failed queue jobs'),'ok'=>$failed===0,'detail'=>__(':count retained failures', ['count'=>$failed])];

        $free = @disk_free_space(storage_path());
        $checks[] = [
            'name' => __('Storage space'),
            'ok' => $free !== false && $free > 1073741824,
            'detail' => $free === false ? __('Unable to read free space') : __(':space GB free', ['space'=>round($free/1073741824,1)]),
        ];

        $mail = config('mail.default');
        $checks[] = [
            'name' => __('Email delivery configuration'),
            'ok' => !in_array($mail, ['log','array',null], true),
            'detail' => in_array($mail, ['log','array',null], true)
                ? __('A delivering mail provider still needs configuration.')
                : __('Mail transport configured; verify delivery with a real reset email.'),
        ];

        $races = Race::where('status','running')->pluck('id');
        $active = OperatorPresence::whereIn('race_id',$races)->where('last_seen_at','>=',now()->subMinutes(2))->count();
        $covered = OperatorPresence::whereIn('race_id',$races)->where('last_seen_at','>=',now()->subMinutes(2))->distinct()->count('race_id');
        $checks[] = [
            'name' => __('Running race stations'),
            'ok' => $races->count() === $covered,
            'detail' => __(':races running races; :stations stations seen in the last two minutes; :missing races without a recent station', [
                'races'=>$races->count(),
                'stations'=>$active,
                'missing'=>$races->count()-$covered,
            ]),
        ];

        return $checks;
    }
}
