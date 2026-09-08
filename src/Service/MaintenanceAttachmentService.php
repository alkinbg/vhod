<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceSignal;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class MaintenanceAttachmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaintenanceAttachmentStorage $storage,
    ) {}

    public function add(User $actor, MaintenanceSignal $signal, UploadedFile $file, DateTimeImmutable $uploadedAt): MaintenanceAttachment
    {
        self::assertCanAttach($actor, $signal);
        $stored = $this->storage->store($file);

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $signal, $stored, $uploadedAt): MaintenanceAttachment {
                $attachment = MaintenanceAttachment::record(
                    $signal,
                    $actor,
                    $stored->originalName,
                    $stored->storageName,
                    $stored->mimeType,
                    $stored->sizeBytes,
                    $uploadedAt,
                );
                $entityManager->persist($attachment);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->storage->remove($stored->storageName);
            throw $exception;
        }
    }

    private static function assertCanAttach(User $actor, MaintenanceSignal $signal): void
    {
        if ($signal->getSubmittedBy() === $actor) {
            return;
        }

        $roles = $actor->getRoles();
        if (in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
            return;
        }

        throw new DomainException('You cannot attach files to this maintenance signal.');
    }
}
