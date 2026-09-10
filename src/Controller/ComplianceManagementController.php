<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BuildingAsset;
use App\Entity\ComplianceCompletion;
use App\Entity\CondominiumProfile;
use App\Entity\MaintenanceContract;
use App\Entity\ManagementMandate;
use App\Entity\User;
use App\Enum\ComplianceCompletionType;
use App\Enum\ManagementMandateKind;
use App\Security\ComplianceAccessPolicy;
use App\Service\ComplianceRegistryService;
use App\Service\ComplianceReminderService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ComplianceManagementController extends AbstractController
{
    private const LOCAL_TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceAccessPolicy $accessPolicy,
        private readonly ComplianceRegistryService $registryService,
        private readonly ComplianceReminderService $reminderService,
    ) {}

    #[Route('/management/compliance', name: 'app_management_compliance', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireViewer();

        /** @var list<ManagementMandate> $mandates */
        $mandates = $this->entityManager->getRepository(ManagementMandate::class)->findBy([], ['endsAt' => 'DESC', 'startsAt' => 'DESC']);
        /** @var list<ComplianceCompletion> $completions */
        $completions = $this->entityManager->getRepository(ComplianceCompletion::class)->findBy([], ['completedAt' => 'DESC', 'recordedAt' => 'DESC']);
        /** @var list<MaintenanceContract> $contracts */
        $contracts = $this->entityManager->getRepository(MaintenanceContract::class)->findBy([], ['endsAt' => 'ASC']);
        /** @var list<BuildingAsset> $assets */
        $assets = $this->entityManager->getRepository(BuildingAsset::class)->findBy(['active' => true], ['name' => 'ASC']);

        $today = new DateTimeImmutable('today', new DateTimeZone(self::LOCAL_TIMEZONE));
        $previousMonth = $today->modify('first day of previous month');

        return $this->render('management/compliance/index.html.twig', [
            'profile' => $this->registryService->profile(),
            'mandates' => $mandates,
            'completions' => $completions,
            'reminders' => $this->reminderService->build($today, $mandates, $completions, $contracts, $assets),
            'mandateKinds' => ManagementMandateKind::cases(),
            'canManageRegistry' => $this->accessPolicy->canManageRegistry($user),
            'canRecordMandate' => $this->accessPolicy->canRecordMandate($user),
            'canRecordMonthlyReport' => $this->accessPolicy->canRecordMonthlyReport($user),
            'canRecordAnnualAudit' => $this->accessPolicy->canRecordAnnualAudit($user),
            'defaultMonthlyPeriod' => $previousMonth->format('Y-m'),
            'defaultAnnualPeriod' => $today->format('Y'),
            'today' => $today,
        ]);
    }

    #[Route('/management/compliance/registry', name: 'app_management_compliance_registry', methods: ['POST'])]
    public function registry(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->accessPolicy->canManageRegistry($user)) {
            throw $this->createAccessDeniedException('Compliance registry management access is required.');
        }
        $this->requireCsrf('compliance_registry', $request);

        try {
            $this->registryService->updateRegistryData(
                $user,
                $this->optionalString($request->request->getString('registry_identifier')),
                $this->optionalString($request->request->getString('registry_parcel_number')),
                $this->optionalDate($request->request->getString('registry_registered_at')),
                $this->nowUtc(),
            );
        } catch (InvalidArgumentException|DomainException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Регистрационните данни са обновени.');

        return $this->redirectToRoute('app_management_compliance');
    }

    #[Route('/management/compliance/mandate', name: 'app_management_compliance_mandate', methods: ['POST'])]
    public function mandate(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->accessPolicy->canRecordMandate($user)) {
            throw $this->createAccessDeniedException('Management mandate recording access is required.');
        }
        $this->requireCsrf('compliance_mandate', $request);

        try {
            $kind = ManagementMandateKind::tryFrom($request->request->getString('kind'));
            if (!$kind instanceof ManagementMandateKind) {
                throw new InvalidArgumentException('Невалиден вид на мандата.');
            }

            $this->registryService->recordMandate(
                $user,
                $kind,
                $request->request->getString('holder_label'),
                $this->requiredDate($request->request->getString('starts_at')),
                $this->requiredDate($request->request->getString('ends_at')),
                $this->nowUtc(),
                note: $this->optionalString($request->request->getString('note')),
            );
        } catch (InvalidArgumentException|DomainException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Мандатът е записан в историята.');

        return $this->redirectToRoute('app_management_compliance');
    }

    #[Route('/management/compliance/completion', name: 'app_management_compliance_completion', methods: ['POST'])]
    public function completion(Request $request): Response
    {
        $user = $this->requireUser();
        $type = ComplianceCompletionType::tryFrom($request->request->getString('type'));
        if (!$type instanceof ComplianceCompletionType) {
            return new Response('Невалиден вид изпълнение.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $allowed = match ($type) {
            ComplianceCompletionType::MONTHLY_REPORT => $this->accessPolicy->canRecordMonthlyReport($user),
            ComplianceCompletionType::ANNUAL_CASH_AUDIT => $this->accessPolicy->canRecordAnnualAudit($user),
        };
        if (!$allowed) {
            throw $this->createAccessDeniedException('Compliance completion recording access is required.');
        }
        $this->requireCsrf('compliance_completion_'.$type->value, $request);

        try {
            $this->registryService->recordCompletion(
                $user,
                $type,
                $request->request->getString('period_key'),
                $this->requiredDate($request->request->getString('completed_at')),
                $this->nowUtc(),
                note: $this->optionalString($request->request->getString('note')),
            );
        } catch (InvalidArgumentException|DomainException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Изпълнението е записано.');

        return $this->redirectToRoute('app_management_compliance');
    }

    private function requireViewer(): User
    {
        $user = $this->requireUser();
        if (!$this->accessPolicy->canView($user)) {
            throw $this->createAccessDeniedException('Compliance view access is required.');
        }

        return $user;
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        return $user;
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
