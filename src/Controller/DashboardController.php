<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MaintenanceSignal;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\MaintenanceSignalStatus;
use App\Enum\UnitRelationType;
use App\Service\UnitBalanceCalculator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnitBalanceCalculator $balanceCalculator,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->render('dashboard/index.html.twig', [
                'person' => null,
                'unit_balances' => [],
                'total_balance_cents' => 0,
                'active_signal_count' => 0,
            ]);
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia'));
        $units = [];
        foreach ($this->entityManager->getRepository(UnitRelation::class)->findBy(['person' => $user->getPerson()]) as $relation) {
            if (!in_array($relation->getType(), [UnitRelationType::OWNER, UnitRelationType::USER], true) || !$relation->isActiveAt($today)) {
                continue;
            }

            $unit = $relation->getUnit();
            $id = $unit->getId();
            if (null !== $id && $unit->isActive()) {
                $units[$id] = $unit;
            }
        }

        /** @var list<array{unit: Unit, balance_cents: int}> $unitBalances */
        $unitBalances = [];
        $totalBalanceCents = 0;
        foreach ($units as $unit) {
            $balanceCents = $this->balanceCalculator->netBalanceCents($unit);
            $unitBalances[] = ['unit' => $unit, 'balance_cents' => $balanceCents];
            $totalBalanceCents += $balanceCents;
        }

        $activeSignalCount = 0;
        foreach ($this->entityManager->getRepository(MaintenanceSignal::class)->findBy(['submittedBy' => $user]) as $signal) {
            if (in_array($signal->getStatus(), [MaintenanceSignalStatus::RESOLVED, MaintenanceSignalStatus::CLOSED], true)) {
                continue;
            }
            ++$activeSignalCount;
        }

        return $this->render('dashboard/index.html.twig', [
            'person' => $user->getPerson(),
            'unit_balances' => $unitBalances,
            'total_balance_cents' => $totalBalanceCents,
            'active_signal_count' => $activeSignalCount,
        ]);
    }
}
