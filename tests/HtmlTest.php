<?php

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Segara\Support\Html;

final class HtmlTest extends TestCase
{
    public function testEscapeUsesUtf8QuotesAndSubstitution(): void
    {
        $input = "<article title=\"both ' quotes\">Tom & invalid\xB1</article>";

        self::assertSame(
            '&lt;article title=&quot;both &#039; quotes&quot;&gt;Tom &amp; invalid�&lt;/article&gt;',
            Html::escape($input),
        );
    }

    public function testJsonIsSafeForScriptAndJsonLdContexts(): void
    {
        $value = [
            '@type' => 'Article',
            'headline' => '</script><script>alert("XSS")</script>',
            'description' => "Tom & 'friends'",
        ];

        $json = Html::json($value);

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $json);
        self::assertStringContainsString('\\u0026', $json);
        self::assertStringContainsString('\\u0027', $json);
        self::assertStringContainsString('\\u0022XSS\\u0022', $json);
        self::assertSame($value, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonEncodingFailureThrows(): void
    {
        $this->expectException(JsonException::class);

        Html::json("\xB1\x31");
    }
}
