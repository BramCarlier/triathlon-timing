<?php
namespace App\Enums;
enum TimingSource: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Manual = 'manual';
}
