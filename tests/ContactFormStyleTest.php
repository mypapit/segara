<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The contact form is one shared partial rendered into every theme, so it has to
 * be styled by every theme. Before this test existed the partial shipped with
 * zero CSS: the submit button was a bare browser button, the fields had no
 * rhythm, and the honeypot field was visible to real visitors.
 */
final class ContactFormStyleTest extends TestCase
{
    private const THEMES = ['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'];

    public function testEveryThemeDressesTheFormAsACard(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression(
                '/\.contact-form\s*\{[^}]*padding:[^;]+;[^}]*border:\s*1px solid var\(--border\)/s',
                $css,
                $theme . ' gives the form a framed card'
            );
            self::assertMatchesRegularExpression('/\.contact-form\s*\{[^}]*border-radius:\s*var\(--radius\)/s', $css, $theme . ' rounds the card');
            self::assertMatchesRegularExpression('/\.contact-form\s*\{[^}]*background:\s*var\(--canvas\)/s', $css, $theme . ' sits the card on the canvas');
        }
    }

    public function testEveryThemeLaysTheFieldsOutOnATwoColumnGridBelowTheBreakpoint(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression('/\.contact-form__grid\s*\{[^}]*display:\s*grid/s', $css, $theme . ' uses a grid');
            self::assertMatchesRegularExpression('/\.contact-form__grid\s*\{[^}]*grid-template-columns:\s*repeat\(2,/s', $css, $theme . ' puts name and email side by side');
            self::assertMatchesRegularExpression('/\.contact-form__field--full[^{]*\{[^}]*grid-column:\s*1\s*\/\s*-1/s', $css, $theme . ' lets subject and message span the row');
            self::assertMatchesRegularExpression('/@media \(max-width: 640px\)[\s\S]*?\.contact-form__grid[^{]*\{[^}]*grid-template-columns:\s*1fr/s', $css, $theme . ' collapses to one column on a phone');
        }
    }

    public function testEveryThemeStylesLabelsAndFields(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression('/\.contact-form label\s*\{[^}]*display:\s*block/s', $css, $theme . ' puts labels above fields');
            self::assertMatchesRegularExpression('/\.contact-form label\s*\{[^}]*font-weight:\s*600/s', $css, $theme . ' weights the labels');
            self::assertMatchesRegularExpression(
                '/\.contact-form input\[type="text"\],\s*\.contact-form input\[type="email"\],\s*\.contact-form textarea\s*\{[^}]*width:\s*100%/s',
                $css,
                $theme . ' makes the fields full width'
            );
            self::assertMatchesRegularExpression('/\.contact-form textarea\s*\{[^}]*resize:\s*vertical/s', $css, $theme . ' lets the message grow');
            self::assertMatchesRegularExpression('/\.contact-form input\[type="text"\]:focus/s', $css, $theme . ' styles the focus state');
        }
    }

    public function testTheSubmitButtonIsStyledNotABrowserDefault(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression('/\.contact-form button\s*\{[^}]*padding:\s*[^;]+;/s', $css, $theme . ' pads the button');
            self::assertMatchesRegularExpression('/\.contact-form button\s*\{[^}]*background:\s*var\(--(primary|secondary)\)/s', $css, $theme . ' fills the button');
            self::assertMatchesRegularExpression('/\.contact-form button\s*\{[^}]*border-radius:\s*var\(--radius\)/s', $css, $theme . ' rounds the button');
            self::assertMatchesRegularExpression('/\.contact-form button\s*\{[^}]*cursor:\s*pointer/s', $css, $theme . ' shows the button is clickable');
            self::assertMatchesRegularExpression('/\.contact-form button:hover/s', $css, $theme . ' gives the button a hover state');
        }
    }

    public function testEveryButtonLabelPassesContrastAgainstItsFill(): void
    {
        $expected = [
            // theme => [label colour, fill]
            'clarity' => ['var(--canvas)', 'var(--primary)'],
            'aubergine' => ['var(--canvas)', 'var(--primary)'],
            'editorial' => ['var(--canvas)', 'var(--primary)'],
            // DESIGN-4's terracotta fill with espresso ink, same as .button.
            'scholar' => ['#281809', 'var(--secondary)'],
        ];

        foreach ($expected as $theme => [$label, $fill]) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression(
                '/\.contact-form button\s*\{[^}]*color:\s*' . preg_quote($label, '/') . '/s',
                $css,
                $theme . ' sets the button label to ' . $label
            );

            $ratio = $this->contrast(
                $this->resolve($css, $fill),
                $this->resolve($css, $label)
            );
            self::assertGreaterThanOrEqual(4.5, $ratio, $theme . ' button label contrast');
        }
    }

    public function testTheHoneypotIsPresentInTheMarkupButInvisible(): void
    {
        $html = $this->render();
        self::assertStringContainsString('name="website"', $html, 'the honeypot field stays in the DOM');

        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);
            self::assertMatchesRegularExpression(
                '/\.contact-form__honeypot\s*\{[^}]*(clip-path:\s*inset\(50%\)|clip:\s*rect)/s',
                $css,
                $theme . ' keeps the honeypot off the screen'
            );
            self::assertMatchesRegularExpression(
                '/\.contact-form__honeypot\s*\{[^}]*width:\s*1px/s',
                $css,
                $theme . ' collapses the honeypot to a pixel'
            );
        }
    }

    public function testTheMarkupCarriesTheClassesTheStylesNeed(): void
    {
        $html = $this->render();

        foreach ([
            'class="contact-form"',
            'class="contact-form__intro"',
            'class="contact-form__grid"',
            'contact-form__field contact-form__field--half',
            'contact-form__field contact-form__field--full',
            'class="contact-form__actions"',
        ] as $needle) {
            self::assertStringContainsString($needle, $html, $needle . ' is rendered');
        }

        // Every visible control has a label bound to its id.
        preg_match_all('/<input id="([^"]+)"/', $html, $inputs);
        preg_match_all('/<textarea id="([^"]+)"/', $html, $areas);
        preg_match_all('/<label for="([^"]+)"/', $html, $labels);

        $controls = array_merge($inputs[1], $areas[1]);
        self::assertSame($controls, $labels[1], 'each field is labelled, in order');
        self::assertStringNotContainsString('style="', $html, 'no inline styles (CSP)');
    }

    public function testTheStatusMessagesUseBodyInkOnATintedGround(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression(
                '/\.contact-form__saved,\s*\.contact-form__error\s*\{[^}]*color:\s*var\(--on-surface\)/s',
                $css,
                $theme . ' keeps message text at body ink'
            );
            self::assertMatchesRegularExpression('/\.contact-form__saved\s*\{[^}]*background:\s*var\(--accent-soft\)/s', $css, $theme . ' tints the saved notice');
            self::assertMatchesRegularExpression('/\.contact-form__error\s*\{[^}]*background:\s*var\(--danger-soft\)/s', $css, $theme . ' tints the error notice');

            self::assertGreaterThanOrEqual(4.5, $this->contrast(
                $this->resolve($css, 'var(--accent-soft)'),
                $this->resolve($css, 'var(--on-surface)')
            ), $theme . ' saved notice contrast');

            self::assertGreaterThanOrEqual(4.5, $this->contrast(
                $this->resolve($css, 'var(--danger-soft)'),
                $this->resolve($css, 'var(--on-surface)')
            ), $theme . ' error notice contrast');
        }
    }

    private function render(): string
    {
        $csrfToken = 'token';
        $saved = null;
        $error = null;
        ob_start();
        require dirname(__DIR__) . '/themes/shared/contact-form.php';

        return (string) ob_get_clean();
    }

    private function css(string $theme): string
    {
        $css = file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
        self::assertIsString($css);

        return $css;
    }

    /** Resolve var(--x) to the hex token, or return a literal colour. */
    private function resolve(string $css, string $value): string
    {
        if (preg_match('/var\(--([a-z-]+)\)/', $value, $match) === 1) {
            $found = preg_match('/--' . preg_quote($match[1], '/') . ':\s*(#[0-9a-f]{6})/i', $css, $hex);
            self::assertSame(1, $found, '--' . $match[1] . ' is a hex token');

            return $hex[1];
        }

        return $value;
    }

    /** WCAG 2.1 relative luminance contrast, 1..21. */
    private function contrast(string $background, string $foreground): float
    {
        $luminance = static function (string $hex): float {
            $channels = [];
            foreach ([1, 3, 5] as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($background);
        $b = $luminance($foreground);
        $light = max($a, $b);
        $dark = min($a, $b);

        return ($light + 0.05) / ($dark + 0.05);
    }
}
