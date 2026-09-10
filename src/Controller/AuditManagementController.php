<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditManagementController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/management/audit', name: 'app_management_audit', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN', null, 'Audit review access is restricted to administrators.');

        return $this->render('management/audit/index.html.twig', [
            'entries' => $this->entityManager->getRepository(AuditEntry::class)->findBy([], ['occurredAt' => 'DESC', 'id' => 'DESC'], 200),
        ]);
    }
}
