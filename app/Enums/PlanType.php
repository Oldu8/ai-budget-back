<?php

namespace App\Enums;

enum PlanType: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Plus = 'plus';
    case Premium = 'premium';
}
