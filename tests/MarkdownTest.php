<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Segara\Content\Markdown;

final class MarkdownTest extends TestCase
{
    public function testRendersBasicMarkdownAndSanitizesUnsafeHtml(): void
    {
        $renderer = new Markdown();
        $html = $renderer->render("# Title\n\n**bold** *em* [link](https://example.test)\n\n- item\n\n`code`\n\n<script>alert(1)</script><img src=x onerror=alert(1)>");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<em>em</em>', $html);
        self::assertStringContainsString('href="https://example.test"', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('<img', strtolower($html));
    }

    public function testAllowsOnlyLocalMediaAndSafeLinks(): void
    {
        $html = (new Markdown())->render('[http](http://example.test) [bad](javascript:alert(1)) ![local](/media/a.png) ![remote](https://evil.test/a.png)\n\n<video src="/media/a.mp4" autoplay onload="x"></video>');

        self::assertStringContainsString('href="http://example.test"', $html);
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringContainsString('src="/media/a.png"', $html);
        self::assertStringNotContainsString('evil.test', $html);
    }

    public function testEscapesScriptClosingSequenceAndMalformedHtml(): void
    {
        $html = (new Markdown())->render('</script><iframe src="https://evil.test"></iframe><b>safe</b>');
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('<iframe', strtolower($html));
        self::assertStringNotContainsString('<b>', strtolower($html));
    }
}
