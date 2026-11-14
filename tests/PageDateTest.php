<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Segara\Content\PageDate;
use Segara\Support\Formatter;

/**
 * The four display modes, the patterns behind them, and the pattern guard that
 * keeps a typo in Settings from printing nonsense at the foot of every page.
 */
final class PageDateTest extends TestCase
{
    private const PUBLISHED = '2026-10-25T01:17:38Z';

    public function testPatternFormatsInTheSiteTimezone(): void
    {
        $formatter = new Formatter();

        self::assertSame('October 25, 2026 (Sunday)', $formatter->pattern(self::PUBLISHED, 'UTC', 'F j, Y (l)'));
        self::assertSame('October 25, 2026 (Sunday)', $formatter->pattern(self::PUBLISHED, 'Asia/Kuala_Lumpur', 'F j, Y (l)'));
        // 18:30Z is already the next day in Kuala Lumpur.
        self::assertSame('October 26, 2026 (Monday)', $formatter->pattern('2026-10-25T18:30:00Z', 'Asia/Kuala_Lumpur', 'F j, Y (l)'));
    }

    public function testIsoDateIsTheDatePartInTheSiteTimezone(): void
    {
        $formatter = new Formatter();

        self::assertSame('2026-10-25', $formatter->isoDate(self::PUBLISHED, 'UTC'));
        self::assertSame('2026-10-26', $formatter->isoDate('2026-10-25T18:30:00Z', 'Asia/Kuala_Lumpur'));
    }

    public function testTheFourModesProduceTheAgreedStrings(): void
    {
        $now = strtotime('2026-10-27T12:00:00Z');
        $dates = new PageDate(new Formatter(static fn (): int => $now));

        self::assertSame(
            'October 25, 2026 (Sunday)',
            $dates->format(self::PUBLISHED, 'full', 'j F Y', 'UTC')['text'],
        );
        self::assertSame(
            'October 25, 2026',
            $dates->format(self::PUBLISHED, 'plain', 'j F Y', 'UTC')['text'],
        );
        self::assertSame(
            '25 October 2026',
            $dates->format(self::PUBLISHED, 'custom', 'j F Y', 'UTC')['text'],
        );
        self::assertSame(
            '2 days ago',
            $dates->format(self::PUBLISHED, 'relative', 'j F Y', 'UTC')['text'],
        );

        // The machine-readable value never becomes the relative phrase.
        self::assertSame('2026-10-25', $dates->format(self::PUBLISHED, 'relative', 'j F Y', 'UTC')['iso']);
    }

    public function testAnInvalidOrUnknownPatternFallsBackToTheDefault(): void
    {
        $dates = new PageDate(new Formatter());

        // "hello" has no date token, so custom mode falls back rather than printing it.
        self::assertSame('October 25, 2026 (Sunday)', $dates->format(self::PUBLISHED, 'custom', 'hello', 'UTC')['text']);
        // An unknown mode is treated as the default.
        self::assertSame('October 25, 2026 (Sunday)', $dates->format(self::PUBLISHED, 'nonsense', 'j F Y', 'UTC')['text']);
    }

    public function testThePatternGuardAcceptsDatesAndRejectsJunk(): void
    {
        self::assertTrue(PageDate::isValidPattern('F j, Y (l)'));
        self::assertTrue(PageDate::isValidPattern('j F Y'));
        self::assertTrue(PageDate::isValidPattern('d/m/Y'));
        self::assertFalse(PageDate::isValidPattern(''));
        self::assertFalse(PageDate::isValidPattern('   '));
        // 'hello' contains an 'l', which is a weekday token: date('hello') prints
        // "01ZSundaySunday2026", so the guard must not be fooled by it.
        self::assertFalse(PageDate::isValidPattern('hello'));
        self::assertFalse(PageDate::isValidPattern('<script>Y</script>'));
        self::assertFalse(PageDate::isValidPattern(str_repeat('Y', 65)));
        self::assertSame(['full', 'plain', 'custom', 'relative'], PageDate::MODES);
        self::assertSame('F j, Y (l)', PageDate::DEFAULT_PATTERN);
    }
}
