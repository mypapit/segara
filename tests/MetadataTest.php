<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Segara\Config;
use Segara\View\Metadata;
use Segara\View\Renderer;

final class MetadataTest extends TestCase
{
    public function testHtmlHeadKeepsVisibleMetadataAndJsonLdSynchronized(): void
    {
        $config = $this->config();
        $metadata = Metadata::fromArray($config, [
            'title' => 'A <safe> & useful title',
            'site_name' => 'Example & Co',
            'language' => 'en-MY',
            'description' => 'A description with "quotes" & <markup>.',
            'path' => '/guide',
            'schema_type' => 'Article',
            'published_at' => '2026-10-25T01:02:03+00:00',
            'modified_at' => '2026-10-25T04:05:06+00:00',
            'author' => ['type' => 'Person', 'name' => 'Ada <Lovelace>'],
            'breadcrumbs' => [
                ['name' => 'Home', 'path' => '/'],
                ['name' => 'Guide', 'path' => '/guide'],
            ],
        ]);

        $head = (new Renderer($config))->head($metadata);

        self::assertStringContainsString('<title>A &lt;safe&gt; &amp; useful title</title>', $head);
        self::assertStringContainsString('<meta name="description" content="A description with &quot;quotes&quot; &amp; &lt;markup&gt;.">', $head);
        self::assertStringContainsString('<link rel="canonical" href="https://example.test/segara/guide">', $head);
        self::assertStringContainsString('<meta property="og:title" content="A &lt;safe&gt; &amp; useful title">', $head);
        self::assertStringContainsString('<meta property="og:type" content="article">', $head);
        self::assertStringContainsString('<meta property="og:url" content="https://example.test/segara/guide">', $head);
        self::assertStringContainsString('<meta property="og:site_name" content="Example &amp; Co">', $head);
        self::assertStringContainsString('<meta name="DC.title" content="A &lt;safe&gt; &amp; useful title">', $head);
        self::assertStringContainsString('<meta name="DC.creator" content="Ada &lt;Lovelace&gt;">', $head);
        self::assertStringContainsString('<meta name="DC.date" content="2026-10-25T01:02:03+00:00">', $head);
        self::assertStringContainsString('<meta name="DC.modified" content="2026-10-25T04:05:06+00:00">', $head);

        $graph = $this->decodeGraph($head);
        self::assertCount(3, $graph);
        self::assertSame('WebSite', $graph[0]['@type']);
        self::assertSame('Example & Co', $graph[0]['name']);
        self::assertSame('https://example.test/segara/', $graph[0]['url']);
        self::assertSame('Article', $graph[1]['@type']);
        self::assertSame('A <safe> & useful title', $graph[1]['headline']);
        self::assertSame('A description with "quotes" & <markup>.', $graph[1]['description']);
        $articleAuthor = $graph[1]['author'];
        self::assertIsArray($articleAuthor);
        self::assertSame('Person', $articleAuthor['@type']);
        self::assertSame('Ada <Lovelace>', $articleAuthor['name']);
        self::assertSame('BreadcrumbList', $graph[2]['@type']);
        $breadcrumbItems = $graph[2]['itemListElement'];
        self::assertIsArray($breadcrumbItems);
        self::assertIsArray($breadcrumbItems[1]);
        self::assertSame('Guide', $breadcrumbItems[1]['name']);
        self::assertSame('https://example.test/segara/guide', $breadcrumbItems[1]['item']);
    }

    public function testScriptBreakoutIsHexEncodedAndJsonRemainsValid(): void
    {
        $config = $this->config();
        $title = '</script><script>alert("XSS")</script>';
        $head = (new Renderer($config))->head(Metadata::fromArray($config, [
            'title' => $title,
            'site_name' => 'Example',
            'path' => '/',
        ]));

        self::assertStringNotContainsString('</script><script>', $head);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $head);
        self::assertSame($title, $this->decodeGraph($head)[1]['name']);
    }

    public function testOrganizationAuthorAndOptionalDescriptionAreSupported(): void
    {
        $config = $this->config();
        $head = (new Renderer($config))->head(Metadata::fromArray($config, [
            'title' => 'About',
            'site_name' => 'Example',
            'author' => ['type' => 'Organization', 'name' => 'Example Org'],
        ]));

        self::assertStringNotContainsString('name="description"', $head);
        $graph = $this->decodeGraph($head);
        self::assertSame('WebPage', $graph[1]['@type']);
        $organizationAuthor = $graph[1]['author'];
        self::assertIsArray($organizationAuthor);
        self::assertSame('Organization', $organizationAuthor['@type']);
        self::assertSame('Example Org', $organizationAuthor['name']);
        self::assertCount(2, $graph);
    }

    public function testAdminMetadataIsGenericAndCannotIncludePageSecrets(): void
    {
        $config = $this->config();
        $head = (new Renderer($config))->head(Metadata::admin($config));

        self::assertStringContainsString('<title>Administration</title>', $head);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $head);
        self::assertStringContainsString('<link rel="canonical" href="https://example.test/segara/admin.php">', $head);
        self::assertStringNotContainsString('draft', strtolower($head));
        self::assertStringNotContainsString('secret', strtolower($head));
        self::assertSame('WebPage', $this->decodeGraph($head)[1]['@type']);
    }

    public function testNonHtmlResponsesDoNotReceiveScriptMetadata(): void
    {
        $config = $this->config();
        $renderer = new Renderer($config);
        $metadata = Metadata::admin($config);

        self::assertSame('', $renderer->head($metadata, 'xml'));
        self::assertSame('', $renderer->head($metadata, 'text'));
        self::assertSame('', $renderer->head($metadata, 'plain'));
    }

    public function testUnsupportedSchemaAndAuthorTypesAreRejected(): void
    {
        $config = $this->config();
        $this->expectException(InvalidArgumentException::class);
        Metadata::fromArray($config, [
            'title' => 'Bad',
            'schema_type' => 'Script',
        ]);
    }

    public function testExternalCanonicalAndImageUrlsAreRejected(): void
    {
        $config = $this->config();
        $this->expectException(InvalidArgumentException::class);
        Metadata::fromArray($config, [
            'title' => 'Bad canonical',
            'path' => 'https://attacker.invalid/',
        ]);
    }

    /** @return list<array> */
    private function decodeGraph(string $head): array
    {
        $matched = preg_match(
            '~<script type="application/ld\+json">(.*?)</script>~s',
            $head,
            $matches,
        );
        self::assertSame(1, $matched);
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertIsArray($decoded['@graph']);

        return array_values(array_map(
            static fn (mixed $entry): array => is_array($entry)
                ? $entry
                : throw new \RuntimeException('JSON-LD graph entry must be an object.'),
            $decoded['@graph'],
        ));
    }

    private function config(): Config
    {
        return Config::fromArray([
            'base_url' => 'https://example.test',
            'base_path' => '/segara',
            'environment' => 'test',
        ]);
    }
}
