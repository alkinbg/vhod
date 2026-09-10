<?php

declare(strict_types=1);

namespace App\Enum;

enum ComplianceReminderType: string
{
    case MANAGEMENT_MANDATE_EXPIRED = 'management_mandate_expired';
    case MANAGEMENT_MANDATE_DUE = 'management_mandate_due';
    case MONTHLY_REPORT_MISSING = 'monthly_report_missing';
    case ANNUAL_CASH_AUDIT_DUE = 'annual_cash_audit_due';
    case CONTRACT_EXPIRED = 'contract_expired';
    case CONTRACT_DUE = 'contract_due';
    case WARRANTY_EXPIRED = 'warranty_expired';
    case WARRANTY_DUE = 'warranty_due';
    case INSPECTION_OVERDUE = 'inspection_overdue';
    case INSPECTION_DUE = 'inspection_due';

    public function labelBg(): string
    {
        return match ($this) {
            self::MANAGEMENT_MANDATE_EXPIRED => 'Изтекъл мандат на управлението',
            self::MANAGEMENT_MANDATE_DUE => 'Мандатът на управлението изтича скоро',
            self::MONTHLY_REPORT_MISSING => 'Липсва месечен отчет',
            self::ANNUAL_CASH_AUDIT_DUE => 'Годишна проверка на касата',
            self::CONTRACT_EXPIRED => 'Изтекъл договор',
            self::CONTRACT_DUE => 'Договорът изтича скоро',
            self::WARRANTY_EXPIRED => 'Изтекла гаранция',
            self::WARRANTY_DUE => 'Гаранцията изтича скоро',
            self::INSPECTION_OVERDUE => 'Просрочена проверка',
            self::INSPECTION_DUE => 'Предстояща проверка',
        };
    }
}
