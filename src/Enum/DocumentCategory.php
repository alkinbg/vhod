<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentCategory: string
{
    case MEETING_INVITATION = 'meeting_invitation';
    case MEETING_MINUTES = 'meeting_minutes';
    case MEETING_PROXY = 'meeting_proxy';
    case HOUSE_RULES = 'house_rules';
    case INVOICE_RECEIPT = 'invoice_receipt';
    case CONTRACT_OFFER = 'contract_offer';
    case WARRANTY = 'warranty';
    case BANK_STATEMENT = 'bank_statement';
    case TECHNICAL_DOCUMENTATION = 'technical_documentation';
    case MONTHLY_REPORT = 'monthly_report';
    case OTHER = 'other';

    public function labelBg(): string
    {
        return match ($this) {
            self::MEETING_INVITATION => 'Покана за общо събрание',
            self::MEETING_MINUTES => 'Протокол от общо събрание',
            self::MEETING_PROXY => 'Пълномощно за общо събрание',
            self::HOUSE_RULES => 'Правилник за вътрешния ред',
            self::INVOICE_RECEIPT => 'Фактура / касов документ',
            self::CONTRACT_OFFER => 'Договор / оферта',
            self::WARRANTY => 'Гаранция',
            self::BANK_STATEMENT => 'Банково извлечение',
            self::TECHNICAL_DOCUMENTATION => 'Техническа документация',
            self::MONTHLY_REPORT => 'Месечен отчет',
            self::OTHER => 'Друг документ',
        };
    }
}
