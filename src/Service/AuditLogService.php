<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEntry;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AuditLogService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public function record(
        ?User $actor,
        string $action,
        string $subjectType,
        ?int $subjectId,
        DateTimeImmutable $occurredAt,
        array $context = [],
    ): AuditEntry {
        $entry = AuditEntry::record($actor, $action, $subjectType, $subjectId, $occurredAt, $context);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
