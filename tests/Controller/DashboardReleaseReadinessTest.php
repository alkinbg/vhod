<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\MaintenanceSignal;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardReleaseReadinessTest extends WebTestCase
{
    public function testDashboardShowsOnlyCurrentResidentsFinancialAndMaintenanceSummary(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $residentPerson = new Person('Иван', 'Иванов', email: 'dashboard-resident@example.com');
        $resident = new User($residentPerson, 'dashboard-resident@example.com', 'hash');
        $otherPerson = new Person('Мария', 'Петрова', email: 'dashboard-other@example.com');
        $other = new User($otherPerson, 'dashboard-other@example.com', 'hash');
        $ownUnit = new Unit('12');
        $otherUnit = new Unit('13');
        $ownRelation = new UnitRelation($residentPerson, $ownUnit, UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'), '50.0000');
        $otherRelation = new UnitRelation($otherPerson, $otherUnit, UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'), '50.0000');
        $fund = new Fund('operating', 'Управление и поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            1500,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );
        $ownCharge = Charge::post($policy, $ownUnit, new DateTimeImmutable('2026-09-01'), '1.0000', 1500, 1500, ['distribution' => 'per_unit'], new DateTimeImmutable('2026-09-01 08:00:00 UTC'));
        $otherCharge = Charge::post($policy, $otherUnit, new DateTimeImmutable('2026-09-01'), '1.0000', 9900, 9900, ['distribution' => 'per_unit'], new DateTimeImmutable('2026-09-01 08:00:00 UTC'));
        $openSignal = MaintenanceSignal::open($resident, MaintenanceSignalCategory::COMMON_AREA, MaintenanceSignalPriority::NORMAL, 'Осветление', 'Не работи лампа.', 'Етаж 2', new DateTimeImmutable('2026-09-10 08:00:00 UTC'));
        $closedSignal = MaintenanceSignal::open($resident, MaintenanceSignalCategory::CLEANING, MaintenanceSignalPriority::LOW, 'Почистване', 'Тестов сигнал.', 'Вход', new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $closedSignal->changeStatus(MaintenanceSignalStatus::CLOSED, new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
        $otherSignal = MaintenanceSignal::open($other, MaintenanceSignalCategory::OTHER, MaintenanceSignalPriority::NORMAL, 'Чужд сигнал', 'Не е за този потребител.', 'Етаж 3', new DateTimeImmutable('2026-09-10 08:00:00 UTC'));

        foreach ([$residentPerson, $resident, $otherPerson, $other, $ownUnit, $otherUnit, $ownRelation, $otherRelation, $fund, $policy, $ownCharge, $otherCharge, $openSignal, $closedSignal, $otherSignal] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($resident);
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('15.00 €', $text);
        self::assertStringNotContainsString('99.00 €', $text);
        self::assertSelectorTextContains('[data-testid="active-maintenance-count"]', '1');
        self::assertStringNotContainsString('Финансовият модул е следващият етап', $text);
        self::assertStringNotContainsString('Скоро', $text);
        self::assertSelectorExists('a[href="/maintenance"]');
        self::assertSelectorExists('a[href="/community"]');
    }
}
