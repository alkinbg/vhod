<?php

declare(strict_types=1);

namespace App\Enum;

enum ExternalIncomeCategory: string
{
    case COMMON_PART_RENT = 'common_part_rent';
    case ADVERTISING_TECHNICAL_INSTALLATIONS = 'advertising_technical_installations';
    case PUBLIC_FUNDING_SUBSIDY = 'public_funding_subsidy';
    case LOAN_PROCEEDS = 'loan_proceeds';
    case RENEWABLE_ENERGY = 'renewable_energy';
    case DONATION = 'donation';
    case OTHER = 'other';

    public function labelBg(): string
    {
        return match ($this) {
            self::COMMON_PART_RENT => 'Приходи от отдаване под наем на общи части',
            self::ADVERTISING_TECHNICAL_INSTALLATIONS => 'Приходи от реклама и технически съоръжения в общите части',
            self::PUBLIC_FUNDING_SUBSIDY => 'Финансиране и субсидии от държавни, общински и европейски програми',
            self::LOAN_PROCEEDS => 'Средства от кредити и заеми',
            self::RENEWABLE_ENERGY => 'Приходи от възобновяеми енергийни източници',
            self::DONATION => 'Дарения',
            self::OTHER => 'Други приходи',
        };
    }
}
