<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Document;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Security\DocumentAccessPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DocumentAccessPolicyTest extends TestCase
{
    public function testResidentCanReadOnlyResidentDocuments(): void
    {
        $policy = $this->policy();
        $resident = $this->user();

        self::assertSame([DocumentAccessLevel::RESIDENTS], $policy->allowedLevels($resident));
        self::assertTrue($policy->canView($resident, $this->document(DocumentAccessLevel::RESIDENTS)));
        self::assertFalse($policy->canView($resident, $this->document(DocumentAccessLevel::FINANCE)));
        self::assertFalse($policy->canView($resident, $this->document(DocumentAccessLevel::MANAGEMENT)));
        self::assertFalse($policy->canManageOfficialContent($resident));
    }

    public function testCashierAndControllerCanReadFinanceButNotManagement(): void
    {
        $policy = $this->policy();

        foreach (['ROLE_CASHIER', 'ROLE_CONTROLLER'] as $role) {
            $user = $this->user($role.'@example.com', [$role]);
            self::assertSame(
                [DocumentAccessLevel::RESIDENTS, DocumentAccessLevel::FINANCE],
                $policy->allowedLevels($user),
            );
            self::assertTrue($policy->canView($user, $this->document(DocumentAccessLevel::FINANCE)));
            self::assertFalse($policy->canView($user, $this->document(DocumentAccessLevel::MANAGEMENT)));
            self::assertFalse($policy->canManageOfficialContent($user));
        }
    }

    public function testManagerAndAdminCanReadAllLevelsAndManageOfficialContent(): void
    {
        $policy = $this->policy();

        foreach (['ROLE_MANAGER', 'ROLE_ADMIN'] as $role) {
            $user = $this->user($role.'@example.com', [$role]);
            self::assertSame(
                [DocumentAccessLevel::RESIDENTS, DocumentAccessLevel::FINANCE, DocumentAccessLevel::MANAGEMENT],
                $policy->allowedLevels($user),
            );
            self::assertTrue($policy->canView($user, $this->document(DocumentAccessLevel::MANAGEMENT)));
            self::assertTrue($policy->canManageOfficialContent($user));
        }
    }

    public function testInactiveUserReceivesNoDocumentOrManagementAccess(): void
    {
        $policy = $this->policy();
        $manager = $this->user('inactive@example.com', ['ROLE_MANAGER']);
        $manager->deactivate();

        self::assertSame([], $policy->allowedLevels($manager));
        self::assertFalse($policy->canView($manager, $this->document(DocumentAccessLevel::RESIDENTS)));
        self::assertFalse($policy->canManageOfficialContent($manager));
    }

    private function policy(): DocumentAccessPolicy
    {
        self::assertTrue(class_exists(DocumentAccessPolicy::class), 'DocumentAccessPolicy has not been implemented yet.');

        return new DocumentAccessPolicy();
    }

    /** @param list<string> $roles */
    private function user(string $email = 'resident@example.com', array $roles = []): User
    {
        $user = new User(new Person('Иван', 'Иванов', email: $email), $email, 'hash');
        $user->setRoles($roles);

        return $user;
    }

    private function document(DocumentAccessLevel $level): Document
    {
        return Document::record(
            DocumentCategory::OTHER,
            $level,
            'Документ',
            null,
            'document.pdf',
            bin2hex(random_bytes(16)).'.pdf',
            'application/pdf',
            100,
            $this->user('uploader-'.bin2hex(random_bytes(4)).'@example.com'),
            new DateTimeImmutable('2026-09-09 08:00:00 UTC'),
        );
    }
}
