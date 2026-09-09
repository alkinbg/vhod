<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSupplier;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceEventType;
use App\Enum\MaintenanceSignalStatus;
use App\Service\MaintenanceRegistryService;
use App\Service\MaintenanceReminderService;
use App\Service\MaintenanceSignalService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MaintenanceManagementController extends AbstractController
{
    private const UTC = 'UTC';
    private const LOCAL_TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaintenanceSignalService $signalService,
        private readonly MaintenanceRegistryService $registryService,
        private readonly MaintenanceReminderService $reminderService,
    ) {}

    #[Route('/management/maintenance', name: 'app_management_maintenance', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireManager();

        /** @var list<MaintenanceSignal> $signals */
        $signals = $this->entityManager->getRepository(MaintenanceSignal::class)->findBy([], ['createdAt' => 'DESC']);
        /** @var list<BuildingAsset> $assets */
        $assets = $this->entityManager->getRepository(BuildingAsset::class)->findBy([], ['name' => 'ASC']);
        /** @var list<MaintenanceSupplier> $suppliers */
        $suppliers = $this->entityManager->getRepository(MaintenanceSupplier::class)->findBy([], ['name' => 'ASC']);
        /** @var list<MaintenanceContract> $contracts */
        $contracts = $this->entityManager->getRepository(MaintenanceContract::class)->findBy([], ['createdAt' => 'DESC']);
        /** @var list<MaintenanceEvent> $events */
        $events = $this->entityManager->getRepository(MaintenanceEvent::class)->findBy([], ['performedAt' => 'DESC']);
        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findBy(['active' => true], ['email' => 'ASC']);

        $activeAssets = array_values(array_filter($assets, static fn (BuildingAsset $asset): bool => $asset->isActive()));
        $today = new DateTimeImmutable('today', new DateTimeZone(self::LOCAL_TIMEZONE));

        return $this->render('management/maintenance/index.html.twig', [
            'signals' => $signals,
            'assets' => $assets,
            'suppliers' => $suppliers,
            'contracts' => $contracts,
            'events' => $events,
            'users' => $users,
            'statuses' => MaintenanceSignalStatus::cases(),
            'reminders' => $this->reminderService->build($today, $activeAssets),
        ]);
    }

    #[Route('/management/maintenance/signal/{id}/assign', name: 'app_management_maintenance_signal_assign', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function assign(int $id, Request $request): Response
    {
        $this->requireManager();
        $this->requireCsrf('maintenance_assign_'.$id, $request);
        $signal = $this->requireSignal($id);

        $assignedToId = $request->request->getInt('assigned_to_id');
        $assignedTo = null;
        if ($assignedToId > 0) {
            $assignedTo = $this->entityManager->find(User::class, $assignedToId);
            if (!$assignedTo instanceof User || !$assignedTo->isActive()) {
                throw new InvalidArgumentException('Избраният потребител не е наличен.');
            }
        }

        $this->signalService->assign($signal, $assignedTo, $this->nowUtc());
        $this->addFlash('success', 'Отговорникът е обновен.');

        return $this->redirectToRoute('app_management_maintenance');
    }

    #[Route('/management/maintenance/signal/{id}/status', name: 'app_management_maintenance_signal_status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function status(int $id, Request $request): Response
    {
        $manager = $this->requireManager();
        $this->requireCsrf('maintenance_status_'.$id, $request);
        $signal = $this->requireSignal($id);
        $status = MaintenanceSignalStatus::tryFrom($request->request->getString('status'));
        if (!$status instanceof MaintenanceSignalStatus) {
            throw new InvalidArgumentException('Невалиден статус на сигнала.');
        }

        $this->signalService->changeStatus(
            $signal,
            $status,
            $manager,
            $this->nowUtc(),
            $request->request->getString('note'),
        );
        $this->addFlash('success', 'Статусът е обновен.');

        return $this->redirectToRoute('app_management_maintenance');
    }

    #[Route('/management/maintenance/asset/new', name: 'app_management_maintenance_asset_new', methods: ['GET', 'POST'])]
    public function assetNew(Request $request): Response
    {
        $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('maintenance_asset_create', $request);

            try {
                $category = BuildingAssetCategory::tryFrom($request->request->getString('category'));
                if (!$category instanceof BuildingAssetCategory) {
                    throw new InvalidArgumentException('Невалидна категория на актива.');
                }

                $interval = $request->request->getString('inspection_interval_months');
                $this->registryService->createAsset(
                    $request->request->getString('name'),
                    $category,
                    $request->request->getString('location'),
                    $this->optionalString($request->request->getString('manufacturer')),
                    $this->optionalString($request->request->getString('model')),
                    $this->optionalString($request->request->getString('serial_number')),
                    $this->optionalDate($request->request->getString('installed_at')),
                    $this->optionalDate($request->request->getString('warranty_until')),
                    '' === trim($interval) ? null : $this->positiveInt($interval, 'Интервалът за проверка трябва да е положително число.'),
                    $this->optionalDate($request->request->getString('next_inspection_at')),
                );
                $this->addFlash('success', 'Активът е създаден.');

                return $this->redirectToRoute('app_management_maintenance');
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/maintenance/asset_new.html.twig', [
            'categories' => BuildingAssetCategory::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/maintenance/supplier/new', name: 'app_management_maintenance_supplier_new', methods: ['GET', 'POST'])]
    public function supplierNew(Request $request): Response
    {
        $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('maintenance_supplier_create', $request);

            try {
                $this->registryService->createSupplier(
                    $request->request->getString('name'),
                    $this->optionalString($request->request->getString('registration_number')),
                    $this->optionalString($request->request->getString('contact_person')),
                    $this->optionalString($request->request->getString('email')),
                    $this->optionalString($request->request->getString('phone')),
                    $this->optionalString($request->request->getString('address')),
                    $this->optionalString($request->request->getString('note')),
                );
                $this->addFlash('success', 'Доставчикът е създаден.');

                return $this->redirectToRoute('app_management_maintenance');
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/maintenance/supplier_new.html.twig', ['error' => $error], new Response(status: $status));
    }

    #[Route('/management/maintenance/contract/new', name: 'app_management_maintenance_contract_new', methods: ['GET', 'POST'])]
    public function contractNew(Request $request): Response
    {
        $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('maintenance_contract_create', $request);

            try {
                $supplier = $this->requireSupplier($request->request->getInt('supplier_id'));
                $asset = $this->optionalAsset($request->request->getInt('asset_id'));
                $this->registryService->createContract(
                    $supplier,
                    $request->request->getString('title'),
                    $this->requiredDate($request->request->getString('starts_at')),
                    $this->nowUtc(),
                    $asset,
                    $this->optionalDate($request->request->getString('ends_at')),
                    $this->optionalString($request->request->getString('reference')),
                    $this->optionalString($request->request->getString('note')),
                );
                $this->addFlash('success', 'Договорът е създаден.');

                return $this->redirectToRoute('app_management_maintenance');
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        /** @var list<MaintenanceSupplier> $suppliers */
        $suppliers = $this->entityManager->getRepository(MaintenanceSupplier::class)->findBy(['active' => true], ['name' => 'ASC']);
        /** @var list<BuildingAsset> $assets */
        $assets = $this->entityManager->getRepository(BuildingAsset::class)->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('management/maintenance/contract_new.html.twig', [
            'suppliers' => $suppliers,
            'assets' => $assets,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/maintenance/event/new', name: 'app_management_maintenance_event_new', methods: ['GET', 'POST'])]
    public function eventNew(Request $request): Response
    {
        $manager = $this->requireManager();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('maintenance_event_create', $request);

            try {
                $asset = $this->requireAsset($request->request->getInt('asset_id'));
                $type = MaintenanceEventType::tryFrom($request->request->getString('type'));
                if (!$type instanceof MaintenanceEventType) {
                    throw new InvalidArgumentException('Невалиден тип събитие.');
                }

                $this->registryService->recordEvent(
                    $asset,
                    $type,
                    $this->requiredDate($request->request->getString('performed_at')),
                    $request->request->getString('summary'),
                    $manager,
                    $this->nowUtc(),
                    $this->optionalSupplier($request->request->getInt('supplier_id')),
                    $this->optionalContract($request->request->getInt('contract_id')),
                    $this->optionalSignal($request->request->getInt('signal_id')),
                    $this->optionalString($request->request->getString('note')),
                    $this->optionalDate($request->request->getString('next_inspection_at')),
                );
                $this->addFlash('success', 'Събитието по поддръжката е записано.');

                return $this->redirectToRoute('app_management_maintenance');
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        /** @var list<BuildingAsset> $assets */
        $assets = $this->entityManager->getRepository(BuildingAsset::class)->findBy(['active' => true], ['name' => 'ASC']);
        /** @var list<MaintenanceSupplier> $suppliers */
        $suppliers = $this->entityManager->getRepository(MaintenanceSupplier::class)->findBy(['active' => true], ['name' => 'ASC']);
        /** @var list<MaintenanceContract> $contracts */
        $contracts = $this->entityManager->getRepository(MaintenanceContract::class)->findBy(['active' => true], ['createdAt' => 'DESC']);
        /** @var list<MaintenanceSignal> $signals */
        $signals = $this->entityManager->getRepository(MaintenanceSignal::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('management/maintenance/event_new.html.twig', [
            'types' => MaintenanceEventType::cases(),
            'assets' => $assets,
            'suppliers' => $suppliers,
            'contracts' => $contracts,
            'signals' => $signals,
            'error' => $error,
        ], new Response(status: $status));
    }

    private function requireManager(): User
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER', null, 'Management access is required.');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        return $user;
    }

    private function requireSignal(int $id): MaintenanceSignal
    {
        $signal = $this->entityManager->find(MaintenanceSignal::class, $id);
        if (!$signal instanceof MaintenanceSignal) {
            throw $this->createNotFoundException('Maintenance signal not found.');
        }

        return $signal;
    }

    private function requireAsset(int $id): BuildingAsset
    {
        $asset = $this->entityManager->find(BuildingAsset::class, $id);
        if (!$asset instanceof BuildingAsset || !$asset->isActive()) {
            throw new InvalidArgumentException('Избраният актив не е наличен.');
        }

        return $asset;
    }

    private function optionalAsset(int $id): ?BuildingAsset
    {
        return $id > 0 ? $this->requireAsset($id) : null;
    }

    private function requireSupplier(int $id): MaintenanceSupplier
    {
        $supplier = $this->entityManager->find(MaintenanceSupplier::class, $id);
        if (!$supplier instanceof MaintenanceSupplier || !$supplier->isActive()) {
            throw new InvalidArgumentException('Избраният доставчик не е наличен.');
        }

        return $supplier;
    }

    private function optionalSupplier(int $id): ?MaintenanceSupplier
    {
        return $id > 0 ? $this->requireSupplier($id) : null;
    }

    private function optionalContract(int $id): ?MaintenanceContract
    {
        if ($id <= 0) {
            return null;
        }

        $contract = $this->entityManager->find(MaintenanceContract::class, $id);
        if (!$contract instanceof MaintenanceContract || !$contract->isActive()) {
            throw new InvalidArgumentException('Избраният договор не е наличен.');
        }

        return $contract;
    }

    private function optionalSignal(int $id): ?MaintenanceSignal
    {
        return $id > 0 ? $this->requireSignal($id) : null;
    }

    private function requiredDate(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::LOCAL_TIMEZONE));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Невалидна дата.');
        }

        return $date;
    }

    private function optionalDate(string $value): ?DateTimeImmutable
    {
        return '' === trim($value) ? null : $this->requiredDate($value);
    }

    private function optionalString(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function positiveInt(string $value, string $message): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^[1-9]\\d*$/', $value)) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::UTC));
    }
}
