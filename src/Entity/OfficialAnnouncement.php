<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentAccessLevel;
use App\Enum\OfficialAnnouncementStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;
use LogicException;

#[ORM\Entity]
#[ORM\Table(name: 'official_announcement')]
class OfficialAnnouncement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, enumType: OfficialAnnouncementStatus::class)]
    private OfficialAnnouncementStatus $status;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_by_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $publishedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, Document> */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'official_announcement_document')]
    #[ORM\JoinColumn(name: 'announcement_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ORM\InverseJoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Collection $documents;

    /** @param iterable<Document> $documents */
    private function __construct(
        string $title,
        string $body,
        User $createdBy,
        DateTimeImmutable $createdAt,
        iterable $documents,
    ) {
        [$title, $body] = self::normalizeContent($title, $body);

        $this->status = OfficialAnnouncementStatus::DRAFT;
        $this->title = $title;
        $this->body = $body;
        $this->createdBy = $createdBy;
        $this->createdAt = self::toUtc($createdAt);
        $this->documents = self::validatedDocuments($documents);
    }

    /** @param iterable<Document> $documents */
    public static function draft(
        string $title,
        string $body,
        User $createdBy,
        DateTimeImmutable $createdAt,
        iterable $documents = [],
    ): self {
        return new self($title, $body, $createdBy, $createdAt, $documents);
    }

    /** @param iterable<Document> $documents */
    public function revise(string $title, string $body, iterable $documents): void
    {
        $this->assertDraft();
        [$title, $body] = self::normalizeContent($title, $body);
        $documents = self::validatedDocuments($documents);

        $this->title = $title;
        $this->body = $body;
        $this->documents = $documents;
    }

    public function publish(User $publishedBy, DateTimeImmutable $publishedAt): void
    {
        $this->assertDraft();
        $publishedAt = self::toUtc($publishedAt);
        if ($publishedAt < $this->createdAt) {
            throw new InvalidArgumentException('Announcement cannot be published before it was created.');
        }

        $this->status = OfficialAnnouncementStatus::PUBLISHED;
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $publishedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): OfficialAnnouncementStatus { return $this->status; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getPublishedBy(): ?User { return $this->publishedBy; }
    public function getPublishedAt(): ?DateTimeImmutable { return $this->publishedAt; }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection { return $this->documents; }

    public function isPublished(): bool
    {
        return OfficialAnnouncementStatus::PUBLISHED === $this->status;
    }

    private function assertDraft(): void
    {
        if (OfficialAnnouncementStatus::DRAFT !== $this->status) {
            throw new LogicException('Published announcement is immutable.');
        }
    }

    /** @return array{string, string} */
    private static function normalizeContent(string $title, string $body): array
    {
        $title = trim($title);
        $body = trim($body);

        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('Announcement title must contain between 1 and 180 characters.');
        }
        if ('' === $body || mb_strlen($body) > 20000) {
            throw new InvalidArgumentException('Announcement body must contain between 1 and 20000 characters.');
        }

        return [$title, $body];
    }

    /**
     * @param iterable<Document> $documents
     * @return Collection<int, Document>
     */
    private static function validatedDocuments(iterable $documents): Collection
    {
        $collection = new ArrayCollection();
        foreach ($documents as $document) {
            if (DocumentAccessLevel::RESIDENTS !== $document->getAccessLevel()) {
                throw new DomainException('Official announcements may link only resident-visible documents.');
            }
            if (!$collection->contains($document)) {
                $collection->add($document);
            }
        }

        return $collection;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
