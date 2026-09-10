<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class HealthController
{
    public function __construct(private Connection $connection) {}

    #[Route('/healthz', name: 'app_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return new JsonResponse(['status' => 'ok']);
        } catch (Throwable) {
            return new JsonResponse(['status' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
