<?php

namespace App\Enums;

enum PlanType: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Standard = 'standard';
    case Premium = 'premium';
}
