<?php
namespace App\Enums;
enum UserRole: string
{
    case Admin = 'admin';
    case Official = 'organizer';
    case Athlete = 'athlete';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Organizer (admin)',
            self::Official => 'Official',
            self::Athlete => 'Athlete',
        };
    }
}
