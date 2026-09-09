<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Repository\DocumentRepository;
use App\Repository\OfficialAnnouncementRepository;
use App\Security\DocumentAccessPolicy;
use App\Service\AnnouncementReceiptService;
use App\Service\OfficialAnnouncementService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnnouncementManagementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OfficialAnnouncementRepository $announcements,
        private readonly AnnouncementReceiptService $receipts,
        private readonly DocumentRepository $documents,
        private readonly DocumentAccessPolicy $documentAccessPolicy,
        private readonly OfficialAnnouncementService $announcementService,
    ) {}

    #[Route('/management/announcements', name: 'app_management_announcements', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireManager();

        return $this->renderIndex();
    }

    #[Route('/management/announcement/new', name: 'app_management_announcement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $manager = $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;
        $selectedDocumentIds = [];

        if ($request->isMethod('POST')) {
            $this->requireCsrf('announcement_create', $request);
            $selectedDocumentIds = $this->submittedDocumentIds($request);

            try {
                $this->announcementService->createDraft(
                    $manager,
                    $request->request->getString('title'),
                    $request->request->getString('body'),
                    $this->nowUtc(),
                    $this->residentDocumentsByIds($selectedDocumentIds),
                );
                $this->addFlash('success', 'Официалната обява е записана като чернова.');

                return $this->redirectToRoute('app_management_announcements');
            } catch (InvalidArgumentException|LogicException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->renderForm(
            null,
            $selectedDocumentIds,
            'announcement_create',
            $error,
            $status,
        );
    }

    #[Route('/management/announcement/{id}/edit', name: 'app_management_announcement_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $manager = $this->requireManager();
        $announcement = $this->requireAnnouncement($id);

        if ($announcement->isPublished()) {
            return $this->renderForm(
                $announcement,
                $this->documentIds($announcement),
                'announcement_edit_'.$id,
                'Публикувана обява не може да бъде редактирана.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $error = null;
        $status = Response::HTTP_OK;
        $selectedDocumentIds = $this->documentIds($announcement);

        if ($request->isMethod('POST')) {
            $this->requireCsrf('announcement_edit_'.$id, $request);
            $selectedDocumentIds = $this->submittedDocumentIds($request);

            try {
                $this->announcementService->revise(
                    $manager,
                    $announcement,
                    $request->request->getString('title'),
                    $request->request->getString('body'),
                    $this->residentDocumentsByIds($selectedDocumentIds),
                );
                $this->addFlash('success', 'Черновата е обновена.');

                return $this->redirectToRoute('app_management_announcements');
            } catch (InvalidArgumentException|LogicException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->renderForm(
            $announcement,
            $selectedDocumentIds,
            'announcement_edit_'.$id,
            $error,
            $status,
        );
    }

    #[Route('/management/announcement/{id}/publish', name: 'app_management_announcement_publish', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        $manager = $this->requireManager();
        $this->requireCsrf('announcement_publish_'.$id, $request);
        $announcement = $this->requireAnnouncement($id);

        try {
            $this->announcementService->publish($manager, $announcement, $this->nowUtc());
            $this->addFlash('success', 'Официалната обява е публикувана.');

            return $this->redirectToRoute('app_management_announcements');
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->renderIndex($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function renderIndex(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        /** @var list<OfficialAnnouncement> $announcements */
        $announcements = $this->announcements->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
        $receiptCounts = [];
        foreach ($announcements as $announcement) {
            if ($announcement->isPublished() && null !== $announcement->getId()) {
                $receiptCounts[$announcement->getId()] = $this->receipts->countsFor($announcement);
            }
        }

        return $this->render('management/announcements/index.html.twig', [
            'announcements' => $announcements,
            'receipt_counts' => $receiptCounts,
            'error' => $error,
        ], new Response(status: $status));
    }

    /** @param list<int> $selectedDocumentIds */
    private function renderForm(
        ?OfficialAnnouncement $announcement,
        array $selectedDocumentIds,
        string $csrfTokenId,
        ?string $error,
        int $status,
    ): Response {
        return $this->render('management/announcements/form.html.twig', [
            'announcement' => $announcement,
            'documents' => $this->documents->findVisible([DocumentAccessLevel::RESIDENTS]),
            'selected_document_ids' => $selectedDocumentIds,
            'csrf_token_id' => $csrfTokenId,
            'error' => $error,
        ], new Response(status: $status));
    }

    /**
     * @param list<int> $ids
     * @return list<Document>
     */
    private function residentDocumentsByIds(array $ids): array
    {
        $documents = [];
        foreach ($ids as $id) {
            $document = $this->entityManager->find(Document::class, $id);
            if (!$document instanceof Document || DocumentAccessLevel::RESIDENTS !== $document->getAccessLevel()) {
                throw new InvalidArgumentException('Към официална обява могат да се прикачват само документи, достъпни за всички живущи.');
            }
            $documents[$id] = $document;
        }

        return array_values($documents);
    }

    /** @return list<int> */
    private function submittedDocumentIds(Request $request): array
    {
        $values = $request->request->all('document_ids');
        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('Невалиден документ.');
            }
            $value = (string) $value;
            if (1 !== preg_match('/^[1-9]\\d*$/', $value)) {
                throw new InvalidArgumentException('Невалиден документ.');
            }
            $ids[(int) $value] = (int) $value;
        }

        return array_values($ids);
    }

    /** @return list<int> */
    private function documentIds(OfficialAnnouncement $announcement): array
    {
        $ids = [];
        foreach ($announcement->getDocuments() as $document) {
            if (null !== $document->getId()) {
                $ids[] = $document->getId();
            }
        }

        return $ids;
    }

    private function requireAnnouncement(int $id): OfficialAnnouncement
    {
        $announcement = $this->entityManager->find(OfficialAnnouncement::class, $id);
        if (!$announcement instanceof OfficialAnnouncement) {
            throw $this->createNotFoundException('Official announcement not found.');
        }

        return $announcement;
    }

    private function requireManager(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }
        if (!$this->documentAccessPolicy->canManageOfficialContent($user)) {
            throw $this->createAccessDeniedException('Management access is required.');
        }

        return $user;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
