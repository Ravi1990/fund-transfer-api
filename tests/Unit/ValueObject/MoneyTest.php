<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFromDecimalStringStandardAmount(): void
    {
        $money = Money::fromDecimalString('100.50', 'USD');
        self::assertSame(10050, $money->cents);
        self::assertSame('USD', $money->currency);
    }

    public function testFromDecimalStringWholeNumber(): void
    {
        $money = Money::fromDecimalString('100', 'USD');
        self::assertSame(10000, $money->cents);
    }

    public function testFromDecimalStringSingleDecimalPlace(): void
    {
        $money = Money::fromDecimalString('100.5', 'USD');
        self::assertSame(10050, $money->cents);
    }

    public function testFromDecimalStringSmallAmount(): void
    {
        $money = Money::fromDecimalString('0.01', 'USD');
        self::assertSame(1, $money->cents);
    }

    public function testFromDecimalStringLargeAmount(): void
    {
        $money = Money::fromDecimalString('999999.99', 'USD');
        self::assertSame(99999999, $money->cents);
    }

    public function testFromDecimalStringNormalizesUppercaseCurrency(): void
    {
        $money = Money::fromDecimalString('10.00', 'usd');
        self::assertSame('USD', $money->currency);
    }

    public function testFromDecimalStringRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString('abc', 'USD');
    }

    public function testFromDecimalStringRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString('-10.00', 'USD');
    }

    public function testFromDecimalStringRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString('0.00', 'USD');
    }

    public function testFromDecimalStringRejectsTooManyDecimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString('10.999', 'USD');
    }

    public function testToDecimalStringStandard(): void
    {
        $money = Money::fromDecimalString('100.50', 'USD');
        self::assertSame('100.50', $money->toDecimalString());
    }

    public function testToDecimalStringAlwaysTwoDecimalPlaces(): void
    {
        $money = Money::fromDecimalString('100', 'USD');
        self::assertSame('100.00', $money->toDecimalString());
    }

    public function testSubtractReturnsCorrectAmount(): void
    {
        $a      = Money::fromDecimalString('100.00', 'USD');
        $b      = Money::fromDecimalString('40.50', 'USD');
        $result = $a->subtract($b);

        self::assertSame(5950, $result->cents);
        self::assertSame('59.50', $result->toDecimalString());
    }

    public function testSubtractIsImmutable(): void
    {
        $a      = Money::fromDecimalString('100.00', 'USD');
        $b      = Money::fromDecimalString('40.00', 'USD');
        $result = $a->subtract($b);

        self::assertSame(10000, $a->cents);
        self::assertSame(6000, $result->cents);
    }

    public function testIsLessThanReturnsTrueWhenSmaller(): void
    {
        $a = Money::fromDecimalString('50.00', 'USD');
        $b = Money::fromDecimalString('100.00', 'USD');
        self::assertTrue($a->isLessThan($b));
    }

    public function testIsLessThanReturnsFalseWhenEqual(): void
    {
        $a = Money::fromDecimalString('100.00', 'USD');
        $b = Money::fromDecimalString('100.00', 'USD');
        self::assertFalse($a->isLessThan($b));
    }

    public function testIsLessThanReturnsFalseWhenGreater(): void
    {
        $a = Money::fromDecimalString('200.00', 'USD');
        $b = Money::fromDecimalString('100.00', 'USD');
        self::assertFalse($a->isLessThan($b));
    }

    public function testIsSameCurrencyTrue(): void
    {
        $a = Money::fromDecimalString('100.00', 'USD');
        $b = Money::fromDecimalString('50.00', 'USD');
        self::assertTrue($a->isSameCurrency($b));
    }

    public function testIsSameCurrencyFalse(): void
    {
        $a = Money::fromDecimalString('100.00', 'USD');
        $b = Money::fromDecimalString('50.00', 'EUR');
        self::assertFalse($a->isSameCurrency($b));
    }
}
