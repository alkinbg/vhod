<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BuildingAsset;
use App\Entity\ComplianceCompletion;
use App\Entity\MaintenanceContract;
use App\Entity\ManagementMandate;
use App\Enum\ComplianceCompletionType;
use App\Enum\ComplianceReminderType;
use App\Enum\MaintenanceReminderType;
use App\Value\ComplianceReminder;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ComplianceReminderService
{
    private const MANAGEMENT_MANDATE_HORIZON_DAYS = 60;
    private const CONTRACT_HORIZON_DAYS = 60;

    public function __construct(private MaintenanceReminderService $maintenanceReminderService) {}

    /**
     * @param list<ManagementMandate> $mandates
     * @param list<ComplianceCompletion> $completions
     * @param list<MaintenanceContract> $contracts
     * @param list<BuildingAsset> $assets
     *
     * @return list<ComplianceReminder>
     */
    public function build(
        DateTimeImmutable $today,
        array $mandates,
        array $completions,
        array $contracts,
        array $assets,
    ): array {
        $timezone = $today->getTimezone();
        $today = self::dateOnly($today, $timezone);
        $reminders = [];

        $currentMandate = $this->currentMandate($today, $mandates);
        if ($currentMandate instanceof ManagementMandate) {
            $dueAt = self::dateOnly($currentMandate->getEndsAt(), $timezone);
            $daysDelta = self::daysDelta($today, $dueAt);
            if ($daysDelta < 0) {
                $reminders[] = new ComplianceReminder(
                    ComplianceReminderType::MANAGEMENT_MANDATE_EXPIRED,
                    $currentMandate->getHolderLabel(),
                    $dueAt,
                    $daysDelta,
                );
            } elseif ($daysDelta <= self::MANAGEMENT_MANDATE_HORIZON_DAYS) {
                $reminders[] = new ComplianceReminder(
                    ComplianceReminderType::MANAGEMENT_MANDATE_DUE,
                    $currentMandate->getHolderLabel(),
                    $dueAt,
                    $daysDelta,
                );
            }
        }

        $previousMonth = $today->modify('first day of previous month');
        $monthlyPeriodKey = $previousMonth->format('Y-m');
        if (!$this->hasCompletion($completions, ComplianceCompletionType::MONTHLY_REPORT, $monthlyPeriodKey)) {
            $dueAt = $today->modify('first day of this month');
            $reminders[] = new ComplianceReminder(
                ComplianceReminderType::MONTHLY_REPORT_MISSING,
                sprintf('Месечен отчет за %s', $previousMonth->format('m.Y')),
                $dueAt,
                self::daysDelta($today, $dueAt),
            );
        }

        $year = $today->format('Y');
        if (!$this->hasCompletion($completions, ComplianceCompletionType::ANNUAL_CASH_AUDIT, $year)) {
            $dueAt = self::dateOnly(new DateTimeImmutable($year.'-12-31', $timezone), $timezone);
            $reminders[] = new ComplianceReminder(
                ComplianceReminderType::ANNUAL_CASH_AUDIT_DUE,
                sprintf('Годишна проверка на касата за %s', $year),
                $dueAt,
                self::daysDelta($today, $dueAt),
            );
        }

        foreach ($contracts as $contract) {
            $endsAt = $contract->getEndsAt();
            if (!$endsAt instanceof DateTimeImmutable) {
                continue;
            }

            $dueAt = self::dateOnly($endsAt, $timezone);
            $daysDelta = self::daysDelta($today, $dueAt);
            if ($daysDelta < 0) {
                $reminders[] = new ComplianceReminder(
                    ComplianceReminderType::CONTRACT_EXPIRED,
                    $contract->getTitle(),
                    $dueAt,
                    $daysDelta,
                );
            } elseif ($daysDelta <= self::CONTRACT_HORIZON_DAYS) {
                $reminders[] = new ComplianceReminder(
                    ComplianceReminderType::CONTRACT_DUE,
                    $contract->getTitle(),
                    $dueAt,
                    $daysDelta,
                );
            }
        }

        foreach ($this->maintenanceReminderService->build($today, $assets) as $reminder) {
            $reminders[] = new ComplianceReminder(
                self::mapMaintenanceType($reminder->type),
                $reminder->asset->getName(),
                self::dateOnly($reminder->dueAt, $timezone),
                $reminder->daysDelta,
            );
        }

        usort($reminders, static function (ComplianceReminder $left, ComplianceReminder $right): int {
            $byDate = $left->dueAt <=> $right->dueAt;
            if (0 !== $byDate) {
                return $byDate;
            }

            $bySubject = strcmp($left->subject, $right->subject);
            if (0 !== $bySubject) {
                return $bySubject;
            }

            return strcmp($left->type->value, $right->type->value);
        });

        return $reminders;
    }

    /** @param list<ManagementMandate> $mandates */
    private function currentMandate(DateTimeImmutable $today, array $mandates): ?ManagementMandate
    {
        $current = null;
        foreach ($mandates as $mandate) {
            if ($mandate->getStartsAt()->format('Y-m-d') > $today->format('Y-m-d')) {
                continue;
            }
            if (!$current instanceof ManagementMandate || $mandate->getEndsAt() > $current->getEndsAt()) {
                $current = $mandate;
            }
        }

        return $current;
    }

    /** @param list<ComplianceCompletion> $completions */
    private function hasCompletion(array $completions, ComplianceCompletionType $type, string $periodKey): bool
    {
        foreach ($completions as $completion) {
            if ($completion->getType() === $type && $completion->getPeriodKey() === $periodKey) {
                return true;
            }
        }

        return false;
    }

    private static function mapMaintenanceType(MaintenanceReminderType $type): ComplianceReminderType
    {
        return match ($type) {
            MaintenanceReminderType::WARRANTY_EXPIRED => ComplianceReminderType::WARRANTY_EXPIRED,
            MaintenanceReminderType::WARRANTY_DUE => ComplianceReminderType::WARRANTY_DUE,
            MaintenanceReminderType::INSPECTION_OVERDUE => ComplianceReminderType::INSPECTION_OVERDUE,
            MaintenanceReminderType::INSPECTION_DUE => ComplianceReminderType::INSPECTION_DUE,
        };
    }

    private static function dateOnly(DateTimeImmutable $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $normalized = DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), $timezone);
        if (!$normalized instanceof DateTimeImmutable) {
            throw new \LogicException('Unable to normalize compliance reminder date.');
        }

        return $normalized;
    }

    private static function daysDelta(DateTimeImmutable $today, DateTimeImmutable $dueAt): int
    {
        return (int) $today->diff($dueAt)->format('%r%a');
    }
}
