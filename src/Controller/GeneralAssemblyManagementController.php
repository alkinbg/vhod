<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssemblyAgendaItem;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\GeneralAssemblyRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Service\AssemblyInvitationService;
use App\Service\AssemblyMinutesService;
use App\Service\DocumentService;
use App\Service\GeneralAssemblyService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GeneralAssemblyManagementController extends AbstractController
{
    private const LOCAL_TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly GeneralAssemblyRepository $assemblies,
        private readonly GeneralAssemblyAccessPolicy $accessPolicy,
        private readonly GeneralAssemblyService $assemblyService,
        private readonly AssemblyInvitationService $invitationService,
        private readonly DocumentService $documentService,
        private readonly AssemblyMinutesService $minutesService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/management/assemblies', name: 'app_management_assemblies', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireManager();

        return $this->renderIndex();
    }

    #[Route('/management/assembly/new', name: 'app_management_assembly_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $actor = $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('assembly_create', $request);

            try {
                $this->assemblyService->createDraft(
                    $actor,
                    $request->request->getString('title'),
                    $this->parseLocalDateTime($request->request->getString('scheduled_at')),
                    self::LOCAL_TIMEZONE,
                    $this->parseLocalDate($request->request->getString('reference_date')),
                    $request->request->getString('place'),
                    $this->parseConveningBasis($request->request->getString('convening_basis')),
                    $request->request->getString('initiator_display_name'),
                    $actor,
                    $this->nowUtc(),
                    $request->request->getString('online_meeting_reference'),
                    $request->request->getString('convening_basis_note'),
                );
                $this->addFlash('success', 'Черновата на общото събрание е създадена.');

                return $this->redirectToRoute('app_management_assemblies');
            } catch (InvalidArgumentException|DomainException|LogicException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->renderForm(
            null,
            'assembly_create',
            $error,
            $status,
            $request->isMethod('POST') ? $request->request->all() : [],
        );
    }

    #[Route('/management/assembly/{id}/edit', name: 'app_management_assembly_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $actor = $this->requireManager();
        $assembly = $this->requireAssembly($id);
        if (GeneralAssemblyStatus::DRAFT !== $assembly->getStatus()) {
            return $this->renderForm(
                $assembly,
                'assembly_edit_'.$id,
                'Свикано общо събрание не може да бъде редактирано като чернова.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('assembly_edit_'.$id, $request);

            try {
                $this->assemblyService->reviseDraft(
                    $actor,
                    $assembly,
                    $request->request->getString('title'),
                    $this->parseLocalDateTime($request->request->getString('scheduled_at')),
                    self::LOCAL_TIMEZONE,
                    $this->parseLocalDate($request->request->getString('reference_date')),
                    $request->request->getString('place'),
                    $this->parseConveningBasis($request->request->getString('convening_basis')),
                    $request->request->getString('initiator_display_name'),
                    $actor,
                    $request->request->getString('online_meeting_reference'),
                    $request->request->getString('convening_basis_note'),
                );
                $this->addFlash('success', 'Черновата на общото събрание е обновена.');

                return $this->redirectToRoute('app_management_assemblies');
            } catch (InvalidArgumentException|DomainException|LogicException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->renderForm(
            $assembly,
            'assembly_edit_'.$id,
            $error,
            $status,
            $request->isMethod('POST') ? $request->request->all() : [],
        );
    }

    #[Route('/management/assembly/{id}/agenda/add', name: 'app_management_assembly_agenda_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addAgendaItem(int $id, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_agenda_add_'.$id, $request);
        $assembly = $this->requireAssembly($id);

        try {
            $this->assemblyService->addAgendaItem(
                $actor,
                $assembly,
                $this->parsePositiveInt($request->request->getString('position'), 'Позицията на точката трябва да е положително цяло число.'),
                $request->request->getString('agenda_title'),
                $request->request->getString('agenda_description'),
                $request->request->getString('draft_resolution_text'),
                $this->parseDecisionKind($request->request->getString('decision_kind')),
            );
            $this->addFlash('success', 'Точката е добавена към дневния ред.');

            return $this->redirectToRoute('app_management_assembly_edit', ['id' => $id]);
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            return $this->renderForm(
                $assembly,
                'assembly_edit_'.$id,
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    #[Route('/management/assembly/{id}/agenda/{itemId}/edit', name: 'app_management_assembly_agenda_edit', requirements: ['id' => '\\d+', 'itemId' => '\\d+'], methods: ['POST'])]
    public function editAgendaItem(int $id, int $itemId, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_agenda_edit_'.$id.'_'.$itemId, $request);
        $assembly = $this->requireAssembly($id);
        $item = $this->requireAgendaItem($assembly, $itemId);

        try {
            $this->assemblyService->reviseAgendaItem(
                $actor,
                $assembly,
                $item,
                $request->request->getString('agenda_title'),
                $request->request->getString('agenda_description'),
                $request->request->getString('draft_resolution_text'),
                $this->parseDecisionKind($request->request->getString('decision_kind')),
            );
            $this->addFlash('success', 'Точката от дневния ред е обновена.');

            return $this->redirectToRoute('app_management_assembly_edit', ['id' => $id]);
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            return $this->renderForm(
                $assembly,
                'assembly_edit_'.$id,
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    #[Route('/management/assembly/{id}/agenda/{itemId}/remove', name: 'app_management_assembly_agenda_remove', requirements: ['id' => '\\d+', 'itemId' => '\\d+'], methods: ['POST'])]
    public function removeAgendaItem(int $id, int $itemId, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_agenda_remove_'.$id.'_'.$itemId, $request);
        $assembly = $this->requireAssembly($id);
        $item = $this->requireAgendaItem($assembly, $itemId);

        try {
            $this->assemblyService->removeAgendaItem($actor, $assembly, $item);
            $this->addFlash('success', 'Точката е премахната от дневния ред.');

            return $this->redirectToRoute('app_management_assembly_edit', ['id' => $id]);
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            return $this->renderForm(
                $assembly,
                'assembly_edit_'.$id,
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    #[Route('/management/assembly/{id}/convene', name: 'app_management_assembly_convene', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function convene(int $id, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_convene_'.$id, $request);
        $assembly = $this->requireAssembly($id);

        try {
            $this->assemblyService->convene($actor, $assembly, $this->nowUtc());
            $this->addFlash('success', 'Общото събрание е свикано и поканата е създадена.');

            return $this->redirectToRoute('app_management_assemblies');
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            return $this->renderIndex($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/management/assembly/{id}/posting', name: 'app_management_assembly_posting', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function posting(int $id, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_posting_'.$id, $request);
        $assembly = $this->requireAssembly($id);
        $evidence = null;

        try {
            $file = $request->files->get('evidence_file');
            if ($file instanceof UploadedFile) {
                $evidence = $this->documentService->uploadGovernanceEvidence(
                    $actor,
                    DocumentCategory::OTHER,
                    'Доказателство за поставяне — '.$assembly->getTitle(),
                    null,
                    $file,
                    $this->nowUtc(),
                );
            }

            $postedAtRaw = $request->request->getString('posted_at');
            $postedAt = '' === $postedAtRaw ? $this->nowUtc() : $this->parseLocalDateTime($postedAtRaw);
            $this->invitationService->recordPosting(
                $actor,
                $assembly,
                $postedAt,
                $request->request->getString('posting_place'),
                $evidence,
                $request->request->getString('notes'),
            );
            $this->addFlash('success', 'Физическото поставяне на поканата е записано отделно от приложението.');

            return $this->redirectToRoute('app_management_assemblies');
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            return $this->renderIndex($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/management/assembly/{id}/minutes', name: 'app_management_assembly_minutes', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function minutes(int $id): Response
    {
        $this->requireManager();
        $assembly = $this->requireAssembly($id);

        return $this->render(
            'management/assemblies/minutes_preview.html.twig',
            $this->minutesService->buildViewModel($assembly),
        );
    }

    #[Route('/management/assembly/{id}/minutes/metadata', name: 'app_management_assembly_minutes_metadata', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function minutesMetadata(int $id, Request $request): Response
    {
        $this->requireManager();
        $this->requireCsrf('assembly_minutes_metadata_'.$id, $request);
        $assembly = $this->requireAssembly($id);

        try {
            $assembly->setMinutesMetadata(
                $request->request->getString('chairperson_name'),
                $request->request->getString('secretary_name'),
                $request->request->getString('formal_notes'),
            );
            $this->entityManager->flush();
            $this->addFlash('success', 'Данните за протокола са обновени.');
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_management_assembly_minutes', ['id' => $id]);
    }

    #[Route('/management/assembly/{id}/minutes/finalize', name: 'app_management_assembly_minutes_finalize', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function finalizeMinutes(int $id, Request $request): Response
    {
        $actor = $this->requireManager();
        $this->requireCsrf('assembly_minutes_finalize_'.$id, $request);
        $assembly = $this->requireAssembly($id);

        try {
            $this->minutesService->finalize($actor, $assembly, $this->nowUtc());
            $this->addFlash('success', 'Протоколът е финализиран и публикуван за живущите.');
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_management_assembly_minutes', ['id' => $id]);
    }

    private function renderIndex(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('management/assemblies/index.html.twig', [
            'assemblies' => $this->assemblies->findManagement(),
            'error' => $error,
        ], new Response(status: $status));
    }

    /** @param array<string, mixed> $submitted */
    private function renderForm(
        ?GeneralAssembly $assembly,
        string $csrfTokenId,
        ?string $error,
        int $status,
        array $submitted = [],
    ): Response {
        $decisionRules = [];
        foreach (AssemblyDecisionKind::cases() as $kind) {
            $decisionRules[$kind->value] = GeneralAssemblyService::suggestedMajorityRule($kind);
        }

        return $this->render('management/assemblies/form.html.twig', [
            'assembly' => $assembly,
            'convening_bases' => AssemblyConveningBasis::cases(),
            'decision_kinds' => AssemblyDecisionKind::cases(),
            'decision_rules' => $decisionRules,
            'csrf_token_id' => $csrfTokenId,
            'error' => $error,
            'submitted' => $submitted,
            'local_timezone' => self::LOCAL_TIMEZONE,
        ], new Response(status: $status));
    }

    private function requireManager(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }
        if (!$this->accessPolicy->canManage($user)) {
            throw $this->createAccessDeniedException('General Assembly management access is required.');
        }

        return $user;
    }

    private function requireAssembly(int $id): GeneralAssembly
    {
        $assembly = $this->assemblies->find($id);
        if (!$assembly instanceof GeneralAssembly) {
            throw $this->createNotFoundException('General Assembly not found.');
        }

        return $assembly;
    }

    private function requireAgendaItem(GeneralAssembly $assembly, int $itemId): AssemblyAgendaItem
    {
        $item = $this->entityManager->find(AssemblyAgendaItem::class, $itemId);
        if (!$item instanceof AssemblyAgendaItem || $item->getAssembly()->getId() !== $assembly->getId()) {
            throw $this->createNotFoundException('Agenda item not found.');
        }

        return $item;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function parseConveningBasis(string $value): AssemblyConveningBasis
    {
        $basis = AssemblyConveningBasis::tryFrom($value);
        if (!$basis instanceof AssemblyConveningBasis) {
            throw new InvalidArgumentException('Невалидно основание за свикване.');
        }

        return $basis;
    }

    private function parseDecisionKind(string $value): AssemblyDecisionKind
    {
        $kind = AssemblyDecisionKind::tryFrom($value);
        if (!$kind instanceof AssemblyDecisionKind) {
            throw new InvalidArgumentException('Невалиден вид решение за точката от дневния ред.');
        }

        return $kind;
    }

    private function parsePositiveInt(string $value, string $errorMessage): int
    {
        if ('' === $value || !ctype_digit($value)) {
            throw new InvalidArgumentException($errorMessage);
        }

        $number = (int) $value;
        if ($number < 1) {
            throw new InvalidArgumentException($errorMessage);
        }

        return $number;
    }

    private function parseLocalDateTime(string $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::LOCAL_TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
            throw new InvalidArgumentException('Невалидни дата и час.');
        }

        return $date;
    }

    private function parseLocalDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::LOCAL_TIMEZONE));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
            throw new InvalidArgumentException('Невалидна референтна дата.');
        }

        return $date;
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
