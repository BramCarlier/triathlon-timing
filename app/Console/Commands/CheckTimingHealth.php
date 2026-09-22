<?php
namespace App\Console\Commands;
use App\Services\OperationalHealth;
use Illuminate\Console\Command;
class CheckTimingHealth extends Command {
    protected $signature='timing:health';
    protected $description='Report timing service health without exposing credentials';
    public function handle(OperationalHealth $health):int {
        $checks=$health->checks();foreach($checks as $check)$this->line(($check['ok']?'OK':'ATTENTION').' '.$check['name'].': '.$check['detail']);
        return collect($checks)->every(fn($c)=>$c['ok'])?self::SUCCESS:self::FAILURE;
    }
}
