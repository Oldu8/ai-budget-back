<?php

namespace App\Enums;

enum PlanType: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Plus = 'plus';
    case Premium = 'premium';

    public static function paidValues(): array
    {
        return [self::Basic->value, self::Plus->value, self::Premium->value];
    }

    public static function values(): array
    {
        return [self::Free->value, self::Basic->value, self::Plus->value, self::Premium->value];
    }
}
