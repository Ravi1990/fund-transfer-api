<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\Account;
use App\Domain\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TransferApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        // createClient() must be called first — it boots the kernel.
        // All container access must happen after this call.
        $this->client = static::createClient();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE transfer_audit_log');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE transfers');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE accounts');
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $cache = static::getContainer()->get('cache.idempotency');
        $cache->clear();
    }

    private function createAccount(
        string $ownerName,
        int $balanceCents,
        string $currency = 'USD',
        AccountStatus $status = AccountStatus::Active,
    ): Account {
        $account = new Account($ownerName, $balanceCents, $currency, $status);
        $this->em->persist($account);
        $this->em->flush();
        return $account;
    }

    public function testSuccessfulTransfer(): void
    {
        $alice = $this->createAccount('Alice', 100000);
        $bob   = $this->createAccount('Bob', 50000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'happy-path-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '100.50',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('completed', $data['status']);
        self::assertSame('100.50', $data['amount']);
        self::assertSame($alice->getPublicId(), $data['from_account_id']);
        self::assertSame($bob->getPublicId(), $data['to_account_id']);
        self::assertArrayHasKey('transfer_id', $data);
        self::assertArrayHasKey('created_at', $data);

        $this->em->clear();
        $aliceUpdated = $this->em->find(Account::class, $alice->getId());
        $bobUpdated   = $this->em->find(Account::class, $bob->getId());

        self::assertSame(89950, $aliceUpdated->getBalanceCents());
        self::assertSame(60050, $bobUpdated->getBalanceCents());
    }

    public function testIdempotencyReplay(): void
    {
        $alice = $this->createAccount('Alice', 100000);
        $bob   = $this->createAccount('Bob', 50000);

        $payload = json_encode([
            'idempotency_key' => 'idem-replay-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '50.00',
            'currency'        => 'USD',
        ]);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $second = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame($first['transfer_id'], $second['transfer_id']);

        $this->em->clear();
        $aliceUpdated = $this->em->find(Account::class, $alice->getId());
        self::assertSame(95000, $aliceUpdated->getBalanceCents());
    }

    public function testFrozenAccountReturns422(): void
    {
        $alice = $this->createAccount('Alice', 100000, 'USD', AccountStatus::Frozen);
        $bob   = $this->createAccount('Bob', 50000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'frozen-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '10.00',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ACCOUNT_FROZEN', $data['error']['code']);
    }

    public function testSelfTransferReturns409(): void
    {
        $alice = $this->createAccount('Alice', 100000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'self-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $alice->getPublicId(),
            'amount'          => '10.00',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('SAME_ACCOUNT_TRANSFER', $data['error']['code']);
    }

    public function testCurrencyMismatchReturns422(): void
    {
        $alice = $this->createAccount('Alice', 100000, 'USD');
        $bob   = $this->createAccount('Bob', 50000, 'EUR');

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'currency-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '10.00',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('CURRENCY_MISMATCH', $data['error']['code']);
    }

    public function testInsufficientFundsReturns409(): void
    {
        $alice = $this->createAccount('Alice', 1000);
        $bob   = $this->createAccount('Bob', 50000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'insufficient-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '100.00',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('INSUFFICIENT_FUNDS', $data['error']['code']);
    }

    public function testAccountNotFoundReturns404(): void
    {
        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'not-found-001',
            'from_account_id' => '01HXXXXXXXXXXXXXXXXXXXXXXX',
            'to_account_id'   => '01HXXXXXXXXXXXXXXXXXXXXXXY',
            'amount'          => '10.00',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ACCOUNT_NOT_FOUND', $data['error']['code']);
    }

    public function testGetTransferReturns200(): void
    {
        $alice = $this->createAccount('Alice', 100000);
        $bob   = $this->createAccount('Bob', 50000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'get-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => '25.00',
            'currency'        => 'USD',
        ]));

        $created    = json_decode($this->client->getResponse()->getContent(), true);
        $transferId = $created['transfer_id'];

        $this->client->request('GET', '/api/v1/transfers/' . $transferId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($transferId, $data['transfer_id']);
        self::assertSame('completed', $data['status']);
        self::assertSame('25.00', $data['amount']);
    }

    public function testGetNonExistentTransferReturns404(): void
    {
        $this->client->request('GET', '/api/v1/transfers/01HXXXXXXXXXXXXXXXXXXXXXXX');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('TRANSFER_NOT_FOUND', $data['error']['code']);
    }

    public function testMissingFieldsReturns400(): void
    {
        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['amount' => '10.00']));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_ERROR', $data['error']['code']);
    }

    public function testInvalidAmountFormatReturns400(): void
    {
        $alice = $this->createAccount('Alice', 100000);
        $bob   = $this->createAccount('Bob', 50000);

        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'idempotency_key' => 'invalid-amount-001',
            'from_account_id' => $alice->getPublicId(),
            'to_account_id'   => $bob->getPublicId(),
            'amount'          => 'not-a-number',
            'currency'        => 'USD',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_ERROR', $data['error']['code']);
    }
}
