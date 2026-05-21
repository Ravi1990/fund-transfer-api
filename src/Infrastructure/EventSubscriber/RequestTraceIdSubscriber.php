<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Generates a unique trace_id per request and attaches it to:
 *   - request attributes (available to controllers and handlers)
 *   - response headers (X-Trace-Id for client-side correlation)
 *
 * Using EventSubscriberInterface (not a listener) as specified —
 * subscribers declare their own event map, making the wiring explicit.
 */
final class RequestTraceIdSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 255],  // Highest priority — runs first
            KernelEvents::RESPONSE => ['onResponse', -255], // Lowest priority — runs last
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Honour upstream trace ID (e.g. from load balancer) or generate a new one
        $traceId = $event->getRequest()->headers->get('X-Trace-Id')
            ?? $this->generateTraceId();

        $event->getRequest()->attributes->set('trace_id', $traceId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $traceId = $event->getRequest()->attributes->get('trace_id');
        if ($traceId !== null) {
            $event->getResponse()->headers->set('X-Trace-Id', $traceId);
        }
    }

    private function generateTraceId(): string
    {
        return sprintf(
            '%s-%s',
            date('Ymd'),
            bin2hex(random_bytes(8)),
        );
    }
}
