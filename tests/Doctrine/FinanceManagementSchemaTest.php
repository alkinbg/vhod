<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\BudgetLine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FinanceManagementSchemaTest extends KernelTestCase
{
    public function testBudgetFundRelationRestrictsHardDeletion(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $mapping = $entityManager->getClassMetadata(BudgetLine::class)->getAssociationMapping('fund');
        self::assertInstanceOf(ToOneOwningSideMapping::class, $mapping);
        self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete);
    }

    public function testBudgetLineIsUniquePerYearFundAndCategory(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $table = $entityManager->getClassMetadata(BudgetLine::class)->table;
        self::assertContains(
            ['columns' => ['budget_year', 'fund_id', 'category']],
            array_values($table['uniqueConstraints'] ?? []),
        );
    }
}
