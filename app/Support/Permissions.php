<?php
namespace App\Support;

class Permissions
{
    public static function catalogue(): array
    {
        return [
            'races.create' => ['label'=>'Create races', 'description'=>'Create a race and receive access to it.'],
            'races.setup' => ['label'=>'Race setup & publication', 'description'=>'Edit assigned race settings, checkpoints and public leaderboard publication.'],
            'participants.manage' => ['label'=>'Manage participants', 'description'=>'View, create, edit, delete and import participants in assigned races.'],
            'timings.record' => ['label'=>'Timing station', 'description'=>'Select a checkpoint, search participants and record timings and undo their own records in assigned races.'],
            'races.control' => ['label'=>'Race control & corrections', 'description'=>'Start and finish assigned races, correct timings.'],
            'results.export' => ['label'=>'Export results', 'description'=>'Download CSV and Excel results for assigned races.'],
        ];
    }
    public static function all(): array { return array_keys(self::catalogue()); }
}
