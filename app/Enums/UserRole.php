<?php
namespace App\Enums;
enum UserRole: string
{
    case Admin = 'admin';
    case Organizer = 'organizer';
    case Athlete = 'athlete';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Organizer (admin)',
            self::Organizer => 'Official',
            self::Athlete => 'Athlete',
        };
    }
}
