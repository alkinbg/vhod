<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AssemblyVote> */
final class AssemblyVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssemblyVote::class);
    }

    public function findForItemAndEntry(AssemblyAgendaItem $item, AssemblyElectorateEntry $entry): ?AssemblyVote
    {
        return $this->findOneBy(['agendaItem' => $item, 'electorateEntry' => $entry]);
    }

    /** @return list<AssemblyVote> */
    public function findForItem(AssemblyAgendaItem $item): array
    {
        return $this->findBy(['agendaItem' => $item], ['id' => 'ASC']);
    }
}
