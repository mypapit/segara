<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Segara\Support\Slug;

final class SlugTest extends TestCase
{
    #[DataProvider('slugExamples')]
    public function testSlugGenerationIsDeterministicAscii(string $title, string $expected): void
    {
        self::assertSame($expected, Slug::fromTitle($title));
    }

    /** @return iterable<string, array{string, string}> */
    public static function slugExamples(): iterable
    {
        yield 'case and whitespace' => ['  Hello,   World!  ', 'hello-world'];
        yield 'accented Latin' => ['Café Déjà Vu', 'cafe-deja-vu'];
        yield 'Cyrillic' => ['Привет мир', 'privet-mir'];
        yield 'punctuation' => ['One & two / three', 'one-two-three'];
        yield 'non Latin fallback' => ['💡 💡', 'page'];
    }

    public function testSlugIsLimitedToOneHundredTwentyCharacters(): void
    {
        $slug = Slug::fromTitle(str_repeat('a', 140));

        self::assertSame(120, strlen($slug));
        self::assertSame(str_repeat('a', 120), $slug);
        self::assertSame('a-' . str_repeat('b', 118), Slug::fromTitle('a-' . str_repeat('b', 130)));
    }

    #[DataProvider('reservedRoutes')]
    public function testReservedRoutesAreRecognized(string $route): void
    {
        self::assertTrue(Slug::isReserved($route));
        self::assertFalse(Slug::isReserved($route . '-page'));
    }

    /** @return iterable<string, array{string}> */
    public static function reservedRoutes(): iterable
    {
        foreach (['admin', 'admin.php', 'media', 'assets', 'rss.xml', 'sitemap.xml', 'robots.txt', 'llms.txt', 'documents', 'videos'] as $route) {
            yield $route => [$route];
        }
    }
}
