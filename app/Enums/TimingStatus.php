<?php
namespace App\Enums;
enum TimingStatus: string
{
    case Recorded = 'recorded';
    case Voided = 'voided';
}
