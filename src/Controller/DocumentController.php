<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentCategory;
use App\Repository\DocumentRepository;
use App\Security\DocumentAccessPolicy;
use App\Service\DocumentStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentAccessPolicy $accessPolicy,
        private readonly DocumentStorage $storage,
    ) {}

    #[Route('/documents', name: 'app_documents_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $categoryValue = trim($request->query->getString('category'));
        $category = null;

        if ('' !== $categoryValue) {
            $category = DocumentCategory::tryFrom($categoryValue);
            if (!$category instanceof DocumentCategory) {
                throw $this->createNotFoundException('Document category not found.');
            }
        }

        return $this->render('documents/index.html.twig', [
            'documents' => $this->documentRepository->findVisible($this->accessPolicy->allowedLevels($user), $category),
            'categories' => DocumentCategory::cases(),
            'selected_category' => $category,
        ]);
    }

    #[Route('/document/{id}/download', name: 'app_document_download', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function download(int $id): Response
    {
        $user = $this->requireUser();
        $document = $this->documentRepository->find($id);
        if (!$document instanceof Document || !$this->accessPolicy->canView($user, $document)) {
            throw $this->createNotFoundException('Document not found.');
        }

        $path = $this->storage->pathFor($document->getStorageName());
        if (!is_file($path)) {
            throw $this->createNotFoundException('Document file not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName(),
        );

        return $response;
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        return $user;
    }
}
