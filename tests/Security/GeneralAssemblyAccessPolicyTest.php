<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GeneralAssemblyAccessPolicyTest extends TestCase
{
    public function testOnlyManagerControllerAndAdminCanManage(): void
    {
        $policy = $this->policy();

        self::assertFalse($policy->canManage($this->user('resident@example.com')));
        self::assertFalse($policy->canManage($this->user('cashier@example.com', ['ROLE_CASHIER'])));
        self::assertTrue($policy->canManage($this->user('controller@example.com', ['ROLE_CONTROLLER'])));
        self::assertTrue($policy->canManage($this->user('manager@example.com', ['ROLE_MANAGER'])));
        self::assertTrue($policy->canManage($this->user('admin@example.com', ['ROLE_ADMIN'])));
    }

    public function testInactivePrivilegedUserCannotManage(): void
    {
        $policy = $this->policy();
        $controller = $this->user('inactive@example.com', ['ROLE_CONTROLLER']);
        $controller->deactivate();

        self::assertFalse($policy->canManage($controller));
    }

    public function testResidentCanViewOnlyFromConvenedStateOnward(): void
    {
        $this->assertDomainAvailable();

        $policy = $this->policy();
        $resident = $this->user('resident@example.com');
        $manager = $this->user('manager@example.com', ['ROLE_MANAGER']);
        $assembly = $this->draftAssembly($manager);

        self::assertFalse($policy->canViewResident($resident, $assembly));

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09 11:00:00 UTC'));
        self::assertTrue($policy->canViewResident($resident, $assembly));

        $assembly->start($manager, new DateTimeImmutable('2026-09-10 15:00:00 UTC'));
        self::assertTrue($policy->canViewResident($resident, $assembly));

        $assembly->close($manager, new DateTimeImmutable('2026-09-10 17:00:00 UTC'));
        self::assertTrue($policy->canViewResident($resident, $assembly));
    }

    public function testInactiveResidentCannotViewConvenedAssembly(): void
    {
        $this->assertDomainAvailable();

        $policy = $this->policy();
        $resident = $this->user('inactive-resident@example.com');
        $resident->deactivate();
        $manager = $this->user('manager@example.com', ['ROLE_MANAGER']);
        $assembly = $this->draftAssembly($manager);
        $assembly->convene($manager, new DateTimeImmutable('2026-09-09 11:00:00 UTC'));

        self::assertFalse($policy->canViewResident($resident, $assembly));
    }

    private function policy(): GeneralAssemblyAccessPolicy
    {
        self::assertTrue(class_exists(GeneralAssemblyAccessPolicy::class), 'GeneralAssemblyAccessPolicy has not been implemented yet.');

        return new GeneralAssemblyAccessPolicy();
    }

    private function assertDomainAvailable(): void
    {
        self::assertTrue(class_exists(GeneralAssembly::class), 'GeneralAssembly has not been implemented yet.');
        self::assertTrue(enum_exists(AssemblyConveningBasis::class), 'AssemblyConveningBasis has not been implemented yet.');
    }

    private function draftAssembly(User $manager): GeneralAssembly
    {
        return GeneralAssembly::draft(
            'Редовно общо събрание',
            new DateTimeImmutable('2026-09-10 15:00:00 UTC'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
            'Вход А, партер',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = new User(new Person('Иван', 'Иванов', email: $email), $email, 'hash');
        $user->setRoles($roles);

        return $user;
    }
}
