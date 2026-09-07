<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookChangeDeclaration;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\BookDeclarationStatus;
use App\Security\CondominiumBookAccessPolicy;
use App\Service\BookChangeApplicationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ManagementBookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CondominiumBookAccessPolicy $accessPolicy,
        private readonly BookChangeApplicationService $applicationService,
    ) {
    }

    #[Route('/management/book', name: 'app_management_book', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireManagementReader();
        [$units, $relations] = $this->bookDataFor($user);
        $pending = $this->entityManager->getRepository(BookChangeDeclaration::class)->findBy(
            ['status' => BookDeclarationStatus::SUBMITTED],
            ['submittedAt' => 'ASC'],
        );

        return $this->render('management/book/index.html.twig', [
            'units' => $units,
            'relations' => $relations,
            'pending_declarations' => $pending,
            'can_review' => $this->accessPolicy->canReviewDeclarations($user),
        ]);
    }

    #[Route('/management/book/declaration/{id}/accept', name: 'app_management_book_accept', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function accept(int $id, Request $request): Response
    {
        $user = $this->requireManagementReader();
        if (!$this->accessPolicy->canReviewDeclarations($user)) {
            throw $this->createAccessDeniedException('Only a manager can review declarations.');
        }

        $declaration = $this->entityManager->find(BookChangeDeclaration::class, $id);
        if (!$declaration instanceof BookChangeDeclaration) {
            throw $this->createNotFoundException('Declaration not found.');
        }

        if (!$this->isCsrfTokenValid('book_accept_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->applicationService->accept($declaration, $user, new DateTimeImmutable());
        $this->addFlash('success', 'Декларацията е одобрена и промените са приложени.');

        return $this->redirectToRoute('app_management_book');
    }

    #[Route('/management/book/print', name: 'app_management_book_print', methods: ['GET'])]
    public function print(): Response
    {
        $user = $this->requireManagementReader();
        [$units, $relations] = $this->bookDataFor($user);

        return $this->render('management/book/print.html.twig', [
            'units' => $units,
            'relations' => $relations,
            'generated_at' => new DateTimeImmutable(),
        ]);
    }

    private function requireManagementReader(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_CONTROLLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Management access is required.');
        }

        return $user;
    }

    /** @return array{0: list<Unit>, 1: list<UnitRelation>} */
    private function bookDataFor(User $user): array
    {
        $allUnits = $this->entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']);
        $relations = $this->entityManager->getRepository(UnitRelation::class)->findAll();
        $today = new DateTimeImmutable('today');
        $units = [];

        foreach ($allUnits as $unit) {
            if ($this->accessPolicy->canViewUnit($user, $unit, $relations, $today)) {
                $units[] = $unit;
            }
        }

        return [$units, $relations];
    }
}
