<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentSource;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_external_reference', columns: ['external_reference'])]
#[ORM\Index(name: 'idx_payment_unit_received', columns: ['unit_id', 'received_at'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(enumType: PaymentSource::class)]
    private PaymentSource $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reference;

    #[ORM\Column(name: 'external_reference', length: 190, nullable: true)]
    private ?string $externalReference;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    private function __construct(
        Unit $unit,
        int $amountCents,
        PaymentSource $source,
        DateTimeImmutable $receivedAt,
        DateTimeImmutable $postedAt,
        ?string $reference,
        ?string $externalReference,
        ?string $note,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $receivedAt = $receivedAt->setTimezone(new DateTimeZone('UTC'));
        $postedAt = $postedAt->setTimezone(new DateTimeZone('UTC'));
        if ($receivedAt > $postedAt) {
            throw new InvalidArgumentException('Payment cannot be posted before it was received.');
        }

        $this->unit = $unit;
        $this->amountCents = $amountCents;
        $this->source = $source;
        $this->receivedAt = $receivedAt;
        $this->postedAt = $postedAt;
        $this->reference = self::nullableTrim($reference);
        $this->externalReference = self::nullableTrim($externalReference);
        $this->note = self::nullableTrim($note);
    }

    public static function post(
        Unit $unit,
        int $amountCents,
        PaymentSource $source,
        DateTimeImmutable $receivedAt,
        DateTimeImmutable $postedAt,
        ?string $reference = null,
        ?string $externalReference = null,
        ?string $note = null,
    ): self {
        return new self(
            $unit,
            $amountCents,
            $source,
            $receivedAt,
            $postedAt,
            $reference,
            $externalReference,
            $note,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getSource(): PaymentSource { return $this->source; }
    public function getReceivedAt(): DateTimeImmutable { return $this->receivedAt; }
    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }
    public function getReference(): ?string { return $this->reference; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getNote(): ?string { return $this->note; }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
