<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Segara\Config;
use Segara\View\Links;
use Segara\View\Metadata;
use Segara\View\Renderer;

final class RendererTest extends TestCase
{
    public function testNativeTemplateUsesOutputBufferingAndEscapedPlainText(): void
    {
        $config = $this->config();
        $renderer = new Renderer($config);
        $level = ob_get_level();

        $html = $renderer->render('admin', 'login', [
            'csrfToken' => 'token',
            'formAction' => '/segara/admin.php?action=login',
            'error' => '<img src=x onerror=alert(1)> & bad',
        ]);

        self::assertSame($level, ob_get_level());
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; bad', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }

    public function testSharedHeadSuppliesRequiredDocumentMetadata(): void
    {
        $config = $this->config();
        $head = (new Renderer($config))->head(Metadata::fromArray($config, [
            'title' => 'Page title',
            'site_name' => 'Site name',
            'language' => 'ms',
            'path' => '/page',
        ]));

        self::assertStringContainsString('<meta charset="utf-8">', $head);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $head);
        self::assertStringContainsString('<meta name="DC.language" content="ms">', $head);
        self::assertSame('ms', Metadata::fromArray($config, [
            'title' => 'Page title',
            'language' => 'ms',
        ])->language());
    }

    #[DataProvider('invalidTemplates')]
    public function testTemplateSelectionIsAFixedAllowlist(string $theme, string $template): void
    {
        $renderer = new Renderer($this->config());
        $level = ob_get_level();

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('template');
            $renderer->render($theme, $template);
        } finally {
            self::assertSame($level, ob_get_level());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTemplates(): iterable
    {
        yield 'theme traversal' => ['../admin', 'login'];
        yield 'template traversal' => ['admin', '../admin/login'];
        yield 'absolute template' => ['admin', '/etc/passwd'];
        yield 'unknown theme' => ['attacker', 'login'];
        yield 'unknown template' => ['admin', 'secret'];
        yield 'shared head is not a body template' => ['shared', 'missing'];
    }

    public function testLinksUseOnlyConfiguredOriginAndBasePath(): void
    {
        $_SERVER['HTTP_HOST'] = 'attacker.invalid';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'also-attacker.invalid';
        $links = new Links($this->config());

        try {
            self::assertSame('/segara/guide', $links->path('/guide'));
            self::assertSame('https://example.test/segara/guide', $links->url('/guide'));
            self::assertSame('https://example.test/segara/', $links->url());
        } finally {
            unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);
        }
    }

    #[DataProvider('invalidPaths')]
    public function testLinksRejectExternalAndTraversalPaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Links($this->config()))->url($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'absolute URL' => ['https://attacker.invalid/'];
        yield 'scheme relative' => ['//attacker.invalid/'];
        yield 'parent traversal' => ['/../secret'];
        yield 'encoded traversal' => ['/%2e%2e/secret'];
        yield 'backslash' => ['/..\\secret'];
        yield 'query' => ['/page?canonical=https://attacker.invalid'];
        yield 'fragment' => ['/page#attacker'];
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
