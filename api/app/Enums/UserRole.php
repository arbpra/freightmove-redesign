<?php

namespace App\Enums;

enum UserRole: string
{
    case Guest = 'guest';
    case Shipper = 'shipper';
    case Carrier = 'carrier';
    case Admin = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
