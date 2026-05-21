<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\InitiateTransferCommand;
use App\Application\Command\InitiateTransferHandler;
use App\Application\Query\GetTransferHandler;
use App\Application\Query\GetTransferQuery;
use App\Domain\Exception\AccountFrozenException;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Exception\CurrencyMismatchException;
use App\Domain\Exception\DuplicateTransferException;
use App\Domain\Exception\InsufficientFundsException;
use App\Domain\Exception\SameAccountTransferException;
use App\Domain\Exception\TransferNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Transfer HTTP controller — thin layer only.
 *
 * Responsibilities:
 *   1. Parse and validate the HTTP request.
 *   2. Apply rate limiting per from_account_id.
 *   3. Delegate to the appropriate handler.
 *   4. Map domain exceptions to HTTP responses.
 *
 * No business logic lives here. All transfer rules are in
 * TransferDomainService and InitiateTransferHandler.
 */
#[Route('/api/v1')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly InitiateTransferHandler $initiateHandler,
        private readonly GetTransferHandler $getHandler,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $transferByAccountLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $traceId = $request->attributes->get('trace_id', uniqid('trace_', true));

        // Parse JSON body — return 400 on malformed JSON
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Request body must be valid JSON.',
                Response::HTTP_BAD_REQUEST,
                $traceId,
            );
        }

        // Validate input shape
        $constraints = new Assert\Collection([
            'idempotency_key' => [
                new Assert\NotBlank(),
                new Assert\Length(max: 128),
            ],
            'from_account_id' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 26, max: 26),
            ],
            'to_account_id' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 26, max: 26),
            ],
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Regex(
                    pattern: '/^\d+(\.\d{1,2})?$/',
                    message: 'Amount must be a positive decimal string like "100.50".',
                ),
            ],
            'currency' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 3, max: 3),
                new Assert\Currency(),
            ],
            'description' => new Assert\Optional([
                new Assert\Length(max: 512),
            ]),
        ]);

        $violations = $this->validator->validate($data, $constraints);

        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }

            return $this->errorResponse(
                'VALIDATION_ERROR',
                implode('; ', $messages),
                Response::HTTP_BAD_REQUEST,
                $traceId,
            );
        }

        // Rate limit per from_account_id — not per IP.
        // IP-based limits are trivially bypassed and don't protect individual accounts.
        $limiter  = $this->transferByAccountLimiter->create($data['from_account_id']);
        $limit    = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            return $this->errorResponse(
                'RATE_LIMIT_EXCEEDED',
                'Too many transfer requests for this account. Please retry later.',
                Response::HTTP_TOO_MANY_REQUESTS,
                $traceId,
                ['Retry-After' => (string) max(1, $retryAfter)],
            );
        }

        $command = new InitiateTransferCommand(
            idempotencyKey: $data['idempotency_key'],
            fromAccountId:  $data['from_account_id'],
            toAccountId:    $data['to_account_id'],
            amount:         $data['amount'],
            currency:       strtoupper($data['currency']),
            description:    $data['description'] ?? null,
        );

        try {
            $result = $this->initiateHandler->handle($command);

            return new JsonResponse($result['response'], $result['httpStatus']);

        } catch (SameAccountTransferException) {
            return $this->errorResponse(
                'SAME_ACCOUNT_TRANSFER',
                'Source and destination accounts must be different.',
                Response::HTTP_CONFLICT,
                $traceId,
            );
        } catch (DuplicateTransferException) {
            return $this->errorResponse(
                'DUPLICATE_REQUEST',
                'A transfer with this idempotency key is already being processed.',
                Response::HTTP_CONFLICT,
                $traceId,
            );
        } catch (AccountNotFoundException $e) {
            return $this->errorResponse(
                'ACCOUNT_NOT_FOUND',
                $e->getMessage(),
                Response::HTTP_NOT_FOUND,
                $traceId,
            );
        } catch (InsufficientFundsException) {
            return $this->errorResponse(
                'INSUFFICIENT_FUNDS',
                'The source account has insufficient funds for this transfer.',
                Response::HTTP_CONFLICT,
                $traceId,
            );
        } catch (AccountFrozenException) {
            return $this->errorResponse(
                'ACCOUNT_FROZEN',
                'One or both accounts are not active.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $traceId,
            );
        } catch (CurrencyMismatchException $e) {
            return $this->errorResponse(
                'CURRENCY_MISMATCH',
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $traceId,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                $e->getMessage(),
                Response::HTTP_BAD_REQUEST,
                $traceId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during transfer', [
                'trace_id' => $traceId,
                'error'    => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'INTERNAL_ERROR',
                'An unexpected error occurred. Please try again later.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $traceId,
            );
        }
    }

    #[Route('/transfers/{ulid}', name: 'transfer_get', methods: ['GET'])]
    public function get(string $ulid, Request $request): JsonResponse
    {
        $traceId = $request->attributes->get('trace_id', uniqid('trace_', true));

        try {
            $response = $this->getHandler->handle(new GetTransferQuery($ulid));

            return new JsonResponse($response, Response::HTTP_OK);

        } catch (TransferNotFoundException $e) {
            return $this->errorResponse(
                'TRANSFER_NOT_FOUND',
                $e->getMessage(),
                Response::HTTP_NOT_FOUND,
                $traceId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error fetching transfer', [
                'trace_id'    => $traceId,
                'transfer_id' => $ulid,
                'error'       => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $traceId,
            );
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function errorResponse(
        string $code,
        string $message,
        int $httpStatus,
        string $traceId,
        array $headers = [],
    ): JsonResponse {
        $response = new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message, 'trace_id' => $traceId]],
            $httpStatus,
        );

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
