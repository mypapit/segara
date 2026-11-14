<?php

declare(strict_types=1);

namespace Tests;

use Segara\Config;
use Segara\View\Renderer;
use Tests\Support\TestCase;

/**
 * The site tagline is a footer line above the copyright in every theme except
 * Slate, whose band already carries it. These tests pin the markup, the order,
 * the escaping, the Slate exclusion and the stylesheet rule.
 */
final class FooterTaglineTest extends TestCase
{
    /** @var list<string> */
    private const FOOTER_THEMES = ['clarity', 'aubergine', 'editorial', 'folio', 'scholar'];

    public function testTheTaglineSitsAboveTheCopyrightInEveryFooterTheme(): void
    {
        foreach (self::FOOTER_THEMES as $theme) {
            $html = $this->render($theme, ['site_tagline' => 'Notes on teaching and research']);

            self::assertStringContainsString('class="site-footer__tagline"', $html, $theme . ' renders the tagline');
            self::assertStringContainsString('Notes on teaching and research', $html, $theme . ' renders the stored text');

            $tagline = strpos($html, 'class="site-footer__tagline"');
            $copyright = strpos($html, 'class="site-footer__copyright"');
            self::assertIsInt($tagline);
            self::assertIsInt($copyright);
            self::assertLessThan($copyright, $tagline, $theme . ' puts the tagline before the copyright');
        }
    }

    public function testTheTaglineIsEscaped(): void
    {
        $html = $this->render('clarity', ['site_tagline' => '<b>bold</b> & co']);

        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; co', $html);
        self::assertStringNotContainsString('<b>bold</b>', $html);
    }

    public function testSlateKeepsItsTaglineInTheBandAndOutOfTheFooter(): void
    {
        $home = $this->render('slate', ['site_tagline' => 'Notes on teaching and research'], 'home');
        self::assertStringContainsString('class="jumbotron__tagline"', $home, 'slate shows it in the band');
        self::assertStringNotContainsString('site-footer__tagline', $home, 'and not again in the footer');

        $page = $this->render('slate', ['site_tagline' => 'Notes on teaching and research']);
        self::assertStringNotContainsString('site-footer__tagline', $page, 'not on a content page either');
    }

    public function testAnEmptyTaglineRendersNoLine(): void
    {
        foreach ([...self::FOOTER_THEMES, 'slate'] as $theme) {
            $html = $this->render($theme, ['site_tagline' => '']);
            self::assertStringNotContainsString('site-footer__tagline', $html, $theme . ' renders nothing when empty');
        }

        // Whitespace only is empty too: no empty paragraph in the footer.
        $blank = $this->render('clarity', ['site_tagline' => '   ']);
        self::assertStringNotContainsString('site-footer__tagline', $blank);
    }

    public function testEachFooterThemeStylesTheLineFromTheFooterInkToken(): void
    {
        foreach (self::FOOTER_THEMES as $theme) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');

            self::assertMatchesRegularExpression(
                '/\.site-footer__tagline\s*\{[^}]*color:\s*var\(--footer-ink\)/s',
                $css,
                $theme . ' colours the tagline with the footer ink token'
            );
            self::assertMatchesRegularExpression(
                '/\.site-footer__tagline\s*\{[^}]*max-width:\s*72rem/s',
                $css,
                $theme . ' aligns the tagline with the colophon'
            );
        }

        $slate = (string) file_get_contents(dirname(__DIR__) . '/themes/slate/assets/theme.css');
        self::assertStringNotContainsString('.site-footer__tagline', $slate, 'slate never renders it, so it carries no rule');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function render(string $theme, array $extra = [], string $template = 'page'): string
    {
        return (new Renderer($this->config()))->render($theme, $template, array_merge([
            'title' => 'Shared Title',
            'body' => '<p>Shared body</p>',
            'challenge' => false,
            'csrfToken' => '',
            'path' => '/about',
            'site_name' => 'Example',
            'pages' => [],
        ], $extra));
    }

    private function config(): Config
    {
        return Config::fromArray([
            'base_url' => 'https://segara.test',
            'base_path' => '',
            'data_path' => $this->sqlitePath,
            'session_path' => sys_get_temp_dir(),
            'uploads_path' => sys_get_temp_dir(),
        ]);
    }
}
