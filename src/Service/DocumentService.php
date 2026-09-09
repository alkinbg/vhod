<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Security\DocumentAccessPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class DocumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStorage $storage,
        private DocumentAccessPolicy $accessPolicy,
    ) {}

    public function upload(
        User $actor,
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        UploadedFile $file,
        DateTimeImmutable $uploadedAt,
    ): Document {
        if (!$this->accessPolicy->canManageOfficialContent($actor)) {
            throw new DomainException('Management access is required to upload official documents.');
        }

        $stored = $this->storage->store($file);

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $category, $accessLevel, $title, $description, $stored, $uploadedAt): Document {
                $document = Document::record(
                    $category,
                    $accessLevel,
                    $title,
                    $description,
                    $stored->originalName,
                    $stored->storageName,
                    $stored->mimeType,
                    $stored->sizeBytes,
                    $actor,
                    $uploadedAt,
                );
                $entityManager->persist($document);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->storage->remove($stored->storageName);
            throw $exception;
        }
    }
}
