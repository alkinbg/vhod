<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExternalIncomeCategory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'external_income')]
#[ORM\Index(name: 'idx_external_income_received_at', columns: ['received_at'])]
#[ORM\Index(name: 'idx_external_income_fund_received', columns: ['fund_id', 'received_at'])]
class ExternalIncome
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Fund $fund;

    #[ORM\Column(enumType: ExternalIncomeCategory::class)]
    private ExternalIncomeCategory $category;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $documentReference;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    private function __construct(
        Fund $fund,
        ExternalIncomeCategory $category,
        int $amountCents,
        DateTimeImmutable $receivedAt,
        DateTimeImmutable $postedAt,
        string $description,
        ?string $documentReference,
        ?string $note,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('External income amount must be positive.');
        }

        $description = trim($description);
        if ('' === $description) {
            throw new InvalidArgumentException('External income description is required.');
        }

        $receivedAt = self::toUtc($receivedAt);
        $postedAt = self::toUtc($postedAt);
        if ($receivedAt > $postedAt) {
            throw new InvalidArgumentException('External income cannot be posted before receipt.');
        }

        $this->fund = $fund;
        $this->category = $category;
        $this->amountCents = $amountCents;
        $this->receivedAt = $receivedAt;
        $this->postedAt = $postedAt;
        $this->description = $description;
        $this->documentReference = self::nullableTrim($documentReference);
        $this->note = self::nullableTrim($note);
    }

    public static function record(
        Fund $fund,
        ExternalIncomeCategory $category,
        int $amountCents,
        DateTimeImmutable $receivedAt,
        DateTimeImmutable $postedAt,
        string $description,
        ?string $documentReference = null,
        ?string $note = null,
    ): self {
        return new self(
            $fund,
            $category,
            $amountCents,
            $receivedAt,
            $postedAt,
            $description,
            $documentReference,
            $note,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getFund(): Fund { return $this->fund; }
    public function getCategory(): ExternalIncomeCategory { return $this->category; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getReceivedAt(): DateTimeImmutable { return $this->receivedAt; }
    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }
    public function getDescription(): string { return $this->description; }
    public function getDocumentReference(): ?string { return $this->documentReference; }
    public function getNote(): ?string { return $this->note; }

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
