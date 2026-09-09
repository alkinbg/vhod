<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyAbsenteeSignatureMode;
use App\Enum\AssemblyQuorumCheckKind;
use App\Enum\AssemblyVoteChoice;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Repository\AssemblyElectorateEntryRepository;
use App\Repository\AssemblyQuorumCheckRepository;
use App\Repository\AssemblyVoteRepository;
use App\Repository\DocumentRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Service\AssemblyAbsenteeVotingService;
use App\Service\AssemblyQuorumService;
use App\Service\AssemblyVotingService;
use App\Service\GeneralAssemblyLifecycleService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/management/assembly')]
final class GeneralAssemblyWorkbenchController extends AbstractController
{
    public function __construct(
        private readonly GeneralAssemblyAccessPolicy $accessPolicy,
        private readonly AssemblyQuorumService $quorumService,
        private readonly AssemblyQuorumCheckRepository $quorumCheckRepository,
        private readonly AssemblyElectorateEntryRepository $electorateRepository,
        private readonly AssemblyVoteRepository $voteRepository,
        private readonly AssemblyVotingService $votingService,
        private readonly AssemblyAbsenteeVotingService $absenteeVotingService,
        private readonly DocumentRepository $documentRepository,
        private readonly GeneralAssemblyLifecycleService $lifecycleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id<\d+>}/workbench', name: 'app_management_assembly_workbench', methods: ['GET'])]
    public function workbench(GeneralAssembly $assembly): Response
    {
        $this->denyUnlessManager();

        $votesByItem = [];
        $resolutionsByItem = [];
        $absenteeEligibleItems = [];
        foreach ($assembly->getAgendaItems() as $item) {
            $itemId = $item->getId();
            if (null === $itemId) {
                continue;
            }
            $votesByItem[$itemId] = $this->voteRepository->findForItem($item);
            $resolution = $this->entityManager->getRepository(AssemblyResolution::class)->findOneBy(['agendaItem' => $item]);
            if ($resolution instanceof AssemblyResolution) {
                $resolutionsByItem[$itemId] = $resolution;
            }
            if (
                AgendaItemStatus::OPEN === $item->getStatus()
                && AssemblyVoteDenominator::ELIGIBLE_ABSENTEE_UNIVERSE === $item->getMajorityRule()->getDenominator()
            ) {
                $absenteeEligibleItems[] = $item;
            }
        }

        $absenteeWindow = $this->entityManager
            ->getRepository(AssemblyAbsenteeWindow::class)
            ->findOneBy(['assembly' => $assembly]);

        return $this->render('management/assemblies/workbench.html.twig', [
            'assembly' => $assembly,
            'representation' => $this->quorumService->currentRepresentation($assembly),
            'latest_check' => $this->quorumCheckRepository->findLatestForAssembly($assembly),
            'quorum_checks' => $this->quorumCheckRepository->findForAssembly($assembly),
            'electorate' => $this->electorateRepository->findForAssembly($assembly),
            'votes_by_item' => $votesByItem,
            'resolutions_by_item' => $resolutionsByItem,
            'vote_choices' => AssemblyVoteChoice::cases(),
            'absentee_window' => $absenteeWindow,
            'absentee_eligible_items' => $absenteeEligibleItems,
            'absentee_declarations' => $this->entityManager
                ->getRepository(AssemblyAbsenteeDeclaration::class)
                ->findBy(['assembly' => $assembly], ['submittedAt' => 'ASC', 'id' => 'ASC']),
            'absentee_signature_modes' => AssemblyAbsenteeSignatureMode::cases(),
            'governance_documents' => $this->documentRepository->findVisible([DocumentAccessLevel::GOVERNANCE]),
        ]);
    }

    #[Route('/{id<\d+>}/start', name: 'app_management_assembly_start', methods: ['POST'])]
    public function start(GeneralAssembly $assembly, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $this->requireCsrf('assembly_start_'.$assembly->getId(), $request);

        try {
            $this->lifecycleService->start($actor, $assembly, $this->nowUtc());
            $this->addFlash('success', 'Общото събрание е започнато.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/close', name: 'app_management_assembly_close', methods: ['POST'])]
    public function close(GeneralAssembly $assembly, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $this->requireCsrf('assembly_close_'.$assembly->getId(), $request);

        try {
            $this->lifecycleService->close($actor, $assembly, $this->nowUtc());
            $this->addFlash('success', 'Общото събрание е приключено.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/quorum-check', name: 'app_management_assembly_quorum_check', methods: ['POST'])]
    public function quorumCheck(GeneralAssembly $assembly, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $this->requireCsrf('assembly_quorum_'.$assembly->getId(), $request);

        $kind = AssemblyQuorumCheckKind::tryFrom((string) $request->request->get('kind'));
        if (null === $kind) {
            throw new BadRequestHttpException('Invalid quorum check kind.');
        }

        try {
            $this->quorumService->check($actor, $assembly, $kind, $this->nowUtc());
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/item/{itemId<\d+>}/open', name: 'app_management_assembly_item_open', methods: ['POST'])]
    public function openItem(GeneralAssembly $assembly, int $itemId, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $item = $this->requireItem($assembly, $itemId);
        $this->requireCsrf('assembly_item_open_'.$itemId, $request);

        try {
            $this->votingService->openItem(
                $actor,
                $item,
                $request->request->getString('final_resolution_text'),
                $this->nowUtc(),
            );
            $this->addFlash('success', 'Точката е отворена за официално гласуване.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/item/{itemId<\d+>}/vote/{entryId<\d+>}', name: 'app_management_assembly_vote', methods: ['POST'])]
    public function vote(GeneralAssembly $assembly, int $itemId, int $entryId, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $item = $this->requireItem($assembly, $itemId);
        $entry = $this->requireEntry($assembly, $entryId);
        $this->requireCsrf('assembly_vote_'.$itemId.'_'.$entryId, $request);

        $choice = AssemblyVoteChoice::tryFrom($request->request->getString('choice'));
        if (!$choice instanceof AssemblyVoteChoice) {
            throw new BadRequestHttpException('Invalid formal vote choice.');
        }

        try {
            $this->votingService->recordVote($actor, $item, $entry, $choice, $this->nowUtc());
            $this->addFlash('success', 'Официалният вот е записан.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/vote/{voteId<\d+>}/correct', name: 'app_management_assembly_vote_correct', methods: ['POST'])]
    public function correctVote(GeneralAssembly $assembly, int $voteId, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $vote = $this->requireVote($assembly, $voteId);
        $this->requireCsrf('assembly_vote_correct_'.$voteId, $request);

        $choice = AssemblyVoteChoice::tryFrom($request->request->getString('choice'));
        if (!$choice instanceof AssemblyVoteChoice) {
            throw new BadRequestHttpException('Invalid formal vote correction choice.');
        }

        try {
            $this->votingService->correctVote(
                $actor,
                $vote,
                $choice,
                $request->request->getString('reason'),
                $this->nowUtc(),
            );
            $this->addFlash('success', 'Корекцията на официалния вот е записана в одитната история.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/item/{itemId<\d+>}/resolve', name: 'app_management_assembly_item_resolve', methods: ['POST'])]
    public function resolveItem(GeneralAssembly $assembly, int $itemId, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $item = $this->requireItem($assembly, $itemId);
        $this->requireCsrf('assembly_item_resolve_'.$itemId, $request);

        try {
            $this->votingService->resolveItem($actor, $item, $this->nowUtc());
            $this->addFlash('success', 'Резултатът от официалното гласуване е изчислен и замразен.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/absentee/open', name: 'app_management_assembly_absentee_open', methods: ['POST'])]
    public function openAbsenteeWindow(GeneralAssembly $assembly, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $this->requireCsrf('assembly_absentee_open_'.$assembly->getId(), $request);

        $itemIds = $request->request->all('agenda_item_ids');
        if ([] === $itemIds) {
            throw new BadRequestHttpException('At least one absentee agenda item is required.');
        }

        $items = [];
        foreach ($itemIds as $itemId) {
            if (!is_scalar($itemId) || !ctype_digit((string) $itemId)) {
                throw new BadRequestHttpException('Invalid absentee agenda item.');
            }
            $items[] = $this->requireItem($assembly, (int) $itemId);
        }

        try {
            $this->absenteeVotingService->openWindow(
                $actor,
                $assembly,
                $items,
                $this->nowUtc(),
                $this->parseAssemblyLocalDateTime($assembly, $request->request->getString('deadline_at')),
                $request->request->getString('legal_basis'),
            );
            $this->addFlash('success', 'Прозорецът за неприсъствено гласуване е отворен.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/absentee/{windowId<\d+>}/declaration', name: 'app_management_assembly_absentee_declaration', methods: ['POST'])]
    public function registerAbsenteeDeclaration(
        GeneralAssembly $assembly,
        int $windowId,
        Request $request,
    ): RedirectResponse {
        $actor = $this->denyUnlessManager();
        $window = $this->requireAbsenteeWindow($assembly, $windowId);
        $this->requireCsrf('assembly_absentee_declaration_'.$windowId, $request);

        $entryId = $request->request->getString('electorate_entry_id');
        $evidenceId = $request->request->getString('evidence_document_id');
        if (!ctype_digit($entryId) || !ctype_digit($evidenceId)) {
            throw new BadRequestHttpException('Invalid absentee declaration reference.');
        }

        $signatureMode = AssemblyAbsenteeSignatureMode::tryFrom($request->request->getString('signature_mode'));
        if (!$signatureMode instanceof AssemblyAbsenteeSignatureMode) {
            throw new BadRequestHttpException('Invalid absentee declaration signature mode.');
        }

        $choices = [];
        foreach ($request->request->all('choices') as $itemId => $choiceValue) {
            if (!ctype_digit((string) $itemId) || !is_scalar($choiceValue)) {
                throw new BadRequestHttpException('Invalid absentee vote choice.');
            }

            $choice = AssemblyVoteChoice::tryFrom((string) $choiceValue);
            if (!$choice instanceof AssemblyVoteChoice) {
                throw new BadRequestHttpException('Invalid absentee vote choice.');
            }
            $choices[(int) $itemId] = $choice;
        }

        try {
            $this->absenteeVotingService->registerDeclaration(
                $actor,
                $window,
                $this->requireEntry($assembly, (int) $entryId),
                $this->requireDocument((int) $evidenceId),
                $signatureMode,
                $choices,
                $this->nowUtc(),
                $request->request->getString('notes'),
            );
            $this->addFlash('success', 'Неприсъствената декларация и официалните гласове са записани.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    #[Route('/{id<\d+>}/absentee/{windowId<\d+>}/close', name: 'app_management_assembly_absentee_close', methods: ['POST'])]
    public function closeAbsenteeWindow(GeneralAssembly $assembly, int $windowId, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();
        $window = $this->requireAbsenteeWindow($assembly, $windowId);
        $this->requireCsrf('assembly_absentee_close_'.$windowId, $request);

        try {
            $this->absenteeVotingService->closeWindow($actor, $window, $this->nowUtc());
            $this->addFlash('success', 'Прозорецът за неприсъствено гласуване е приключен.');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectWorkbench($assembly);
    }

    private function denyUnlessManager(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->accessPolicy->canManage($user)) {
            throw new AccessDeniedHttpException('General Assembly management access is required.');
        }

        return $user;
    }

    private function requireItem(GeneralAssembly $assembly, int $itemId): AssemblyAgendaItem
    {
        $item = $this->entityManager->find(AssemblyAgendaItem::class, $itemId);
        if (!$item instanceof AssemblyAgendaItem || $item->getAssembly() !== $assembly) {
            throw $this->createNotFoundException('General Assembly agenda item not found.');
        }

        return $item;
    }

    private function requireEntry(GeneralAssembly $assembly, int $entryId): AssemblyElectorateEntry
    {
        $entry = $this->entityManager->find(AssemblyElectorateEntry::class, $entryId);
        if (!$entry instanceof AssemblyElectorateEntry || $entry->getAssembly() !== $assembly) {
            throw $this->createNotFoundException('General Assembly electorate entry not found.');
        }

        return $entry;
    }

    private function requireVote(GeneralAssembly $assembly, int $voteId): AssemblyVote
    {
        $vote = $this->entityManager->find(AssemblyVote::class, $voteId);
        if (!$vote instanceof AssemblyVote || $vote->getAgendaItem()->getAssembly() !== $assembly) {
            throw $this->createNotFoundException('General Assembly formal vote not found.');
        }

        return $vote;
    }

    private function requireAbsenteeWindow(GeneralAssembly $assembly, int $windowId): AssemblyAbsenteeWindow
    {
        $window = $this->entityManager->find(AssemblyAbsenteeWindow::class, $windowId);
        if (!$window instanceof AssemblyAbsenteeWindow || $window->getAssembly() !== $assembly) {
            throw $this->createNotFoundException('General Assembly absentee voting window not found.');
        }

        return $window;
    }

    private function requireDocument(int $documentId): Document
    {
        $document = $this->entityManager->find(Document::class, $documentId);
        if (!$document instanceof Document) {
            throw $this->createNotFoundException('Absentee declaration evidence document not found.');
        }

        return $document;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function parseAssemblyLocalDateTime(GeneralAssembly $assembly, string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            throw new BadRequestHttpException('Absentee voting deadline is required.');
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone($assembly->getTimezoneSnapshot())))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new BadRequestHttpException('Invalid absentee voting deadline.', $exception);
        }
    }

    private function redirectWorkbench(GeneralAssembly $assembly): RedirectResponse
    {
        return $this->redirectToRoute('app_management_assembly_workbench', ['id' => $assembly->getId()]);
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
