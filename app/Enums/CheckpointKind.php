<?php
namespace App\Enums;
enum CheckpointKind: string
{
    case Start = 'start';
    case Split = 'split';
    case Transition = 'transition';
    case Finish = 'finish';
}
