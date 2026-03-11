<?php

namespace App\Enums;

enum TransactionCreatedBy: string
{
    case User = 'user';
    case Ai = 'ai';
    case Recurring = 'recurring';
}
