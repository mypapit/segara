<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Segara\Support\Formatter;

final class FormatterTest extends TestCase
{
    public function testMoneyFormatsCurrencyWithGrouping(): void
    {
        $formatter = new Formatter();
        self::assertStringContainsString('1,234', $formatter->money(1234.5, 'USD', 'en'));
        self::assertStringContainsString('1,234', $formatter->money(1234, 'MYR', 'ms_MY'));
    }

    public function testNumberFormatsWithGrouping(): void
    {
        self::assertStringContainsString('1,234,567', (new Formatter())->number(1234567.891, 'en'));
    }

    public function testDateConvertsUtcInstantIntoTargetTimezone(): void
    {
        $formatter = new Formatter();
        self::assertStringContainsString('3:04', $formatter->date('2026-01-02T03:04:05Z', 'UTC', 'en'));
        self::assertStringContainsString('11:04', $formatter->date('2026-01-02T03:04:05Z', 'Asia/Kuala_Lumpur', 'en'));
    }

    public function testFuzzyDatesAreRelativeAndDeterministic(): void
    {
        $now = strtotime('2026-10-25T12:00:00Z');
        $formatter = new Formatter(static fn(): int => $now);

        self::assertSame('just now', $formatter->date('2026-10-25T11:59:30Z', 'UTC', 'en', true));
        self::assertSame('10 minutes ago', $formatter->date('2026-10-25T11:50:00Z', 'UTC', 'en', true));
        self::assertSame('1 hour ago', $formatter->date('2026-10-25T11:00:00Z', 'UTC', 'en', true));
        self::assertSame('2 days ago', $formatter->date('2026-10-23T12:00:00Z', 'UTC', 'en', true));
        self::assertSame('3 weeks ago', $formatter->date('2026-10-04T12:00:00Z', 'UTC', 'en', true));
    }

    public function testInvalidInputsAreRejected(): void
    {
        $formatter = new Formatter();
        $rejected = 0;
        foreach ([
            static fn () => $formatter->money('abc', 'USD', 'en'),
            static fn () => $formatter->date('not-a-date', 'UTC', 'en'),
            static fn () => $formatter->date('2026-01-01T00:00:00Z', 'Mars/Nowhere', 'en'),
            static fn () => $formatter->money(10, 'US', 'en'),
        ] as $invoke) {
            try {
                $invoke();
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected);
    }
}
