<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BuildingAsset;
use App\Enum\MaintenanceReminderType;
use App\Value\MaintenanceReminder;
use DateTimeImmutable;

final class MaintenanceReminderService
{
    /**
     * @param list<BuildingAsset> $assets
     *
     * @return list<MaintenanceReminder>
     */
    public function build(DateTimeImmutable $today, array $assets): array
    {
        $today = $today->setTime(0, 0);
        $warrantyHorizon = $today->modify('+60 days');
        $inspectionHorizon = $today->modify('+30 days');
        $reminders = [];

        foreach ($assets as $asset) {
            if (!$asset->isActive()) {
                continue;
            }

            $warrantyUntil = $asset->getWarrantyUntil();
            if (null !== $warrantyUntil) {
                $due = $warrantyUntil->setTime(0, 0);
                if ($due < $today) {
                    $reminders[] = new MaintenanceReminder($asset, MaintenanceReminderType::WARRANTY_EXPIRED, $due, self::daysDelta($today, $due));
                } elseif ($due <= $warrantyHorizon) {
                    $reminders[] = new MaintenanceReminder($asset, MaintenanceReminderType::WARRANTY_DUE, $due, self::daysDelta($today, $due));
                }
            }

            $nextInspectionAt = $asset->getNextInspectionAt();
            if (null !== $nextInspectionAt) {
                $due = $nextInspectionAt->setTime(0, 0);
                if ($due < $today) {
                    $reminders[] = new MaintenanceReminder($asset, MaintenanceReminderType::INSPECTION_OVERDUE, $due, self::daysDelta($today, $due));
                } elseif ($due <= $inspectionHorizon) {
                    $reminders[] = new MaintenanceReminder($asset, MaintenanceReminderType::INSPECTION_DUE, $due, self::daysDelta($today, $due));
                }
            }
        }

        usort($reminders, static function (MaintenanceReminder $left, MaintenanceReminder $right): int {
            $dateOrder = $left->dueAt <=> $right->dueAt;
            if (0 !== $dateOrder) {
                return $dateOrder;
            }

            $assetOrder = strcmp($left->asset->getName(), $right->asset->getName());
            if (0 !== $assetOrder) {
                return $assetOrder;
            }

            return self::typeRank($left->type) <=> self::typeRank($right->type);
        });

        return $reminders;
    }

    private static function daysDelta(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    private static function typeRank(MaintenanceReminderType $type): int
    {
        return match ($type) {
            MaintenanceReminderType::WARRANTY_EXPIRED,
            MaintenanceReminderType::WARRANTY_DUE => 0,
            MaintenanceReminderType::INSPECTION_OVERDUE,
            MaintenanceReminderType::INSPECTION_DUE => 1,
        };
    }
}
