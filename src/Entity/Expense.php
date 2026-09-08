<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExpenseCategory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'expense')]
#[ORM\Index(name: 'idx_expense_paid_at', columns: ['paid_at'])]
#[ORM\Index(name: 'idx_expense_fund_paid', columns: ['fund_id', 'paid_at'])]
class Expense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Fund $fund;

    #[ORM\Column(enumType: ExpenseCategory::class)]
    private ExpenseCategory $category;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $paidAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payee;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $documentReference;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $decisionReference;

    private function __construct(
        Fund $fund,
        ExpenseCategory $category,
        int $amountCents,
        DateTimeImmutable $paidAt,
        DateTimeImmutable $postedAt,
        string $description,
        ?string $payee,
        ?string $documentReference,
        ?string $note,
        ?string $decisionReference,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Expense amount must be positive.');
        }

        $description = trim($description);
        if ('' === $description) {
            throw new InvalidArgumentException('Expense description is required.');
        }

        $paidAt = self::toUtc($paidAt);
        $postedAt = self::toUtc($postedAt);
        if ($paidAt > $postedAt) {
            throw new InvalidArgumentException('Expense cannot be posted before it was paid.');
        }

        $this->fund = $fund;
        $this->category = $category;
        $this->amountCents = $amountCents;
        $this->paidAt = $paidAt;
        $this->postedAt = $postedAt;
        $this->description = $description;
        $this->payee = self::nullableTrim($payee);
        $this->documentReference = self::nullableTrim($documentReference);
        $this->note = self::nullableTrim($note);
        $this->decisionReference = self::nullableTrim($decisionReference);
    }

    public static function post(
        Fund $fund,
        ExpenseCategory $category,
        int $amountCents,
        DateTimeImmutable $paidAt,
        DateTimeImmutable $postedAt,
        string $description,
        ?string $payee = null,
        ?string $documentReference = null,
        ?string $note = null,
        ?string $decisionReference = null,
    ): self {
        return new self(
            $fund,
            $category,
            $amountCents,
            $paidAt,
            $postedAt,
            $description,
            $payee,
            $documentReference,
            $note,
            $decisionReference,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getFund(): Fund { return $this->fund; }
    public function getCategory(): ExpenseCategory { return $this->category; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getPaidAt(): DateTimeImmutable { return $this->paidAt; }
    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }
    public function getDescription(): string { return $this->description; }
    public function getPayee(): ?string { return $this->payee; }
    public function getDocumentReference(): ?string { return $this->documentReference; }
    public function getNote(): ?string { return $this->note; }
    public function getDecisionReference(): ?string { return $this->decisionReference; }

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
