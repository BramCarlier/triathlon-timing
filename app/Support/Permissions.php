<?php
namespace App\Support;

class Permissions
{
    public static function catalogue(): array
    {
        return [
            'timings.record' => ['label'=>'Record checkpoint times', 'description'=>'Record and undo timings at the Official’s assigned checkpoint. The checkpoint itself is assigned by an Organizer.'],
            'results.export' => ['label'=>'Export results', 'description'=>'Download CSV and Excel results for assigned races.'],
        ];
    }

    public static function all(): array { return array_keys(self::catalogue()); }
}
