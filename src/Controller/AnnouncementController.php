<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AnnouncementReceipt;
use App\Entity\OfficialAnnouncement;
use App\Entity\User;
use App\Repository\AnnouncementReceiptRepository;
use App\Repository\OfficialAnnouncementRepository;
use App\Service\AnnouncementPdfService;
use App\Service\AnnouncementReceiptService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AnnouncementController extends AbstractController
{
    public function __construct(
        private readonly OfficialAnnouncementRepository $announcements,
        private readonly AnnouncementReceiptRepository $receipts,
        private readonly AnnouncementReceiptService $receiptService,
        private readonly AnnouncementPdfService $pdfService,
    ) {}

    #[Route('/announcements', name: 'app_announcements_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireUser();

        return $this->render('announcements/index.html.twig', [
            'announcements' => $this->announcements->findPublished(),
            'unread_announcement_ids' => $this->receiptService->unreadAnnouncementIds($user),
        ]);
    }

    #[Route('/announcement/{id}', name: 'app_announcement_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->requireUser();
        $announcement = $this->requirePublished($id);

        return $this->render('announcements/show.html.twig', [
            'announcement' => $announcement,
            'receipt' => $this->receipts->findFor($user, $announcement),
            'viber_share_url' => $this->viberShareUrl($announcement),
        ]);
    }

    #[Route('/announcement/{id}/read', name: 'app_announcement_read', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function read(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $announcement = $this->requirePublished($id);

        if (!$this->isCsrfTokenValid('announcement_read_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->receiptService->markRead($user, $announcement, $this->nowUtc());

        return $this->redirectToRoute('app_announcement_show', ['id' => $id]);
    }

    #[Route('/announcement/{id}/print', name: 'app_announcement_print', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function print(int $id): Response
    {
        $this->requireUser();

        return $this->render('announcements/print.html.twig', [
            'announcement' => $this->requirePublished($id),
        ]);
    }

    #[Route('/announcement/{id}/pdf', name: 'app_announcement_pdf', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $this->requireUser();
        $announcement = $this->requirePublished($id);

        return new Response($this->pdfService->render($announcement), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=announcement-'.$id.'.pdf',
        ]);
    }

    private function requirePublished(int $id): OfficialAnnouncement
    {
        $announcement = $this->announcements->find($id);
        if (!$announcement instanceof OfficialAnnouncement || !$announcement->isPublished()) {
            throw $this->createNotFoundException('Official announcement not found.');
        }

        return $announcement;
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        return $user;
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function viberShareUrl(OfficialAnnouncement $announcement): ?string
    {
        $id = $announcement->getId();
        if (null === $id || !$announcement->isPublished()) {
            return null;
        }

        $url = $this->generateUrl(
            'app_announcement_show',
            ['id' => $id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        if (mb_strlen($url) > 200) {
            return null;
        }

        $separator = ' — ';
        $budget = max(0, 200 - mb_strlen($separator) - mb_strlen($url));
        $title = mb_substr($announcement->getTitle(), 0, $budget);
        $text = '' === $title ? $url : $title.$separator.$url;

        return 'viber://forward?text='.rawurlencode($text);
    }
}
