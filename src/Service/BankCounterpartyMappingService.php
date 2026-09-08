<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BankCounterpartyMapping;
use App\Entity\Unit;
use App\Value\Iban;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class BankCounterpartyMappingService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function assign(string $counterpartyIban, Unit $unit, DateTimeImmutable $createdAt): BankCounterpartyMapping
    {
        if (null === $unit->getId()) {
            throw new DomainException('Unit must be persisted before assigning a counterparty mapping.');
        }
        if (!$unit->isActive()) {
            throw new DomainException('Counterparty mapping requires an active unit.');
        }

        $counterpartyIban = Iban::normalize($counterpartyIban);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $counterpartyIban,
            $unit,
            $createdAt,
        ): BankCounterpartyMapping {
            $existing = $this->findActive($counterpartyIban);
            if (null !== $existing) {
                if (!self::sameUnit($existing->getUnit(), $unit)) {
                    throw new DomainException('Counterparty IBAN is already mapped to another unit.');
                }

                return $existing;
            }

            $mapping = BankCounterpartyMapping::create($counterpartyIban, $unit, $createdAt);
            $entityManager->persist($mapping);
            $entityManager->flush();

            return $mapping;
        });
    }

    private function findActive(string $counterpartyIban): ?BankCounterpartyMapping
    {
        $mapping = $this->entityManager->getRepository(BankCounterpartyMapping::class)->findOneBy([
            'counterpartyIban' => $counterpartyIban,
            'active' => true,
        ]);

        return $mapping instanceof BankCounterpartyMapping ? $mapping : null;
    }

    private static function sameUnit(Unit $left, Unit $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftId = $left->getId();
        $rightId = $right->getId();

        return null !== $leftId && null !== $rightId && $leftId === $rightId;
    }
}
