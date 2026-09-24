<?php
namespace App\Support;

class Permissions
{
    public static function catalogue(): array
    {
        return [
            'races.create' => ['label'=>__('Create races'), 'description'=>__('Organizer (admin)-only race creation.')],
            'races.setup' => ['label'=>__('Race setup & publication'), 'description'=>__('Organizer (admin)-only race setup, checkpoint assignment and publication.')],
            'participants.manage' => ['label'=>__('Manage athletes'), 'description'=>__('Organizer (admin)-only athlete registration and import.')],
            'timings.record' => ['label'=>__('Record checkpoint times'), 'description'=>__('Record and undo timings at the Official’s assigned checkpoint.')],
            'races.control' => ['label'=>__('Race controls & corrections'), 'description'=>__('Organizer (admin)-only start, manual end and timing corrections.')],
            'results.export' => ['label'=>__('Export results'), 'description'=>__('Download CSV and Excel results for assigned races.')],
        ];
    }

    public static function officialCatalogue(): array
    {
        return collect(self::catalogue())->only(['timings.record', 'results.export'])->all();
    }

    public static function all(): array { return array_keys(self::catalogue()); }
    public static function official(): array { return array_keys(self::officialCatalogue()); }
}
