<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FinancialReversalUniquenessTest extends KernelTestCase
{
    public function testFinancialParentAssociationsRestrictHardDeletion(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $expected = [
            Expense::class => ['fund'],
            ExpenseReversal::class => ['expense'],
            ExternalIncome::class => ['fund'],
            ExternalIncomeReversal::class => ['income'],
        ];

        foreach ($expected as $entityClass => $fields) {
            $metadata = $entityManager->getClassMetadata($entityClass);
            foreach ($fields as $field) {
                $mapping = $metadata->getAssociationMapping($field);
                self::assertInstanceOf(ToOneOwningSideMapping::class, $mapping);
                self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete);
            }
        }
    }

    public function testReversalParentsAreUniqueAtDatabaseMetadataLevel(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $expenseTable = $entityManager->getClassMetadata(ExpenseReversal::class)->table;
        $incomeTable = $entityManager->getClassMetadata(ExternalIncomeReversal::class)->table;

        self::assertContains(['columns' => ['expense_id']], array_values($expenseTable['uniqueConstraints'] ?? []));
        self::assertContains(['columns' => ['external_income_id']], array_values($incomeTable['uniqueConstraints'] ?? []));
    }
}
