<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentSource: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case OTHER = 'other';
}
