<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(name: 'idx_audit_entry_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_entry_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_entry_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_audit_entry_actor', columns: ['actor_id'])]
final class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_audit_entry_actor')]
    private ?User $actor;

    #[ORM\Column(name: 'actor_identifier', length: 180)]
    private string $actorIdentifier;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(name: 'subject_type', length: 120)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id', nullable: true)]
    private ?int $subjectId;

    /** @var array<string, bool|int|float|string|null> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    /** @param array<string, bool|int|float|string|null> $context */
    private function __construct(
        ?User $actor,
        string $action,
        string $subjectType,
        ?int $subjectId,
        DateTimeImmutable $occurredAt,
        array $context,
    ) {
        $action = trim($action);
        $subjectType = trim($subjectType);
        if ('' === $action) {
            throw new InvalidArgumentException('Audit action cannot be blank.');
        }
        if ('' === $subjectType) {
            throw new InvalidArgumentException('Audit subject type cannot be blank.');
        }
        if (null !== $subjectId && $subjectId <= 0) {
            throw new InvalidArgumentException('Audit subject id must be positive when provided.');
        }

        $this->actor = $actor;
        $this->actorIdentifier = $actor?->getUserIdentifier() ?? 'system';
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
        $this->context = $context;
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public static function record(
        ?User $actor,
        string $action,
        string $subjectType,
        ?int $subjectId,
        DateTimeImmutable $occurredAt,
        array $context = [],
    ): self {
        return new self($actor, $action, $subjectType, $subjectId, $occurredAt, $context);
    }

    public function getId(): ?int { return $this->id; }
    public function getActor(): ?User { return $this->actor; }
    public function getActorIdentifier(): string { return $this->actorIdentifier; }
    public function getAction(): string { return $this->action; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): ?int { return $this->subjectId; }

    /** @return array<string, bool|int|float|string|null> */
    public function getContext(): array { return $this->context; }

    public function getOccurredAt(): DateTimeImmutable { return $this->occurredAt; }
}
