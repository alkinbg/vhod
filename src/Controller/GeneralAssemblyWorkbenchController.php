<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyQuorumCheckKind;
use App\Repository\AssemblyQuorumCheckRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Service\AssemblyQuorumService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/management/assembly')]
final class GeneralAssemblyWorkbenchController extends AbstractController
{
    public function __construct(
        private readonly GeneralAssemblyAccessPolicy $accessPolicy,
        private readonly AssemblyQuorumService $quorumService,
        private readonly AssemblyQuorumCheckRepository $quorumCheckRepository,
    ) {
    }

    #[Route('/{id<\d+>}/workbench', name: 'app_management_assembly_workbench', methods: ['GET'])]
    public function workbench(GeneralAssembly $assembly): Response
    {
        $this->denyUnlessManager();

        return $this->render('management/assemblies/workbench.html.twig', [
            'assembly' => $assembly,
            'representation' => $this->quorumService->currentRepresentation($assembly),
            'latest_check' => $this->quorumCheckRepository->findLatestForAssembly($assembly),
            'quorum_checks' => $this->quorumCheckRepository->findForAssembly($assembly),
        ]);
    }

    #[Route('/{id<\d+>}/quorum-check', name: 'app_management_assembly_quorum_check', methods: ['POST'])]
    public function quorumCheck(GeneralAssembly $assembly, Request $request): RedirectResponse
    {
        $actor = $this->denyUnlessManager();

        if (!$this->isCsrfTokenValid('assembly_quorum_'.$assembly->getId(), (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $kind = AssemblyQuorumCheckKind::tryFrom((string) $request->request->get('kind'));
        if (null === $kind || AssemblyQuorumCheckKind::MANUAL_REVIEW === $kind) {
            throw new BadRequestHttpException('Invalid quorum check kind.');
        }

        $this->quorumService->check(
            $actor,
            $assembly,
            $kind,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return $this->redirectToRoute('app_management_assembly_workbench', ['id' => $assembly->getId()]);
    }

    private function denyUnlessManager(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->accessPolicy->canManage($user)) {
            throw new AccessDeniedHttpException('General Assembly management access is required.');
        }

        return $user;
    }
}
