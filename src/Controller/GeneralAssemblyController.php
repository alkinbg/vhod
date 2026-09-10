<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssemblyMinutesCorrection;
use App\Entity\AssemblyResolution;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\GeneralAssemblyRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GeneralAssemblyController extends AbstractController
{
    public function __construct(
        private readonly GeneralAssemblyRepository $assemblies,
        private readonly GeneralAssemblyAccessPolicy $accessPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/assemblies', name: 'app_assemblies_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireResident();

        return $this->render('assemblies/index.html.twig', [
            'assemblies' => $this->assemblies->findResidentVisible(),
        ]);
    }

    #[Route('/assembly/{id<\d+>}', name: 'app_assembly_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->requireResident();
        $assembly = $this->requireResidentAssembly($id, $user);
        $resolutionRepository = $this->entityManager->getRepository(AssemblyResolution::class);
        $agenda = [];

        foreach ($assembly->getAgendaItems() as $item) {
            $agenda[] = [
                'item' => $item,
                'resolution' => $resolutionRepository->findOneBy(['agendaItem' => $item]),
            ];
        }

        return $this->render('assemblies/show.html.twig', [
            'assembly' => $assembly,
            'agenda' => $agenda,
        ]);
    }

    #[Route('/assembly/{id<\d+>}/minutes', name: 'app_assembly_minutes', methods: ['GET'])]
    public function minutes(int $id): Response
    {
        $user = $this->requireResident();
        $assembly = $this->requireResidentAssembly($id, $user);
        $document = $assembly->getMinutesDocument();

        if (GeneralAssemblyStatus::MINUTES_FINALIZED !== $assembly->getStatus() || !$document instanceof Document) {
            throw $this->createNotFoundException('Finalized General Assembly minutes not found.');
        }

        return $this->render('assemblies/minutes.html.twig', [
            'assembly' => $assembly,
            'document' => $document,
            'corrections' => $this->entityManager->getRepository(AssemblyMinutesCorrection::class)->findBy(
                ['assembly' => $assembly],
                ['recordedAt' => 'ASC', 'id' => 'ASC'],
            ),
        ]);
    }

    private function requireResident(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }
        if (!$user->isActive()) {
            throw $this->createAccessDeniedException('Active resident account is required.');
        }

        return $user;
    }

    private function requireResidentAssembly(int $id, User $user): GeneralAssembly
    {
        $assembly = $this->assemblies->findResidentVisibleById($id);
        if (!$assembly instanceof GeneralAssembly || !$this->accessPolicy->canViewResident($user, $assembly)) {
            throw $this->createNotFoundException('General Assembly not found.');
        }

        return $assembly;
    }
}
