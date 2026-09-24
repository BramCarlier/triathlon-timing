<?php
namespace App\Support;

class Permissions
{
    public static function catalogue(): array
    {
        return [
            'races.create' => ['label'=>'Create races', 'description'=>'Organizer (admin)-only race creation.'],
            'races.setup' => ['label'=>'Race setup & publication', 'description'=>'Organizer (admin)-only race setup, checkpoint assignment and publication.'],
            'participants.manage' => ['label'=>'Manage athletes', 'description'=>'Organizer (admin)-only athlete registration and import.'],
            'timings.record' => ['label'=>'Record checkpoint times', 'description'=>'Record and undo timings at the Official’s assigned checkpoint.'],
            'races.control' => ['label'=>'Race controls & corrections', 'description'=>'Organizer (admin)-only start, manual end and timing corrections.'],
            'results.export' => ['label'=>'Export results', 'description'=>'Download CSV and Excel results for assigned races.'],
        ];
    }

    public static function officialCatalogue(): array
    {
        return collect(self::catalogue())->only(['timings.record', 'results.export'])->all();
    }

    public static function all(): array { return array_keys(self::catalogue()); }
    public static function official(): array { return array_keys(self::officialCatalogue()); }
}
