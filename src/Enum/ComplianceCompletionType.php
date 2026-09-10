<?php

declare(strict_types=1);

namespace App\Enum;

enum ComplianceCompletionType: string
{
    case MONTHLY_REPORT = 'monthly_report';
    case ANNUAL_CASH_AUDIT = 'annual_cash_audit';

    public function labelBg(): string
    {
        return match ($this) {
            self::MONTHLY_REPORT => 'Месечен отчет',
            self::ANNUAL_CASH_AUDIT => 'Годишна проверка на касата',
        };
    }
}
