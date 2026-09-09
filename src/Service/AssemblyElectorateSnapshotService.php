<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\AssemblyPrincipalType;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\UnitRelationType;
use App\Util\ExactDecimal;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class AssemblyElectorateSnapshotService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<AssemblyElectorateEntry> */
    public function createSnapshot(GeneralAssembly $assembly, DateTimeImmutable $createdAt): array
    {
        if (GeneralAssemblyStatus::DRAFT !== $assembly->getStatus()) {
            throw new DomainException('Electorate can be snapshotted only while the General Assembly is a draft.');
        }

        $entryRepository = $this->entityManager->getRepository(AssemblyElectorateEntry::class);
        if (0 < $entryRepository->count(['assembly' => $assembly])) {
            throw new DomainException('General Assembly electorate has already been snapshotted.');
        }

        /** @var list<Unit> $units */
        $units = $this->entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']);
        $entries = [];

        foreach ($units as $unit) {
            $relations = $this->activeOwnerRelations($unit, $assembly->getReferenceDate());
            if ([] === $relations) {
                throw new DomainException(sprintf(
                    'Active unit "%s" has no active owner relation on the General Assembly reference date.',
                    $unit->getDesignation(),
                ));
            }

            $groupReviewReason = $this->ownershipGroupReviewReason($relations);
            $unitIdealParts = null === $unit->getIdealParts() ? null : ExactDecimal::normalize($unit->getIdealParts());

            foreach ($relations as $relation) {
                $share = $this->ownershipShareSnapshot($relation, count($relations));
                $reviewReasons = [];
                if (null !== $groupReviewReason) {
                    $reviewReasons[] = $groupReviewReason;
                }
                if (null === $unitIdealParts) {
                    $reviewReasons[] = 'Липсват идеални части за обекта.';
                }

                $representedWeight = null;
                if ([] === $reviewReasons && null !== $share && null !== $unitIdealParts) {
                    $representedWeight = ExactDecimal::mul(
                        $unitIdealParts,
                        ExactDecimal::div($share, '100.00000000'),
                    );
                }

                $reviewReason = [] === $reviewReasons ? null : implode(' ', array_unique($reviewReasons));
                [$principalType, $principalName, $principalIdentifier] = $this->principalSnapshot($relation);

                $entry = AssemblyElectorateEntry::snapshot(
                    $assembly,
                    $unit,
                    $relation,
                    $unit->getDesignation(),
                    $principalType,
                    $relation->getPerson(),
                    $principalName,
                    $principalIdentifier,
                    $relation->getType()->value,
                    $share,
                    $unitIdealParts,
                    $representedWeight,
                    null !== $representedWeight,
                    $reviewReason,
                    $createdAt,
                );
                $this->entityManager->persist($entry);
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return list<UnitRelation> */
    private function activeOwnerRelations(Unit $unit, DateTimeImmutable $referenceDate): array
    {
        /** @var list<UnitRelation> $relations */
        $relations = $this->entityManager->getRepository(UnitRelation::class)->findBy(
            ['unit' => $unit, 'type' => UnitRelationType::OWNER],
            ['id' => 'ASC'],
        );

        return array_values(array_filter(
            $relations,
            static fn (UnitRelation $relation): bool => $relation->isActiveAt($referenceDate),
        ));
    }

    /** @param list<UnitRelation> $relations */
    private function ownershipGroupReviewReason(array $relations): ?string
    {
        if (1 === count($relations)) {
            $share = $relations[0]->getOwnershipShare();
            if (null !== $share && 0 !== ExactDecimal::compare(ExactDecimal::normalize($share), '100.00000000')) {
                return 'Единственият активен собственик няма 100% дял; данните за собствеността изискват преглед.';
            }

            return null;
        }

        $seenPrincipals = [];
        $sum = '0.00000000';
        foreach ($relations as $relation) {
            $share = $relation->getOwnershipShare();
            if (null === $share) {
                return 'Липсват дялове на един или повече съсобственици; системата не ги разпределя по равно.';
            }

            $key = $this->principalKey($relation);
            if (isset($seenPrincipals[$key])) {
                return 'Има припокриващи се активни отношения за един и същ собственик.';
            }
            $seenPrincipals[$key] = true;
            $sum = ExactDecimal::add($sum, ExactDecimal::normalize($share));
        }

        if (0 !== ExactDecimal::compare($sum, '100.00000000')) {
            return 'Сборът на активните съсобственически дялове не е 100%; данните изискват преглед.';
        }

        return null;
    }

    private function ownershipShareSnapshot(UnitRelation $relation, int $ownerCount): ?string
    {
        $share = $relation->getOwnershipShare();
        if (null !== $share) {
            return ExactDecimal::normalize($share);
        }

        return 1 === $ownerCount ? '100.00000000' : null;
    }

    /** @return array{AssemblyPrincipalType, string, ?string} */
    private function principalSnapshot(UnitRelation $relation): array
    {
        $person = $relation->getPerson();
        if (null !== $person) {
            return [AssemblyPrincipalType::PERSON, $person->getDisplayName(), null];
        }

        $name = $relation->getLegalEntityName();
        $identifier = $relation->getLegalEntityIdentifier();
        if (null === $name || null === $identifier) {
            throw new DomainException('Legal-entity owner relation has incomplete identity data.');
        }

        return [AssemblyPrincipalType::LEGAL_ENTITY, $name, $identifier];
    }

    private function principalKey(UnitRelation $relation): string
    {
        $person = $relation->getPerson();
        if (null !== $person) {
            return 'person:'.($person->getId() ?? spl_object_id($person));
        }

        return 'legal:'.($relation->getLegalEntityIdentifier() ?? spl_object_id($relation));
    }
}
