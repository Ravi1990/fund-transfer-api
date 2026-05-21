<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Domain\Enum\TransferStatus;
use App\Domain\Exception\InvalidTransferStateException;
use PHPUnit\Framework\TestCase;

final class TransferStatusTest extends TestCase
{
    public function testPendingCanTransitionToProcessing(): void
    {
        $this->expectNotToPerformAssertions();
        TransferStatus::Pending->transitionTo(TransferStatus::Processing);
    }

    public function testProcessingCanTransitionToCompleted(): void
    {
        $this->expectNotToPerformAssertions();
        TransferStatus::Processing->transitionTo(TransferStatus::Completed);
    }

    public function testProcessingCanTransitionToFailed(): void
    {
        $this->expectNotToPerformAssertions();
        TransferStatus::Processing->transitionTo(TransferStatus::Failed);
    }

    public function testPendingCannotTransitionToCompleted(): void
    {
        $this->expectException(InvalidTransferStateException::class);
        TransferStatus::Pending->transitionTo(TransferStatus::Completed);
    }

    public function testPendingCanTransitionToFailedDirectly(): void
    {
        $this->expectNotToPerformAssertions();
        TransferStatus::Pending->transitionTo(TransferStatus::Failed);
    }

    public function testCompletedCannotTransitionToFailed(): void
    {
        $this->expectException(InvalidTransferStateException::class);
        TransferStatus::Completed->transitionTo(TransferStatus::Failed);
    }

    public function testFailedCannotTransitionToCompleted(): void
    {
        $this->expectException(InvalidTransferStateException::class);
        TransferStatus::Failed->transitionTo(TransferStatus::Completed);
    }

    public function testCompletedIsTerminal(): void
    {
        self::assertTrue(TransferStatus::Completed->isTerminal());
    }

    public function testFailedIsTerminal(): void
    {
        self::assertTrue(TransferStatus::Failed->isTerminal());
    }

    public function testPendingIsNotTerminal(): void
    {
        self::assertFalse(TransferStatus::Pending->isTerminal());
    }

    public function testProcessingIsNotTerminal(): void
    {
        self::assertFalse(TransferStatus::Processing->isTerminal());
    }
}
