<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnimalRegistration;
use App\Entity\BookChangeDeclaration;
use App\Entity\HouseholdMember;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitAbsence;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\BookChangeType;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class BookChangeApplicationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function accept(
        BookChangeDeclaration $declaration,
        User $reviewedBy,
        DateTimeImmutable $reviewedAt,
        ?string $note = null,
    ): void {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($declaration, $reviewedBy, $reviewedAt, $note): void {
            $this->findActiveResidentRelation(
                $declaration->getSubmittedBy()->getPerson(),
                $declaration->getUnit(),
                self::dateOnly($declaration->getSubmittedAt()),
            );

            match ($declaration->getType()) {
                BookChangeType::CONTACT_UPDATE => $this->applyContactUpdate($declaration),
                BookChangeType::HOUSEHOLD_MEMBER_ADD => $this->applyHouseholdMember($declaration, $entityManager),
                BookChangeType::ABSENCE => $this->applyAbsence($declaration, $entityManager),
                BookChangeType::ANIMAL => $this->applyAnimal($declaration, $entityManager),
            };

            $declaration->accept($reviewedBy, $reviewedAt, $note);
            $this->auditLog?->record(
                $reviewedBy,
                'condominium_book.declaration.accepted',
                'BookChangeDeclaration',
                $declaration->getId(),
                $reviewedAt,
                [
                    'change_type' => $declaration->getType()->value,
                    'unit_id' => $declaration->getUnit()->getId(),
                ],
            );
        });
    }

    private function applyContactUpdate(BookChangeDeclaration $declaration): void
    {
        $payload = $declaration->getPayload();
        $person = $declaration->getSubmittedBy()->getPerson();

        $email = array_key_exists('email', $payload)
            ? self::optionalString($payload, 'email')
            : $person->getEmail();
        $phone = array_key_exists('phone', $payload)
            ? self::optionalString($payload, 'phone')
            : $person->getPhone();

        $person->updateContact($email, $phone);
    }

    private function applyHouseholdMember(BookChangeDeclaration $declaration, EntityManagerInterface $entityManager): void
    {
        $payload = $declaration->getPayload();
        $validFrom = self::requiredDate($payload, 'validFrom');
        $relation = $this->findActiveResidentRelation(
            $declaration->getSubmittedBy()->getPerson(),
            $declaration->getUnit(),
            $validFrom,
        );

        $person = new Person(
            self::requiredString($payload, 'firstName'),
            self::requiredString($payload, 'lastName'),
        );

        $entityManager->persist($person);
        $entityManager->persist(new HouseholdMember($person, $relation, $validFrom));
    }

    private function applyAbsence(BookChangeDeclaration $declaration, EntityManagerInterface $entityManager): void
    {
        $payload = $declaration->getPayload();

        $entityManager->persist(new UnitAbsence(
            $declaration->getSubmittedBy()->getPerson(),
            $declaration->getUnit(),
            self::requiredDate($payload, 'validFrom'),
            self::optionalDate($payload, 'validUntil'),
        ));
    }

    private function applyAnimal(BookChangeDeclaration $declaration, EntityManagerInterface $entityManager): void
    {
        $payload = $declaration->getPayload();

        $entityManager->persist(new AnimalRegistration(
            $declaration->getUnit(),
            self::requiredString($payload, 'species'),
            self::requiredPositiveInt($payload, 'count'),
            self::optionalString($payload, 'passport'),
        ));
    }

    private function findActiveResidentRelation(Person $person, Unit $unit, DateTimeImmutable $at): UnitRelation
    {
        $relations = $this->entityManager->getRepository(UnitRelation::class)->findBy([
            'person' => $person,
            'unit' => $unit,
        ]);

        foreach ($relations as $relation) {
            if (!in_array($relation->getType(), [UnitRelationType::OWNER, UnitRelationType::USER], true)) {
                continue;
            }

            if ($relation->isActiveAt($at)) {
                return $relation;
            }
        }

        throw new DomainException('The declaration submitter has no active owner or user relation to this unit.');
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = self::optionalString($payload, $key);

        if (null === $value) {
            throw new DomainException(sprintf('Declaration payload field "%s" is missing or empty.', $key));
        }

        return $value;
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new DomainException(sprintf('Declaration payload field "%s" must be a string.', $key));
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function requiredPositiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (!is_int($value) || $value <= 0) {
            throw new DomainException(sprintf('Declaration payload field "%s" must be a positive integer.', $key));
        }

        return $value;
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function requiredDate(array $payload, string $key): DateTimeImmutable
    {
        $value = self::requiredString($payload, $key);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new DomainException(sprintf('Declaration payload field "%s" must be a valid YYYY-MM-DD date.', $key));
        }

        return $date;
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function optionalDate(array $payload, string $key): ?DateTimeImmutable
    {
        $value = self::optionalString($payload, $key);

        if (null === $value) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new DomainException(sprintf('Declaration payload field "%s" must be a valid YYYY-MM-DD date.', $key));
        }

        return $date;
    }

    private static function dateOnly(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0);
    }
}
