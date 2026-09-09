<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Repository\DocumentRepository;
use App\Security\DocumentAccessPolicy;
use App\Service\DocumentService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentManagementController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentAccessPolicy $accessPolicy,
        private readonly DocumentService $documentService,
    ) {}

    #[Route('/management/documents', name: 'app_management_documents', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireManager();

        return $this->render('management/documents/index.html.twig', [
            'documents' => $this->documentRepository->findBy([], ['uploadedAt' => 'DESC', 'id' => 'DESC']),
        ]);
    }

    #[Route('/management/document/new', name: 'app_management_document_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);

            try {
                $category = DocumentCategory::tryFrom($request->request->getString('category'));
                $accessLevel = DocumentAccessLevel::tryFrom($request->request->getString('access_level'));
                if (!$category instanceof DocumentCategory || !$accessLevel instanceof DocumentAccessLevel) {
                    throw new InvalidArgumentException('Невалидна категория или ниво на достъп.');
                }

                $file = $request->files->get('document_file');
                if (!$file instanceof UploadedFile) {
                    throw new InvalidArgumentException('Изберете файл за качване.');
                }

                $description = trim($request->request->getString('description'));
                $this->documentService->upload(
                    $actor,
                    $category,
                    $accessLevel,
                    $request->request->getString('title'),
                    '' === $description ? null : $description,
                    $file,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
                $this->addFlash('success', 'Документът е качен успешно.');

                return $this->redirectToRoute('app_management_documents');
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/documents/new.html.twig', [
            'categories' => DocumentCategory::cases(),
            'access_levels' => DocumentAccessLevel::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function requireManager(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->accessPolicy->canManageOfficialContent($user)) {
            throw $this->createAccessDeniedException('Management access is required.');
        }

        return $user;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('document_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
