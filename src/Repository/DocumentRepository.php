<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Document> */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @param list<DocumentAccessLevel> $levels
     * @return list<Document>
     */
    public function findVisible(array $levels, ?DocumentCategory $category = null): array
    {
        if ([] === $levels) {
            return [];
        }

        $values = array_map(
            static fn (DocumentAccessLevel $level): string => $level->value,
            $levels,
        );

        $queryBuilder = $this->createQueryBuilder('d')
            ->andWhere('d.accessLevel IN (:levels)')
            ->setParameter('levels', $values, ArrayParameterType::STRING)
            ->orderBy('d.uploadedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        if ($category instanceof DocumentCategory) {
            $queryBuilder
                ->andWhere('d.category = :category')
                ->setParameter('category', $category->value);
        }

        /** @var list<Document> $documents */
        $documents = $queryBuilder->getQuery()->getResult();

        return $documents;
    }
}
