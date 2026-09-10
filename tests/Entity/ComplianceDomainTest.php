<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceCompletion;
use App\Entity\CondominiumProfile;
use App\Entity\ManagementMandate;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\ComplianceCompletionType;
use App\Enum\ManagementMandateKind;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ComplianceDomainTest extends TestCase
{
    public function testRegistryProfileStoresNormalizedExternalIdentityAndUpdateAudit(): void
    {
        $creator = $this->user('creator-compliance@example.com');
        $updater = $this->user('updater-compliance@example.com');
        $createdAt = new DateTimeImmutable('2026-09-10T07:00:00+03:00');
        $updatedAt = new DateTimeImmutable('2026-09-10T08:30:00+03:00');

        $profile = CondominiumProfile::create($creator, $createdAt);
        $profile->updateRegistryData(
            '  EISES-RUSE-000123  ',
            '  18.123.456  ',
            new DateTimeImmutable('2026-09-01'),
            $updater,
            $updatedAt,
        );

        self::assertSame('primary', $profile->getScopeKey());
        self::assertSame('EISES-RUSE-000123', $profile->getRegistryIdentifier());
        self::assertSame('18.123.456', $profile->getRegistryParcelNumber());
        self::assertSame('2026-09-01', $profile->getRegistryRegisteredAt()?->format('Y-m-d'));
        self::assertSame($creator, $profile->getCreatedBy());
        self::assertSame($updater, $profile->getUpdatedBy());
        self::assertSame('2026-09-10T04:00:00+00:00', $profile->getCreatedAt()->format(DATE_ATOM));
        self::assertSame('2026-09-10T05:30:00+00:00', $profile->getUpdatedAt()->format(DATE_ATOM));
    }

    public function testRegistryIdentifierCanBeSetOnceAndCannotThenBeChangedOrCleared(): void
    {
        $actor = $this->user('registry-immutable@example.com');
        $profile = CondominiumProfile::create($actor, new DateTimeImmutable('2026-09-10T08:00:00Z'));
        $profile->updateRegistryData(
            'EISES-RUSE-000123',
            '18.123.456',
            new DateTimeImmutable('2026-09-01'),
            $actor,
            new DateTimeImmutable('2026-09-10T08:01:00Z'),
        );

        $profile->updateRegistryData(
            ' EISES-RUSE-000123 ',
            '18.123.999',
            new DateTimeImmutable('2026-09-02'),
            $actor,
            new DateTimeImmutable('2026-09-10T08:02:00Z'),
        );
        self::assertSame('EISES-RUSE-000123', $profile->getRegistryIdentifier());
        self::assertSame('18.123.999', $profile->getRegistryParcelNumber());

        foreach (['EISES-RUSE-000999', '   '] as $invalidIdentifier) {
            try {
                $profile->updateRegistryData(
                    $invalidIdentifier,
                    '18.000.000',
                    new DateTimeImmutable('2026-09-03'),
                    $actor,
                    new DateTimeImmutable('2026-09-10T08:03:00Z'),
                );
                self::fail('An already assigned external registry identifier was changed or cleared.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            self::assertSame('EISES-RUSE-000123', $profile->getRegistryIdentifier());
            self::assertSame('18.123.999', $profile->getRegistryParcelNumber());
            self::assertSame('2026-09-02', $profile->getRegistryRegisteredAt()?->format('Y-m-d'));
        }
    }

    public function testRegistryProfileAllowsUnknownExternalIdentityWithoutInventingAValue(): void
    {
        $actor = $this->user('registry-empty@example.com');
        $profile = CondominiumProfile::create($actor, new DateTimeImmutable('2026-09-10T08:00:00Z'));

        $profile->updateRegistryData('   ', null, null, $actor, new DateTimeImmutable('2026-09-10T08:01:00Z'));

        self::assertNull($profile->getRegistryIdentifier());
        self::assertNull($profile->getRegistryParcelNumber());
        self::assertNull($profile->getRegistryRegisteredAt());
    }

    public function testMandateIsNormalizedHistoricalFactAndRejectsInvalidDateOrder(): void
    {
        $actor = $this->user('mandate@example.com');
        $mandate = ManagementMandate::record(
            ManagementMandateKind::MANAGER,
            '  Мария Иванова  ',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2028-08-31'),
            $actor,
            new DateTimeImmutable('2026-09-01T12:00:00+03:00'),
            note: '  Избрана от общото събрание  ',
        );

        self::assertSame(ManagementMandateKind::MANAGER, $mandate->getKind());
        self::assertSame('Мария Иванова', $mandate->getHolderLabel());
        self::assertSame('2026-09-01', $mandate->getStartsAt()->format('Y-m-d'));
        self::assertSame('2028-08-31', $mandate->getEndsAt()->format('Y-m-d'));
        self::assertSame('Избрана от общото събрание', $mandate->getNote());
        self::assertSame($actor, $mandate->getRecordedBy());
        self::assertSame('2026-09-01T09:00:00+00:00', $mandate->getRecordedAt()->format(DATE_ATOM));
        self::assertFalse(method_exists($mandate, 'setEndsAt'));

        $this->expectException(InvalidArgumentException::class);
        ManagementMandate::record(
            ManagementMandateKind::MANAGEMENT_BOARD,
            'Управителен съвет',
            new DateTimeImmutable('2026-09-02'),
            new DateTimeImmutable('2026-09-01'),
            $actor,
            new DateTimeImmutable('2026-09-01T10:00:00Z'),
        );
    }

    public function testComplianceCompletionValidatesPeriodKeyByType(): void
    {
        $actor = $this->user('completion@example.com');

        $monthly = ComplianceCompletion::record(
            ComplianceCompletionType::MONTHLY_REPORT,
            '2026-08',
            new DateTimeImmutable('2026-09-03'),
            $actor,
            new DateTimeImmutable('2026-09-03T09:00:00Z'),
            note: '  Отчетът е публикуван  ',
        );
        $annual = ComplianceCompletion::record(
            ComplianceCompletionType::ANNUAL_CASH_AUDIT,
            '2026',
            new DateTimeImmutable('2026-06-30'),
            $actor,
            new DateTimeImmutable('2026-06-30T12:00:00Z'),
        );

        self::assertSame('2026-08', $monthly->getPeriodKey());
        self::assertSame('Отчетът е публикуван', $monthly->getNote());
        self::assertSame('2026', $annual->getPeriodKey());
        self::assertFalse(method_exists($monthly, 'setPeriodKey'));

        foreach ([
            [ComplianceCompletionType::MONTHLY_REPORT, '2026'],
            [ComplianceCompletionType::MONTHLY_REPORT, '2026-13'],
            [ComplianceCompletionType::ANNUAL_CASH_AUDIT, '2026-08'],
        ] as [$type, $periodKey]) {
            try {
                ComplianceCompletion::record(
                    $type,
                    $periodKey,
                    new DateTimeImmutable('2026-09-03'),
                    $actor,
                    new DateTimeImmutable('2026-09-03T09:00:00Z'),
                );
                self::fail(sprintf('Invalid period key %s was accepted for %s.', $periodKey, $type->value));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function user(string $email): User
    {
        $person = new Person('Тест', 'Потребител', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles(['ROLE_MANAGER']);

        return $user;
    }
}
