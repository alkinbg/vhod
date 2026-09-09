<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Repository\DocumentRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_access_category_uploaded', columns: ['access_level', 'category', 'uploaded_at'])]
#[ORM\Index(name: 'idx_document_uploaded_by', columns: ['uploaded_by_id'])]
#[ORM\UniqueConstraint(name: 'uniq_document_storage_name', columns: ['storage_name'])]
class Document
{
    private const MAX_SIZE = 16 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: DocumentCategory::class)]
    private DocumentCategory $category;

    #[ORM\Column(length: 24, enumType: DocumentAccessLevel::class)]
    private DocumentAccessLevel $accessLevel;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 40)]
    private string $storageName;

    #[ORM\Column(length: 80)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_document_uploaded_by')]
    private User $uploadedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $uploadedAt;

    private function __construct(
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        string $originalName,
        string $storageName,
        string $mimeType,
        int $sizeBytes,
        User $uploadedBy,
        DateTimeImmutable $uploadedAt,
    ) {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('Document title must contain between 1 and 180 characters.');
        }

        $description = self::nullableTrim($description);
        if (null !== $description && mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('Document description cannot exceed 2000 characters.');
        }

        $originalName = trim($originalName);
        if ('' === $originalName || mb_strlen($originalName) > 255) {
            throw new InvalidArgumentException('Document original name must contain between 1 and 255 characters.');
        }
        if (1 !== preg_match('/^[a-f0-9]{32}\.(?:pdf|jpg|png|webp)$/', $storageName)) {
            throw new InvalidArgumentException('Invalid document storage name.');
        }
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported document MIME type.');
        }
        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_SIZE) {
            throw new InvalidArgumentException('Document size is invalid.');
        }

        $this->category = $category;
        $this->accessLevel = $accessLevel;
        $this->title = $title;
        $this->description = $description;
        $this->originalName = $originalName;
        $this->storageName = $storageName;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = self::toUtc($uploadedAt);
    }

    public static function record(
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        string $originalName,
        string $storageName,
        string $mimeType,
        int $sizeBytes,
        User $uploadedBy,
        DateTimeImmutable $uploadedAt,
    ): self {
        return new self(
            $category,
            $accessLevel,
            $title,
            $description,
            $originalName,
            $storageName,
            $mimeType,
            $sizeBytes,
            $uploadedBy,
            $uploadedAt,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): DocumentCategory { return $this->category; }
    public function getAccessLevel(): DocumentAccessLevel { return $this->accessLevel; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStorageName(): string { return $this->storageName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getUploadedBy(): User { return $this->uploadedBy; }
    public function getUploadedAt(): DateTimeImmutable { return $this->uploadedAt; }

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
