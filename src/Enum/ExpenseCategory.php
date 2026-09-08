<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpenseCategory: string
{
    case MANAGER_COMPENSATION = 'manager_compensation';
    case CONTROLLER_COMPENSATION = 'controller_compensation';
    case CASHIER_COMPENSATION = 'cashier_compensation';
    case PROFESSIONAL_MANAGER_COMPENSATION = 'professional_manager_compensation';
    case MANAGEMENT_CONSUMABLES = 'management_consumables';

    case COMMON_PARTS_CLEANING = 'common_parts_cleaning';
    case ELEVATOR_MAINTENANCE = 'elevator_maintenance';
    case COMMON_ELECTRICITY = 'common_electricity';
    case COMMON_WATER = 'common_water';
    case PEST_CONTROL = 'pest_control';
    case LANDSCAPING = 'landscaping';
    case MAINTENANCE_OTHER = 'maintenance_other';

    case NECESSARY_REPAIR = 'necessary_repair';
    case URGENT_REPAIR = 'urgent_repair';
    case MAJOR_RENOVATION = 'major_renovation';
    case COMMON_PART_REMODELING = 'common_part_remodeling';
    case COMMON_INSTALLATION_REPLACEMENT = 'common_installation_replacement';
    case USEFUL_EXPENSE = 'useful_expense';

    case LEGAL_SERVICES = 'legal_services';
    case CONSULTING_SERVICES = 'consulting_services';
    case COURT_PROCEEDINGS = 'court_proceedings';
    case BANK_FEES = 'bank_fees';

    case OTHER = 'other';

    public function section(): ExpenseReportSection
    {
        return match ($this) {
            self::MANAGER_COMPENSATION,
            self::CONTROLLER_COMPENSATION,
            self::CASHIER_COMPENSATION,
            self::PROFESSIONAL_MANAGER_COMPENSATION,
            self::MANAGEMENT_CONSUMABLES => ExpenseReportSection::MANAGEMENT,

            self::COMMON_PARTS_CLEANING,
            self::ELEVATOR_MAINTENANCE,
            self::COMMON_ELECTRICITY,
            self::COMMON_WATER,
            self::PEST_CONTROL,
            self::LANDSCAPING,
            self::MAINTENANCE_OTHER => ExpenseReportSection::MAINTENANCE,

            self::NECESSARY_REPAIR,
            self::URGENT_REPAIR,
            self::MAJOR_RENOVATION,
            self::COMMON_PART_REMODELING,
            self::COMMON_INSTALLATION_REPLACEMENT,
            self::USEFUL_EXPENSE => ExpenseReportSection::REPAIRS,

            self::LEGAL_SERVICES,
            self::CONSULTING_SERVICES,
            self::COURT_PROCEEDINGS,
            self::BANK_FEES => ExpenseReportSection::SERVICES,

            self::OTHER => ExpenseReportSection::OTHER,
        };
    }

    public function labelBg(): string
    {
        return match ($this) {
            self::MANAGER_COMPENSATION => 'Възнаграждение на управител/управителен съвет',
            self::CONTROLLER_COMPENSATION => 'Възнаграждение на контрольор/контролен съвет',
            self::CASHIER_COMPENSATION => 'Възнаграждение на касиер',
            self::PROFESSIONAL_MANAGER_COMPENSATION => 'Възнаграждение на професионален управител',
            self::MANAGEMENT_CONSUMABLES => 'Консумативи за управление',
            self::COMMON_PARTS_CLEANING => 'Почистване на общите части',
            self::ELEVATOR_MAINTENANCE => 'Поддръжка и обслужване на асансьор',
            self::COMMON_ELECTRICITY => 'Електроенергия за общите части',
            self::COMMON_WATER => 'Вода за общите части',
            self::PEST_CONTROL => 'Дезинфекция, дезинсекция и дератизация',
            self::LANDSCAPING => 'Поддръжка на прилежащи зелени площи',
            self::MAINTENANCE_OTHER => 'Други разходи за поддръжка на общите части',
            self::NECESSARY_REPAIR => 'Необходим ремонт',
            self::URGENT_REPAIR => 'Неотложен ремонт',
            self::MAJOR_RENOVATION => 'Основен ремонт и обновяване',
            self::COMMON_PART_REMODELING => 'Преустройство на общи части',
            self::COMMON_INSTALLATION_REPLACEMENT => 'Подмяна на общи инсталации и оборудване',
            self::USEFUL_EXPENSE => 'Полезни разходи за общите части',
            self::LEGAL_SERVICES => 'Юридически услуги',
            self::CONSULTING_SERVICES => 'Консултантски услуги',
            self::COURT_PROCEEDINGS => 'Съдебни и изпълнителни разходи',
            self::BANK_FEES => 'Банкови такси',
            self::OTHER => 'Други разходи',
        };
    }
}
