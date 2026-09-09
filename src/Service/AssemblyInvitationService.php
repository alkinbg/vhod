<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyInvitationPosting;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\GeneralAssemblyStatus;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use DomainException;
use Twig\Environment;

final readonly class AssemblyInvitationService
{
    public function __construct(
        private Environment $twig,
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
    ) {}

    public function renderPdf(GeneralAssembly $assembly): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            $this->twig->render('assemblies/invitation_pdf.html.twig', ['assembly' => $assembly]),
            'UTF-8',
        );
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function recordPosting(
        User $actor,
        GeneralAssembly $assembly,
        DateTimeImmutable $postedAt,
        string $postingPlace,
        ?Document $evidenceDocument = null,
        ?string $notes = null,
    ): AssemblyInvitationPosting {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
        if (GeneralAssemblyStatus::DRAFT === $assembly->getStatus()) {
            throw new DomainException('Physical posting can be recorded only after the General Assembly is convened.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $postedAt, $postingPlace, $evidenceDocument, $notes): AssemblyInvitationPosting {
            $posting = AssemblyInvitationPosting::record(
                $assembly,
                $postedAt,
                $postingPlace,
                $actor,
                $evidenceDocument,
                $notes,
            );
            $entityManager->persist($posting);

            return $posting;
        });
    }
}
