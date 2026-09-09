<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_attachment')]
#[ORM\Index(name: 'idx_maintenance_attachment_signal', columns: ['signal_id'])]
#[ORM\Index(name: 'idx_maintenance_attachment_uploaded_by', columns: ['uploaded_by_id'])]
#[ORM\Index(name: 'idx_maintenance_attachment_uploaded_at', columns: ['uploaded_at'])]
class MaintenanceAttachment
{
    private const MAX_SIZE = 8 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'signal_id', nullable: false, onDelete: 'RESTRICT')]
    private MaintenanceSignal $signal;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 40, unique: true)]
    private string $storageName;

    #[ORM\Column(length: 80)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $uploadedAt;

    private function __construct(
        MaintenanceSignal $signal,
        User $uploadedBy,
        string $originalName,
        string $storageName,
        string $mimeType,
        int $sizeBytes,
        DateTimeImmutable $uploadedAt,
    ) {
        $originalName = trim($originalName);
        if ('' === $originalName || mb_strlen($originalName) > 255) {
            throw new InvalidArgumentException('Attachment original name must contain between 1 and 255 characters.');
        }
        if (1 !== preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/', $storageName)) {
            throw new InvalidArgumentException('Invalid maintenance attachment storage name.');
        }
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported maintenance attachment MIME type.');
        }
        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_SIZE) {
            throw new InvalidArgumentException('Maintenance attachment size is invalid.');
        }

        $this->signal = $signal;
        $this->uploadedBy = $uploadedBy;
        $this->originalName = $originalName;
        $this->storageName = $storageName;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->uploadedAt = $uploadedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function record(
        MaintenanceSignal $signal,
        User $uploadedBy,
        string $originalName,
        string $storageName,
        string $mimeType,
        int $sizeBytes,
        DateTimeImmutable $uploadedAt,
    ): self {
        return new self($signal, $uploadedBy, $originalName, $storageName, $mimeType, $sizeBytes, $uploadedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getSignal(): MaintenanceSignal { return $this->signal; }
    public function getUploadedBy(): User { return $this->uploadedBy; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStorageName(): string { return $this->storageName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getUploadedAt(): DateTimeImmutable { return $this->uploadedAt; }
}
