<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Person;
use App\Entity\User;
use App\Security\ComplianceAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComplianceAccessPolicyTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool, bool, bool, bool, bool}> */
    public static function capabilityMatrix(): iterable
    {
        yield 'resident' => [[], false, false, false, false, false];
        yield 'cashier' => [['ROLE_CASHIER'], false, false, false, false, false];
        yield 'manager' => [['ROLE_MANAGER'], true, true, true, true, true];
        yield 'controller' => [['ROLE_CONTROLLER'], true, false, false, false, true];
        yield 'admin' => [['ROLE_ADMIN'], true, true, true, true, true];
    }

    #[DataProvider('capabilityMatrix')]
    public function testCapabilityMatrix(
        array $roles,
        bool $canView,
        bool $canManageRegistry,
        bool $canRecordMandate,
        bool $canRecordMonthlyReport,
        bool $canRecordAnnualAudit,
    ): void {
        $policy = new ComplianceAccessPolicy();
        $user = $this->user($roles);

        self::assertSame($canView, $policy->canView($user));
        self::assertSame($canManageRegistry, $policy->canManageRegistry($user));
        self::assertSame($canRecordMandate, $policy->canRecordMandate($user));
        self::assertSame($canRecordMonthlyReport, $policy->canRecordMonthlyReport($user));
        self::assertSame($canRecordAnnualAudit, $policy->canRecordAnnualAudit($user));
    }

    public function testInactivePrivilegedUserHasNoComplianceCapabilities(): void
    {
        $policy = new ComplianceAccessPolicy();
        $user = $this->user(['ROLE_ADMIN']);
        $user->deactivate();

        self::assertFalse($policy->canView($user));
        self::assertFalse($policy->canManageRegistry($user));
        self::assertFalse($policy->canRecordMandate($user));
        self::assertFalse($policy->canRecordMonthlyReport($user));
        self::assertFalse($policy->canRecordAnnualAudit($user));
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $person = new Person('Тест', 'Потребител', email: 'policy-'.md5(implode(',', $roles)).'@example.com');
        $user = new User($person, $person->getEmail() ?? 'fallback@example.com', 'hash');
        $user->setRoles($roles);

        return $user;
    }
}
