<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Global exception handler — ensures no Doctrine or Symfony internals
 * leak to API consumers.
 *
 * All unhandled exceptions produce a consistent error envelope.
 * Controllers handle their own domain exceptions; this subscriber
 * catches anything that escapes (e.g. Doctrine connection errors).
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', -100],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request   = $event->getRequest();
        $traceId   = $request->attributes->get('trace_id', 'unknown');

        // Log full context for internal debugging — never sent to client
        $this->logger->error('Unhandled exception', [
            'trace_id'   => $traceId,
            'exception'  => $exception->getMessage(),
            'class'      => $exception::class,
            'file'       => $exception->getFile(),
            'line'       => $exception->getLine(),
        ]);

        // Return generic error — never expose stack traces or internal details
        $event->setResponse(new JsonResponse(
            [
                'error' => [
                    'code'     => 'INTERNAL_ERROR',
                    'message'  => 'An unexpected error occurred.',
                    'trace_id' => $traceId,
                ],
            ],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
