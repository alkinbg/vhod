<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceCompletion;
use App\Entity\CondominiumProfile;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\ManagementMandate;
use App\Entity\User;
use App\Enum\ComplianceCompletionType;
use App\Enum\ManagementMandateKind;
use App\Security\ComplianceAccessPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class ComplianceRegistryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceAccessPolicy $accessPolicy,
    ) {}

    public function profile(): ?CondominiumProfile
    {
        return $this->entityManager->getRepository(CondominiumProfile::class)->findOneBy([]);
    }

    public function updateRegistryData(
        User $actor,
        ?string $identifier,
        ?string $parcelNumber,
        ?DateTimeImmutable $registeredAt,
        DateTimeImmutable $updatedAt,
    ): CondominiumProfile {
        if (!$this->accessPolicy->canManageRegistry($actor)) {
            throw new DomainException('Compliance registry management access is required.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $identifier, $parcelNumber, $registeredAt, $updatedAt): CondominiumProfile {
            $profile = $entityManager->getRepository(CondominiumProfile::class)->findOneBy([]);
            if (!$profile instanceof CondominiumProfile) {
                $profile = CondominiumProfile::create($actor, $updatedAt);
                $entityManager->persist($profile);
            }

            $profile->updateRegistryData($identifier, $parcelNumber, $registeredAt, $actor, $updatedAt);

            return $profile;
        });
    }

    public function recordMandate(
        User $actor,
        ManagementMandateKind $kind,
        string $holderLabel,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $recordedAt,
        ?GeneralAssembly $sourceAssembly = null,
        ?string $note = null,
    ): ManagementMandate {
        if (!$this->accessPolicy->canRecordMandate($actor)) {
            throw new DomainException('Management mandate recording access is required.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $kind, $holderLabel, $startsAt, $endsAt, $recordedAt, $sourceAssembly, $note): ManagementMandate {
            $mandate = ManagementMandate::record(
                $kind,
                $holderLabel,
                $startsAt,
                $endsAt,
                $actor,
                $recordedAt,
                $sourceAssembly,
                $note,
            );
            $entityManager->persist($mandate);

            return $mandate;
        });
    }

    public function recordCompletion(
        User $actor,
        ComplianceCompletionType $type,
        string $periodKey,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $recordedAt,
        ?Document $evidenceDocument = null,
        ?string $note = null,
    ): ComplianceCompletion {
        $allowed = match ($type) {
            ComplianceCompletionType::MONTHLY_REPORT => $this->accessPolicy->canRecordMonthlyReport($actor),
            ComplianceCompletionType::ANNUAL_CASH_AUDIT => $this->accessPolicy->canRecordAnnualAudit($actor),
        };
        if (!$allowed) {
            throw new DomainException('Compliance completion recording access is required.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $type, $periodKey, $completedAt, $recordedAt, $evidenceDocument, $note): ComplianceCompletion {
            $existing = $entityManager->getRepository(ComplianceCompletion::class)->findOneBy([
                'type' => $type,
                'periodKey' => trim($periodKey),
            ]);
            if ($existing instanceof ComplianceCompletion) {
                throw new DomainException('За този период вече има записано изпълнение.');
            }

            $completion = ComplianceCompletion::record(
                $type,
                $periodKey,
                $completedAt,
                $actor,
                $recordedAt,
                $evidenceDocument,
                $note,
            );
            $entityManager->persist($completion);

            return $completion;
        });
    }
}
