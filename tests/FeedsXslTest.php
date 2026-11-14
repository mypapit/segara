<?php

declare(strict_types=1);

namespace Tests;

use DOMDocument;
use DOMProcessingInstruction;
use XSLTProcessor;
use InvalidArgumentException;
use PDO;
use Segara\Config;
use Segara\Feeds\RssController;
use Segara\Feeds\Xml;
use Segara\Maintenance\PublicFiles;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

/**
 * A bare XML file in a browser is a wall of angle brackets. Both public documents
 * declare a stylesheet, and the stylesheet has to exist, be real XSLT 1.0, and
 * load its CSS from a file rather than inlining a <style> block that a future
 * Content-Security-Policy would block.
 */
final class FeedsXslTest extends TestCase
{
    private const PUBLIC_ROOT = __DIR__ . '/../public';

    private RssController $rss;
    private PublicFiles $files;
    private string $writeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connect($this->sqlitePath);
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $pages = new PageRepository($pdo);
        $config = Config::fromArray([
            'base_url' => 'https://example.test',
            'base_path' => '',
            'environment' => 'test',
        ]);
        $this->rss = new RssController($config, $pages, new MediaRepository($pdo));
        $this->writeRoot = sys_get_temp_dir() . '/segara-xsl-' . bin2hex(random_bytes(8));
        mkdir($this->writeRoot, 0777, true);
        $this->files = new PublicFiles($config, $pages, $this->writeRoot);

        $pages->create([
            'title' => 'About',
            'slug' => 'about',
            'state' => 'published',
            'author_name' => 'Admin',
            'body_markdown' => '<h2>Heading</h2>',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->writeRoot . '/*') as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->writeRoot);
        parent::tearDown();
    }

    public function testTheSitemapDeclaresItsStylesheetBeforeTheRootElement(): void
    {
        $this->files->rebuild();
        $xml = (string) file_get_contents($this->writeRoot . '/sitemap.xml');

        self::assertSame('sitemap-styles.xml', $this->stylesheetHref($xml));
        self::assertLessThan(
            (int) strpos($xml, '<urlset'),
            (int) strpos($xml, '<?xml-stylesheet'),
            'the declaration must precede <urlset>, or browsers ignore it',
        );
        // The declaration must not upset a strict XML parser either.
        $doc = new DOMDocument();
        self::assertTrue(@$doc->loadXML($xml), 'sitemap.xml is not well-formed with the declaration');
        self::assertGreaterThanOrEqual(1, $doc->getElementsByTagName('url')->length, 'the sitemap still parses');
    }

    public function testTheFeedDeclaresItsStylesheetBeforeTheRootElement(): void
    {
        $xml = $this->rss->feed()->body();

        self::assertSame('rss-styles.xml', $this->stylesheetHref($xml));
        self::assertLessThan(
            (int) strpos($xml, '<rss'),
            (int) strpos($xml, '<?xml-stylesheet'),
            'the declaration must precede <rss>, or browsers ignore it',
        );
        $doc = new DOMDocument();
        self::assertTrue(@$doc->loadXML($xml), 'the feed is not well-formed with the declaration');
    }

    public function testBothStylesheetsExistAndAreXsltOne(): void
    {
        foreach (['sitemap-styles.xml', 'rss-styles.xml'] as $name) {
            $path = self::PUBLIC_ROOT . '/' . $name;
            self::assertFileExists($path);

            $doc = new DOMDocument();
            self::assertTrue(@$doc->load($path), $name . ' is not well-formed XML');

            $root = $doc->documentElement;
            self::assertNotNull($root);
            self::assertSame('stylesheet', $root->localName, $name . ' must be an XSLT stylesheet');
            self::assertSame('http://www.w3.org/1999/XSL/Transform', $root->namespaceURI);
            self::assertSame('1.0', $root->getAttribute('version'), 'browsers only ship an XSLT 1.0 engine');
            self::assertSame('html', $root->getAttribute('method') !== ''
                ? $root->getAttribute('method')
                : $this->outputMethod($doc), $name . ' must output HTML');
        }
    }

    public function testBothStylesheetsLoadTheSharedCssInsteadOfInliningIt(): void
    {
        self::assertFileExists(self::PUBLIC_ROOT . '/assets/xml.css');

        foreach (['sitemap-styles.xml', 'rss-styles.xml'] as $name) {
            $xsl = (string) file_get_contents(self::PUBLIC_ROOT . '/' . $name);

            self::assertStringContainsString('href="assets/xml.css"', $xsl, $name . ' must link the shared CSS');
            self::assertStringNotContainsString('<style', $xsl, $name . ' must not inline a style block');
            self::assertStringNotContainsString('style=', $xsl, $name . ' must not use style attributes');
        }
    }

    public function testEveryDeclaredStylesheetIsShippedInTheDocumentRoot(): void
    {
        $this->files->rebuild();
        $sources = [
            'sitemap' => (string) file_get_contents($this->writeRoot . '/sitemap.xml'),
            'feed' => $this->rss->feed()->body(),
        ];

        foreach ($sources as $label => $xml) {
            $href = $this->stylesheetHref($xml);
            self::assertFileExists(
                self::PUBLIC_ROOT . '/' . $href,
                $label . ' points at ' . $href . ', which is not in the document root',
            );
            // Stock nginx and Apache serve .xml as text/xml. A .xsl suffix is not in
            // either default mime map, so the browser would refuse it as a stylesheet.
            self::assertStringEndsWith('.xml', $href, $label . ' must use a mapped extension');
        }
    }

    /**
     * The decisive test: run the stylesheet the way a browser would. Chromium 138
     * and later no longer ship an XSLT engine, so a passing browser check is not
     * available on this box - the transform is proven here instead.
     */
    public function testTheStylesheetsTurnBothDocumentsIntoAList(): void
    {
        $this->files->rebuild();
        $sitemap = $this->transform(
            (string) file_get_contents($this->writeRoot . '/sitemap.xml'),
            'sitemap-styles.xml',
        );

        self::assertStringContainsString('<html', $sitemap);
        self::assertStringContainsString('class="doc-row"', $sitemap);
        self::assertStringContainsString('https://example.test/about', $sitemap);
        self::assertStringContainsString('href="assets/xml.css"', $sitemap);

        $feed = $this->transform($this->rss->feed()->body(), 'rss-styles.xml');

        self::assertStringContainsString('<html', $feed);
        self::assertStringContainsString('class="doc-row"', $feed);
        self::assertStringContainsString('About', $feed);
        self::assertStringContainsString('href="assets/xml.css"', $feed);
        // The feed's description is rendered markup. Printing it would either show
        // raw angle brackets or inject them, so the stylesheet leaves it out.
        self::assertStringNotContainsString('&lt;h2&gt;', $feed, 'no escaped markup in the styled view');
        self::assertStringNotContainsString('<h2>Heading</h2>', $feed, 'no injected markup in the styled view');
    }

    public function testTheWriterRefusesAnHrefThatCouldBreakTheDeclaration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Xml())->stylesheet('evil" ?><broken');
    }

    private function transform(string $xml, string $stylesheet): string
    {
        $source = new DOMDocument();
        self::assertTrue(@$source->loadXML($xml), 'source document is not well-formed');

        $xsl = new DOMDocument();
        self::assertTrue(@$xsl->load(self::PUBLIC_ROOT . '/' . $stylesheet), 'stylesheet did not parse');

        $processor = new XSLTProcessor();
        $processor->importStylesheet($xsl);
        $result = $processor->transformToXml($source);
        self::assertIsString($result, 'the transform failed');

        return $result;
    }

    private function stylesheetHref(string $xml): string
    {
        $doc = new DOMDocument();
        self::assertTrue(@$doc->loadXML($xml));

        foreach ($doc->childNodes as $node) {
            if ($node instanceof DOMProcessingInstruction && $node->target === 'xml-stylesheet') {
                if (preg_match('/href="([^"]+)"/', $node->data, $match) !== 1) {
                    self::fail('the declaration has no href');
                }

                return $match[1];
            }
        }

        self::fail('No xml-stylesheet declaration was found.');
    }

    private function outputMethod(DOMDocument $doc): string
    {
        foreach ($doc->getElementsByTagNameNS('http://www.w3.org/1999/XSL/Transform', 'output') as $node) {
            return $node->getAttribute('method');
        }

        return '';
    }
}
