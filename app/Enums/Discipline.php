<?php
namespace App\Enums;
enum Discipline: string
{
    case Swim = 'swim';
    case Bike = 'bike';
    case Run = 'run';

    public function label(): string { return ucfirst($this->value); }
}
