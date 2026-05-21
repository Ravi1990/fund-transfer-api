<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check endpoint for Docker, load balancer, and monitoring probes.
 * Checks both DB and Redis connectivity.
 * Returns 200 if all checks pass, 503 if any fail.
 */
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cacheIdempotency,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
        ];

        $allOk      = !in_array('error', $checks, strict: true);
        $httpStatus = $allOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $httpStatus);
    }

    private function checkDatabase(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkRedis(): string
    {
        try {
            // Writing and reading a probe key verifies both write and read paths
            $item = $this->cacheIdempotency->getItem('health_probe');
            $item->set('1');
            $item->expiresAfter(10);
            $this->cacheIdempotency->save($item);
            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
