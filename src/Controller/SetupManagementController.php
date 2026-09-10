<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\UnitRelationType;
use App\Enum\UnitType;
use App\Value\EuroAmount;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SetupManagementController extends AbstractController
{
    private const SOFIA = 'Europe/Sofia';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/management/setup', name: 'app_management_setup', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();

        return $this->render('management/setup/index.html.twig', [
            'unit_count' => $this->entityManager->getRepository(Unit::class)->count([]),
            'relation_count' => $this->entityManager->getRepository(UnitRelation::class)->count([]),
            'fund_count' => $this->entityManager->getRepository(Fund::class)->count([]),
            'policy_count' => $this->entityManager->getRepository(FeePolicy::class)->count([]),
        ]);
    }

    #[Route('/management/setup/unit/new', name: 'app_management_setup_unit_new', methods: ['GET', 'POST'])]
    public function unitNew(Request $request): Response
    {
        $this->requireAdmin();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('setup_unit_new', $request);

            try {
                $type = UnitType::tryFrom($request->request->getString('type'));
                if (!$type instanceof UnitType) {
                    throw new InvalidArgumentException('Invalid unit type.');
                }

                $unit = new Unit(
                    $request->request->getString('designation'),
                    $type,
                    self::optionalInt($request->request->getString('floor'), 'Floor'),
                    self::nullableString($request->request->getString('built_area')),
                    self::nullableString($request->request->getString('ideal_parts')),
                );
                $this->entityManager->persist($unit);
                $this->entityManager->flush();

                $this->addFlash('success', 'Обектът е създаден.');

                return $this->redirectToRoute('app_management_setup');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/setup/unit_new.html.twig', [
            'types' => UnitType::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/setup/relation/new', name: 'app_management_setup_relation_new', methods: ['GET', 'POST'])]
    public function relationNew(Request $request): Response
    {
        $this->requireAdmin();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('setup_relation_new', $request);

            try {
                $unit = $this->requireUnit($request->request->getInt('unit_id'));
                $type = UnitRelationType::tryFrom($request->request->getString('type'));
                if (!$type instanceof UnitRelationType) {
                    throw new InvalidArgumentException('Invalid relation type.');
                }
                $validFrom = self::parseDate($request->request->getString('valid_from'));
                $ownershipShare = self::nullableString($request->request->getString('ownership_share'));
                $personId = $request->request->getInt('person_id');

                if ($personId > 0) {
                    $relation = new UnitRelation(
                        $this->requirePerson($personId),
                        $unit,
                        $type,
                        $validFrom,
                        $ownershipShare,
                    );
                } else {
                    $relation = UnitRelation::forLegalEntity(
                        $unit,
                        $type,
                        $validFrom,
                        $request->request->getString('legal_entity_name'),
                        $request->request->getString('legal_entity_identifier'),
                        $ownershipShare,
                    );
                }

                $this->entityManager->persist($relation);
                $this->entityManager->flush();

                $this->addFlash('success', 'Отношението към обекта е създадено.');

                return $this->redirectToRoute('app_management_setup');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/setup/relation_new.html.twig', [
            'units' => $this->entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']),
            'people' => $this->entityManager->getRepository(Person::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']),
            'types' => UnitRelationType::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/setup/fund/new', name: 'app_management_setup_fund_new', methods: ['GET', 'POST'])]
    public function fundNew(Request $request): Response
    {
        $this->requireAdmin();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('setup_fund_new', $request);

            try {
                $type = FundType::tryFrom($request->request->getString('type'));
                if (!$type instanceof FundType) {
                    throw new InvalidArgumentException('Invalid fund type.');
                }

                $fund = new Fund(
                    $request->request->getString('code'),
                    $request->request->getString('name'),
                    $type,
                );
                $this->entityManager->persist($fund);
                $this->entityManager->flush();

                $this->addFlash('success', 'Фондът е създаден.');

                return $this->redirectToRoute('app_management_setup');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/setup/fund_new.html.twig', [
            'types' => FundType::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/setup/fee-policy/new', name: 'app_management_setup_fee_policy_new', methods: ['GET', 'POST'])]
    public function feePolicyNew(Request $request): Response
    {
        $this->requireAdmin();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('setup_fee_policy_new', $request);

            try {
                $fund = $this->requireFund($request->request->getInt('fund_id'));
                $category = FeeCategory::tryFrom($request->request->getString('category'));
                $distribution = FeeDistribution::tryFrom($request->request->getString('distribution'));
                if (!$category instanceof FeeCategory || !$distribution instanceof FeeDistribution) {
                    throw new InvalidArgumentException('Invalid fee category or distribution.');
                }

                $policy = FeePolicy::create(
                    $request->request->getString('code'),
                    $request->request->getString('name'),
                    $fund,
                    $category,
                    $distribution,
                    EuroAmount::parse($request->request->getString('amount')),
                    self::parseDate($request->request->getString('effective_from')),
                    $request->request->getString('decision_reference'),
                    $request->request->getBoolean('include_animal_equivalents'),
                    $request->request->getBoolean('statutory_minimum_confirmed'),
                );
                $this->entityManager->persist($policy);
                $this->entityManager->flush();

                $this->addFlash('success', 'Правилото за такса е създадено.');

                return $this->redirectToRoute('app_management_setup');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/setup/fee_policy_new.html.twig', [
            'funds' => $this->entityManager->getRepository(Fund::class)->findBy(['active' => true], ['name' => 'ASC']),
            'categories' => FeeCategory::cases(),
            'distributions' => FeeDistribution::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function requireAdmin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Administrator access is required.');
        }

        return $user;
    }

    private function requireCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function requireUnit(int $id): Unit
    {
        $unit = $this->entityManager->find(Unit::class, $id);
        if (!$unit instanceof Unit || !$unit->isActive()) {
            throw new InvalidArgumentException('Invalid unit.');
        }

        return $unit;
    }

    private function requirePerson(int $id): Person
    {
        $person = $this->entityManager->find(Person::class, $id);
        if (!$person instanceof Person) {
            throw new InvalidArgumentException('Invalid person.');
        }

        return $person;
    }

    private function requireFund(int $id): Fund
    {
        $fund = $this->entityManager->find(Fund::class, $id);
        if (!$fund instanceof Fund || !$fund->isActive()) {
            throw new InvalidArgumentException('Invalid fund.');
        }

        return $fund;
    }

    private static function parseDate(string $input): DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) {
            throw new InvalidArgumentException('Invalid date.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, new DateTimeZone(self::SOFIA));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException('Invalid date.');
        }

        return $date;
    }

    private static function optionalInt(string $input, string $label): ?int
    {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }
        if (1 !== preg_match('/^-?\d+$/D', $input)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $label));
        }

        return (int) $input;
    }

    private static function nullableString(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
