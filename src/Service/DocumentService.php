<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Security\DocumentAccessPolicy;
use App\Value\DocumentStoredFile;
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

        return $this->persistStored($actor, $category, $accessLevel, $title, $description, $stored, $uploadedAt);
    }

    public function recordGenerated(
        User $actor,
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        string $originalName,
        string $pdfBytes,
        DateTimeImmutable $recordedAt,
        bool $allowGovernanceManager = false,
    ): Document {
        $officialManager = $this->accessPolicy->canManageOfficialContent($actor);
        $governanceManager = $allowGovernanceManager
            && $this->accessPolicy->canManageGovernanceEvidence($actor)
            && $this->isSafeGovernanceGeneratedCombination($category, $accessLevel);

        if (!$officialManager && !$governanceManager) {
            throw new DomainException('Management access is required to record generated official documents.');
        }

        $stored = $this->storage->storeGeneratedPdf($originalName, $pdfBytes);

        return $this->persistStored($actor, $category, $accessLevel, $title, $description, $stored, $recordedAt);
    }

    public function uploadGovernanceEvidence(
        User $actor,
        DocumentCategory $category,
        string $title,
        ?string $description,
        UploadedFile $file,
        DateTimeImmutable $uploadedAt,
    ): Document {
        if (!$this->accessPolicy->canManageGovernanceEvidence($actor)) {
            throw new DomainException('Governance management access is required to upload evidence.');
        }

        $stored = $this->storage->store($file);

        return $this->persistStored(
            $actor,
            $category,
            DocumentAccessLevel::GOVERNANCE,
            $title,
            $description,
            $stored,
            $uploadedAt,
        );
    }

    private function persistStored(
        User $actor,
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        DocumentStoredFile $stored,
        DateTimeImmutable $recordedAt,
    ): Document {
        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $category, $accessLevel, $title, $description, $stored, $recordedAt): Document {
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
                    $recordedAt,
                );
                $entityManager->persist($document);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->storage->remove($stored->storageName);
            throw $exception;
        }
    }

    private function isSafeGovernanceGeneratedCombination(
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
    ): bool {
        if (DocumentAccessLevel::RESIDENTS === $accessLevel) {
            return in_array($category, [DocumentCategory::MEETING_INVITATION, DocumentCategory::MEETING_MINUTES], true);
        }

        if (DocumentAccessLevel::GOVERNANCE === $accessLevel) {
            return in_array($category, [DocumentCategory::OTHER, DocumentCategory::MEETING_PROXY], true);
        }

        return false;
    }
}
