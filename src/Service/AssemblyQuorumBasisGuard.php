<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeneralAssembly;
use App\Enum\AssemblyLegalResult;
use App\Repository\AssemblyQuorumCheckRepository;
use DomainException;

final readonly class AssemblyQuorumBasisGuard
{
    public function __construct(private AssemblyQuorumCheckRepository $quorumChecks)
    {
    }

    public function assertValid(GeneralAssembly $assembly): void
    {
        $latest = $this->quorumChecks->findLatestForAssembly($assembly);
        if (null === $latest || AssemblyLegalResult::VALID !== $latest->getResult()) {
            throw new DomainException('A persisted valid quorum check is required before automatic legal finalization.');
        }
    }
}
