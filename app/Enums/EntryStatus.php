<?php

namespace App\Enums;

enum EntryStatus: string
{
    case Registered = 'registered';
    case Dns = 'dns';
    case Dnf = 'dnf';
    case Dsq = 'dsq';

    /** The label shown in results and timing screens, e.g. "DNF". */
    public function resultLabel(): string
    {
        return strtoupper($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
