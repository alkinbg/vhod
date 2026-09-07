<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookChangeDeclaration;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\BookChangeType;
use App\Security\CondominiumBookAccessPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CondominiumBookAccessPolicy $accessPolicy,
    ) {
    }

    #[Route('/book', name: 'app_book', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireUser();
        $relations = $this->relationsFor($user);
        $today = new DateTimeImmutable('today');

        $units = [];
        foreach ($relations as $relation) {
            $unit = $relation->getUnit();
            if (!$this->accessPolicy->canViewUnit($user, $unit, $relations, $today)) {
                continue;
            }

            $id = $unit->getId();
            if (null !== $id) {
                $units[$id] = $unit;
            }
        }

        $declarations = $this->entityManager->getRepository(BookChangeDeclaration::class)->findBy(
            ['submittedBy' => $user],
            ['submittedAt' => 'DESC'],
        );

        return $this->render('book/index.html.twig', [
            'units' => array_values($units),
            'declarations' => $declarations,
        ]);
    }

    #[Route('/book/declaration/{type}', name: 'app_book_declaration', methods: ['GET', 'POST'])]
    public function declaration(string $type, Request $request): Response
    {
        $changeType = BookChangeType::tryFrom($type);
        if (null === $changeType) {
            throw $this->createNotFoundException('Unknown declaration type.');
        }

        $user = $this->requireUser();
        $relations = $this->relationsFor($user);
        $today = new DateTimeImmutable('today');
        $eligibleUnits = [];

        foreach ($relations as $relation) {
            $unit = $relation->getUnit();
            if (!$this->accessPolicy->canSubmitDeclaration($user, $unit, $relations, $today)) {
                continue;
            }

            $id = $unit->getId();
            if (null !== $id) {
                $eligibleUnits[$id] = $unit;
            }
        }

        if ([] === $eligibleUnits) {
            throw $this->createAccessDeniedException('No active owner or user relation is available.');
        }

        $unit = $this->resolveUnit($request, $eligibleUnits);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('book_declaration_'.$changeType->value, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $declaration = BookChangeDeclaration::submit(
                $unit,
                $user,
                $changeType,
                $this->payloadFromRequest($changeType, $request),
                new DateTimeImmutable(),
            );

            $this->entityManager->persist($declaration);
            $this->entityManager->flush();

            $this->addFlash('success', 'Декларацията е изпратена за преглед.');

            return $this->redirectToRoute('app_book');
        }

        return $this->render('book/declaration.html.twig', [
            'change_type' => $changeType,
            'unit' => $unit,
            'units' => array_values($eligibleUnits),
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @return list<UnitRelation> */
    private function relationsFor(User $user): array
    {
        return $this->entityManager->getRepository(UnitRelation::class)->findBy([
            'person' => $user->getPerson(),
        ]);
    }

    /** @param array<int, Unit> $eligibleUnits */
    private function resolveUnit(Request $request, array $eligibleUnits): Unit
    {
        $requestedId = $request->isMethod('POST')
            ? $request->request->getInt('unit')
            : $request->query->getInt('unit');

        if (0 !== $requestedId) {
            if (!isset($eligibleUnits[$requestedId])) {
                throw $this->createAccessDeniedException('The selected unit is not available to this user.');
            }

            return $eligibleUnits[$requestedId];
        }

        $unit = reset($eligibleUnits);
        if (!$unit instanceof Unit) {
            throw new \LogicException('Eligible unit list must not be empty.');
        }

        return $unit;
    }

    /** @return array<string, bool|int|float|string|null> */
    private function payloadFromRequest(BookChangeType $type, Request $request): array
    {
        return match ($type) {
            BookChangeType::CONTACT_UPDATE => [
                'email' => $request->request->getString('email'),
                'phone' => $request->request->getString('phone'),
            ],
            BookChangeType::HOUSEHOLD_MEMBER_ADD => [
                'firstName' => $request->request->getString('firstName'),
                'lastName' => $request->request->getString('lastName'),
                'validFrom' => $request->request->getString('validFrom'),
            ],
            BookChangeType::ABSENCE => [
                'validFrom' => $request->request->getString('validFrom'),
                'validUntil' => $request->request->getString('validUntil'),
            ],
            BookChangeType::ANIMAL => [
                'species' => $request->request->getString('species'),
                'count' => $request->request->getInt('count'),
                'passport' => $request->request->getString('passport'),
            ],
        };
    }
}
