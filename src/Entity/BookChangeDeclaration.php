<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BookChangeType;
use App\Enum\BookDeclarationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Index(columns: ['status', 'submitted_at'], name: 'idx_book_declaration_queue')]
class BookChangeDeclaration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $submittedBy;

    #[ORM\Column(enumType: BookChangeType::class)]
    private BookChangeType $type;

    /** @var array<string, bool|int|float|string|null> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(enumType: BookDeclarationStatus::class)]
    private BookDeclarationStatus $status = BookDeclarationStatus::SUBMITTED;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $submittedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNote = null;

    /**
     * @param array<string, bool|int|float|string|null> $payload
     */
    private function __construct(
        Unit $unit,
        User $submittedBy,
        BookChangeType $type,
        array $payload,
        DateTimeImmutable $submittedAt,
    ) {
        self::validatePayload($type, $payload);

        $this->unit = $unit;
        $this->submittedBy = $submittedBy;
        $this->type = $type;
        $this->payload = $payload;
        $this->submittedAt = self::toUtc($submittedAt);
    }

    /**
     * @param array<string, bool|int|float|string|null> $payload
     */
    public static function submit(
        Unit $unit,
        User $submittedBy,
        BookChangeType $type,
        array $payload,
        DateTimeImmutable $submittedAt,
    ): self {
        return new self($unit, $submittedBy, $type, $payload, $submittedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function getSubmittedBy(): User { return $this->submittedBy; }
    public function getType(): BookChangeType { return $this->type; }

    /** @return array<string, bool|int|float|string|null> */
    public function getPayload(): array { return $this->payload; }

    public function getStatus(): BookDeclarationStatus { return $this->status; }
    public function getSubmittedAt(): DateTimeImmutable { return $this->submittedAt; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function getReviewedAt(): ?DateTimeImmutable { return $this->reviewedAt; }
    public function getReviewNote(): ?string { return $this->reviewNote; }

    public function accept(User $reviewedBy, DateTimeImmutable $reviewedAt, ?string $note = null): void
    {
        $this->review(BookDeclarationStatus::ACCEPTED, $reviewedBy, $reviewedAt, $note, false);
    }

    public function reject(User $reviewedBy, DateTimeImmutable $reviewedAt, string $reason): void
    {
        $this->review(BookDeclarationStatus::REJECTED, $reviewedBy, $reviewedAt, $reason, true);
    }

    private function review(
        BookDeclarationStatus $status,
        User $reviewedBy,
        DateTimeImmutable $reviewedAt,
        ?string $note,
        bool $noteRequired,
    ): void {
        if (BookDeclarationStatus::SUBMITTED !== $this->status) {
            throw new DomainException('Declaration has already been reviewed.');
        }

        if (!self::canReview($reviewedBy)) {
            throw new DomainException('Only a manager or administrator can review declarations.');
        }

        $reviewedAt = self::toUtc($reviewedAt);
        if ($reviewedAt < $this->submittedAt) {
            throw new InvalidArgumentException('Review time cannot precede submission time.');
        }

        $note = self::nullableTrim($note);
        if ($noteRequired && null === $note) {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $this->status = $status;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
        $this->reviewNote = $note;
    }

    /**
     * @param array<string, bool|int|float|string|null> $payload
     */
    private static function validatePayload(BookChangeType $type, array $payload): void
    {
        match ($type) {
            BookChangeType::CONTACT_UPDATE => self::validateContactPayload($payload),
            BookChangeType::HOUSEHOLD_MEMBER_ADD => self::validateHouseholdPayload($payload),
            BookChangeType::ABSENCE => self::validateAbsencePayload($payload),
            BookChangeType::ANIMAL => self::validateAnimalPayload($payload),
        };
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function validateContactPayload(array $payload): void
    {
        $email = self::payloadString($payload, 'email');
        $phone = self::payloadString($payload, 'phone');

        if (null === $email && null === $phone) {
            throw new InvalidArgumentException('Email or phone is required.');
        }

        if (null !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function validateHouseholdPayload(array $payload): void
    {
        if (null === self::payloadString($payload, 'firstName') || null === self::payloadString($payload, 'lastName')) {
            throw new InvalidArgumentException('Household member first and last name are required.');
        }

        self::requiredDate($payload, 'validFrom');
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function validateAbsencePayload(array $payload): void
    {
        $from = self::requiredDate($payload, 'validFrom');
        $until = self::optionalDate($payload, 'validUntil');

        if (null !== $until && $until < $from) {
            throw new InvalidArgumentException('Absence cannot end before it starts.');
        }
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function validateAnimalPayload(array $payload): void
    {
        if (null === self::payloadString($payload, 'species')) {
            throw new InvalidArgumentException('Animal species is required.');
        }

        $count = $payload['count'] ?? null;
        if (!is_int($count) || $count <= 0) {
            throw new InvalidArgumentException('Animal count must be a positive integer.');
        }
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function requiredDate(array $payload, string $key): DateTimeImmutable
    {
        $value = self::payloadString($payload, $key);
        if (null === $value) {
            throw new InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return self::parseDate($value, $key);
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function optionalDate(array $payload, string $key): ?DateTimeImmutable
    {
        $value = self::payloadString($payload, $key);

        return null === $value ? null : self::parseDate($value, $key);
    }

    private static function parseDate(string $value, string $key): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new InvalidArgumentException(sprintf('%s must be a valid YYYY-MM-DD date.', $key));
        }

        return $date;
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private static function payloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $key));
        }

        return self::nullableTrim($value);
    }

    private static function canReview(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
