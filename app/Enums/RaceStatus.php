<?php
namespace App\Enums;
enum RaceStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Running = 'running';
    case Finished = 'finished';
    case Archived = 'archived';
}
