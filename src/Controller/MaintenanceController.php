<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Service\MaintenanceAttachmentService;
use App\Service\MaintenanceAttachmentStorage;
use App\Service\MaintenanceSignalService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class MaintenanceController extends AbstractController
{
    private const UTC = 'UTC';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaintenanceSignalService $signalService,
        private readonly MaintenanceAttachmentService $attachmentService,
        private readonly MaintenanceAttachmentStorage $attachmentStorage,
    ) {}

    #[Route('/maintenance', name: 'app_maintenance_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireUser();
        /** @var list<MaintenanceSignal> $signals */
        $signals = $this->entityManager->getRepository(MaintenanceSignal::class)->findBy(
            ['submittedBy' => $user],
            ['createdAt' => 'DESC'],
        );

        return $this->render('maintenance/index.html.twig', ['signals' => $signals]);
    }

    #[Route('/maintenance/signal/new', name: 'app_maintenance_signal_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->requireUser();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('maintenance_signal_create', $request);

            try {
                $category = MaintenanceSignalCategory::tryFrom($request->request->getString('category'));
                $priority = MaintenanceSignalPriority::tryFrom($request->request->getString('priority'));
                if (!$category instanceof MaintenanceSignalCategory || !$priority instanceof MaintenanceSignalPriority) {
                    throw new InvalidArgumentException('Невалидна категория или приоритет.');
                }

                $asset = $this->optionalActiveAsset($request->request->getInt('asset_id'));
                $signal = $this->signalService->create(
                    $user,
                    $category,
                    $priority,
                    $request->request->getString('title'),
                    $request->request->getString('description'),
                    $request->request->getString('location'),
                    new DateTimeImmutable('now', new DateTimeZone(self::UTC)),
                    $asset,
                );
                $this->addFlash('success', 'Сигналът е подаден успешно.');

                return $this->redirectToRoute('app_maintenance_signal_show', ['id' => $signal->getId()]);
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        /** @var list<BuildingAsset> $assets */
        $assets = $this->entityManager->getRepository(BuildingAsset::class)->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('maintenance/new.html.twig', [
            'categories' => MaintenanceSignalCategory::cases(),
            'priorities' => MaintenanceSignalPriority::cases(),
            'assets' => $assets,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/maintenance/signal/{id}', name: 'app_maintenance_signal_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->requireUser();
        $signal = $this->visibleSignal($id, $user);

        /** @var list<MaintenanceAttachment> $attachments */
        $attachments = $this->entityManager->getRepository(MaintenanceAttachment::class)->findBy(['signal' => $signal], ['uploadedAt' => 'ASC']);
        /** @var list<MaintenanceSignalStatusChange> $history */
        $history = $this->entityManager->getRepository(MaintenanceSignalStatusChange::class)->findBy(['signal' => $signal], ['changedAt' => 'ASC']);

        return $this->render('maintenance/show.html.twig', [
            'signal' => $signal,
            'attachments' => $attachments,
            'history' => $history,
        ]);
    }

    #[Route('/maintenance/signal/{id}/attachment', name: 'app_maintenance_attachment_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addAttachment(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('maintenance_attachment_'.$id, $request);
        $signal = $this->visibleSignal($id, $user);

        try {
            $file = $request->files->get('attachment');
            if (!$file instanceof UploadedFile) {
                throw new InvalidArgumentException('Изберете файл за прикачване.');
            }

            $this->attachmentService->add(
                $user,
                $signal,
                $file,
                new DateTimeImmutable('now', new DateTimeZone(self::UTC)),
            );
            $this->addFlash('success', 'Файлът е прикачен успешно.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_maintenance_signal_show', ['id' => $id]);
    }

    #[Route('/maintenance/attachment/{id}/download', name: 'app_maintenance_attachment_download', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function downloadAttachment(int $id): Response
    {
        $user = $this->requireUser();
        $attachment = $this->entityManager->find(MaintenanceAttachment::class, $id);
        if (!$attachment instanceof MaintenanceAttachment || !$this->canView($attachment->getSignal(), $user)) {
            throw $this->createNotFoundException('Maintenance attachment not found.');
        }

        $path = $this->attachmentStorage->pathFor($attachment->getStorageName());
        if (!is_file($path)) {
            throw $this->createNotFoundException('Maintenance attachment file not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalName());

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

    private function visibleSignal(int $id, User $user): MaintenanceSignal
    {
        $signal = $this->entityManager->find(MaintenanceSignal::class, $id);
        if (!$signal instanceof MaintenanceSignal || !$this->canView($signal, $user)) {
            throw $this->createNotFoundException('Maintenance signal not found.');
        }

        return $signal;
    }

    private function canView(MaintenanceSignal $signal, User $user): bool
    {
        if ($signal->getSubmittedBy() === $user) {
            return true;
        }

        $roles = $user->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }

    private function optionalActiveAsset(int $id): ?BuildingAsset
    {
        if ($id <= 0) {
            return null;
        }

        $asset = $this->entityManager->find(BuildingAsset::class, $id);
        if (!$asset instanceof BuildingAsset || !$asset->isActive()) {
            throw new InvalidArgumentException('Избраният актив не е наличен.');
        }

        return $asset;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
