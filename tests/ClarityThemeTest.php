<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Segara\Config;
use Segara\View\Renderer;

/**
 * Pins the 2026 bolder pass on Clarity Canvas. The palette and the Arimo stack
 * are unchanged; what changed is that the index now has a heading, the first
 * entry leads it, and the depth the theme draws is its own blue rather than a
 * neutral grey.
 */
final class ClarityThemeTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/assets/theme.css');
    }

    private function token(string $css, string $name): string
    {
        if (preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $match) !== 1) {
            throw new RuntimeException($name . ' is not declared in theme.css');
        }

        return trim($match[1]);
    }

    /** @param array<string, mixed> $data */
    private function home(array $data = []): string
    {
        $config = Config::fromArray([
            'base_url' => 'https://segara.test',
            'base_path' => '',
            'data_path' => sys_get_temp_dir(),
            'session_path' => sys_get_temp_dir(),
            'uploads_path' => sys_get_temp_dir(),
        ]);

        return (new Renderer($config))->render('clarity', 'home', $data + [
            'pages' => [],
            'basePath' => '',
        ]);
    }

    public function testThePaletteAndTheTypeFaceAreUnchanged(): void
    {
        $css = $this->css();

        $expected = [
            '--surface' => '#fcf9f8',
            '--canvas' => '#ffffff',
            '--on-surface' => '#1b1c1c',
            '--primary' => '#1b70b7',
            '--secondary' => '#0645ad',
            '--footer-bg' => '#303030',
            '--outline' => '#717782',
            '--font-body' => 'arimo, arial, helvetica, sans-serif',
        ];

        foreach ($expected as $token => $value) {
            self::assertSame($value, strtolower($this->token($css, $token)), $token . ' keeps its value');
        }
    }

    /**
     * The index used to open with a card and no heading: the site title in the
     * bar is an anchor. A document with no h1 is a document a screen reader
     * cannot name, so this is the assertion the pass exists for.
     */
    public function testTheIndexHasOneHeadingAndItNamesTheSite(): void
    {
        $html = $this->home(['site_name' => 'Mobile Mypapit Policy Site']);

        self::assertSame(1, substr_count($html, '<h1'), 'exactly one h1 on the index');
        self::assertMatchesRegularExpression(
            '/<h1 class="index-head__title">Mobile Mypapit Policy Site<\/h1>/',
            $html,
        );
        // The heading names the page before the stream starts, not after it.
        self::assertLessThan(
            (int) strpos($html, 'class="page-stream"') ?: PHP_INT_MAX,
            (int) strpos($html, 'class="index-head"'),
        );
    }

    public function testTheTaglineFollowsTheHeadingAndIsEscaped(): void
    {
        $html = $this->home([
            'site_name' => 'Segara',
            'site_tagline' => 'Tide tables <b>not</b> dashboards',
        ]);

        self::assertStringContainsString('class="index-head__lead"', $html);
        self::assertStringContainsString('Tide tables &lt;b&gt;not&lt;/b&gt; dashboards', $html);
        self::assertStringNotContainsString('Tide tables <b>not</b>', $html);
    }

    public function testAnAbsentTaglineRendersNoLeadLine(): void
    {
        self::assertStringNotContainsString('index-head__lead', $this->home(['site_name' => 'Segara']));
    }

    /**
     * The lead entry is the one surface allowed to stand forward: the band the
     * footer already uses, the Title step, and the only resting shadow. An
     * ordinary entry separates by its hairline alone.
     */
    public function testTheFirstEntryLeadsAndTheRestOfTheStreamStandsBack(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.page-card:first-child\s*\{[^}]*border-top:\s*6px solid var\(--primary\)/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child\s*\{[^}]*box-shadow:\s*var\(--shadow-1\)/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child h2\s*\{[^}]*font-size:\s*clamp\(1\.6rem, 3vw, 2\.15rem\)/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child h2\s*\{[^}]*max-width:\s*26ch/s',
            $css,
        );

        // The base card carries no shadow of its own: the lead's shadow is the
        // signal, so a second lifted card would flatten the page again.
        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*border:\s*1px solid var\(--border\)/s', $css);
        // The transition names the property; the base card never sets it.
        self::assertDoesNotMatchRegularExpression('/\.page-card\s*\{[^}]*box-shadow:/s', $css);
        self::assertMatchesRegularExpression('/\.page-card h2\s*\{[^}]*font-size:\s*1\.25rem/s', $css);
    }

    public function testTheCardAnswersAHoverAndAFocusedLink(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.page-card:hover,\s*\n\.page-card:focus-within\s*\{[^}]*transform:\s*translateY\(-2px\)/s',
            $css,
        );
        // Reduced motion drops the rise and keeps the shadow.
        self::assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce.{0,200}?\.page-card:focus-within\s*\{\s*transform:\s*none/s',
            $css,
        );
    }

    /** The Never-Grey Shadow Rule: clarity was the last theme painting one. */
    public function testDepthIsTintedByTheThemeAndNeverNeutralGrey(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('rgba(0, 0, 0', $css);
        self::assertStringNotContainsString('rgba(0,0,0', $css);
        self::assertMatchesRegularExpression(
            '/--shadow-1:\s*[^;]*rgba\(27, 112, 183/',
            $css,
            'the resting shadow is a tint of the theme blue',
        );
        self::assertMatchesRegularExpression('/--shadow-2:\s*[^;]*rgba\(27, 112, 183/', $css);
    }

    /** The 66ch Measure Rule: the summary used to run about 116 characters. */
    public function testTheCardSummaryHoldsAMeasure(): void
    {
        self::assertMatchesRegularExpression(
            '/\.page-card p\s*\{[^}]*max-width:\s*66ch/s',
            $this->css(),
        );
    }

    /**
     * The title link carries a full-card overlay, so an entry is one tab stop
     * and the whole card is the target. The focus ring is drawn on the overlay,
     * which cannot reshape the card it covers.
     */
    public function testAnEntryIsOneTabStopWithAFullCardTarget(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*position:\s*relative/s', $css);
        self::assertMatchesRegularExpression(
            '/\.page-card h2 a::after\s*\{[^}]*position:\s*absolute;\s*\n\s*inset:\s*0/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.page-card h2 a:focus-visible::after\s*\{[^}]*outline:\s*2px solid var\(--primary\)/s',
            $css,
        );

        $card = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/partials/page-card.php');
        self::assertSame(1, substr_count($card, '<a '), 'one link per entry');
        self::assertStringContainsString('page-card__more', $card);
        self::assertStringContainsString('aria-hidden="true"', $card);
    }

    /**
     * The defect the owner reported: on a one-entry index the footer stopped
     * where the content stopped and floated mid-screen with page background
     * below it. Body is the column, the layout grows, the band closes it.
     */
    public function testTheFooterClosesThePageEvenWhenTheContentIsShort(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/body\s*\{[^}]*display:\s*flex;\s*\n\s*flex-direction:\s*column;\s*\n\s*min-height:\s*100vh;\s*\n\s*min-height:\s*100svh/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*flex:\s*1 0 auto/s', $css);
        // A grown grid row would stretch the content: the frame stays at the top.
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*align-content:\s*start/s', $css);
    }

    /** The band spans the viewport; its content starts on the page column. */
    public function testTheFooterContentSitsOnThePageColumn(): void
    {
        self::assertMatchesRegularExpression(
            '/\.site-footer\s*\{[^}]*padding:\s*3rem max\(var\(--content-pad\), calc\(\(100% - var\(--content-max\)\) \/ 2 \+ var\(--content-pad\)\)\) 2rem/s',
            $this->css(),
        );
    }

    /** One column of full-width cards was the flattest composition in the set. */
    public function testTheIndexIsAGridWithTheLeadAcrossBothTracks(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.page-stream\s*\{[^}]*display:\s*grid/s', $css);
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 900px\)\s*\{[^}]*\.page-stream\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/s',
            $css,
        );
        // The media block closes .page-stream before the lead rule, so this
        // pattern must be allowed to cross a brace.
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 900px\).{0,400}?\.page-card:first-child\s*\{[^}]*grid-column:\s*1 \/ -1/s',
            $css,
        );
    }

    /**
     * Clarity used the shared card, which carries no date, no tags and no read
     * affordance: an entry was a title in a box. It owns its card now.
     */
    public function testTheEntryCarriesItsDateTagsAndReadAffordance(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/home.php');
        $card = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/partials/page-card.php');
        $css = $this->css();

        self::assertStringContainsString("require __DIR__ . '/partials/page-card.php'", $home);
        self::assertStringNotContainsString('shared/page-card.php', $home);

        self::assertStringContainsString('page-card__date', $card);
        self::assertMatchesRegularExpression('/<time class="page-card__date" datetime="[^"]+"/', $card);
        self::assertStringContainsString('page-card__tags', $card);
        self::assertStringContainsString('page-card__tag', $card);
        self::assertStringContainsString('array_slice($page[\'tags\'], 0, 3)', $card);

        foreach (['.page-card__date', '.page-card__tag', '.page-card__more'] as $selector) {
            self::assertStringContainsString($selector, $css, $selector . ' is drawn');
        }
        // The affordance sits on the bottom edge whatever the card contains.
        self::assertMatchesRegularExpression('/\.page-card__more\s*\{[^}]*margin-top:\s*auto/s', $css);
    }

    /** Two counts the index can prove about itself, below its heading. */
    public function testTheIndexHeadCarriesTheCountsItCanProve(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/home.php');
        $css = $this->css();

        self::assertStringContainsString('index-head__stats', $home);
        self::assertStringContainsString("<dt>Entries</dt>", $home);
        self::assertStringContainsString("<dt>Latest</dt>", $home);
        self::assertMatchesRegularExpression('/\.index-head__stats\s*\{[^}]*display:\s*flex/s', $css);
        // Never above the heading: the stats sit after the h1 in the markup.
        self::assertLessThan(
            (int) strpos($home, 'index-head__stats'),
            (int) strpos($home, '<h1 class="index-head__title">'),
        );
    }

    /** The article carries its reading time below its heading, like its siblings. */
    public function testTheArticleCarriesAReadingTimeBelowItsHeading(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/page.php');
        $css = $this->css();

        $head = strpos($page, 'class="article-head"');
        self::assertIsInt($head);
        $heading = strpos($page, '</h1>', $head);
        $meta = strpos($page, 'article-meta', $head);
        self::assertIsInt($heading);
        self::assertIsInt($meta);
        self::assertGreaterThan((int) $heading, (int) $meta, 'the reading time follows the heading');
        self::assertStringContainsString('min read', $page);
        self::assertMatchesRegularExpression('/\.article-meta\s*\{[^}]*display:\s*flex/s', $css);
    }

    public function testTheCardAndBadgeInksClearTheContrastFloor(): void
    {
        // Summary ink on the card, badge ink on its tint, lead ink on the page.
        self::assertGreaterThanOrEqual(4.5, $this->contrast('#ffffff', '#555555'));
        self::assertGreaterThanOrEqual(4.5, $this->contrast('#eaf4fc', '#124e7c'));
        self::assertGreaterThanOrEqual(4.5, $this->contrast('#fcf9f8', '#555555'));
        // Large display type only owes 3:1.
        self::assertGreaterThanOrEqual(3.0, $this->contrast('#fcf9f8', '#1b1c1c'));
    }

    public function testTheEmptyStateIsNotBareText(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.empty-state\s*\{[^}]*border:\s*1px dashed var\(--border\)/s', $css);
        self::assertMatchesRegularExpression('/\.empty-state\s*\{[^}]*border-radius:\s*var\(--radius\)/s', $css);
        self::assertStringContainsString('.empty-state__icon', $css);
        self::assertStringContainsString('empty-state__icon', (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/home.php'));
    }

    public function testTheArticleAndErrorHeadingsTakeTheHeadlineRole(): void
    {
        $css = $this->css();

        foreach (['.page-content h1', '.main > h1'] as $selector) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\s*\{[^}]*font-size:\s*clamp\(2\.05rem, 4vw, 2\.9rem\)/s',
                $css,
                $selector . ' takes the Headline role',
            );
        }
        self::assertMatchesRegularExpression('/\.page-content h1\s*\{[^}]*text-wrap:\s*balance/s', $css);
    }

    public function testTheBrowserSurfacesAreThemed(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/body\s*\{[^}]*caret-color:\s*var\(--primary\)/s', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*scrollbar-color:/s', $css);
        self::assertMatchesRegularExpression('/::selection\s*\{[^}]*background:\s*var\(--accent-soft\)/s', $css);
    }

    public function testALongSiteNameCannotWidenThePage(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.index-head__title,\s*\n\.index-head__lead,[^{]*\{[^}]*overflow-wrap:\s*anywhere/s',
            $css,
        );
        self::assertStringContainsString('overflow-wrap: anywhere', $css);
    }

    public function testThePressSheetInksExistAndClearTheirRatios(): void
    {
        $css = $this->css();

        self::assertSame('#a52a2a', strtolower($this->token($css, '--ink-plate')));
        self::assertSame('#6b6f76', strtolower($this->token($css, '--rule-ink')));
        self::assertGreaterThanOrEqual(4.5, $this->contrast('#fcf9f8', '#a52a2a'), 'letterpress red is text-safe');
        self::assertGreaterThanOrEqual(3.0, $this->contrast('#fcf9f8', '#6b6f76'), 'graphite clears the non-text floor');
        // The red is an accent, never the body ink.
        self::assertMatchesRegularExpression('/body\s*\{[^}]*color:\s*var\(--on-surface\)/s', $css);
    }

    /**
     * A gradient stop needs a concrete colour, so each field repeats its token's
     * channels. This is the guard that the two cannot drift apart.
     */
    public function testEveryFieldMatchesItsToken(): void
    {
        $css = $this->css();

        $pairs = [
            'rgba(107, 111, 118' => $this->token($css, '--rule-ink'),        // --field-graph, --field-hatch
            'rgba(165, 42, 42' => $this->token($css, '--ink-plate'),          // --field-frieze
            'rgba(159, 202, 255' => $this->token($css, '--footer-accent'),    // --field-pinstripe
        ];

        foreach ($pairs as $rgba => $hex) {
            $hex = ltrim($hex, '#');
            $channels = sprintf(
                '%d, %d, %d',
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
            );
            self::assertStringContainsString('rgba(' . $channels, $css, $rgba . ' must be #' . $hex);
        }
    }

    public function testTheRedNeverCarriesBodyCopy(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\.page-content p[^{]*\{[^}]*color:\s*var\(--ink-plate\)/s',
            $this->css(),
            'running text stays on the ink token',
        );
    }

    public function testTheRegisterMarkIsOneSymbolUsedDecoratively(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/layout.php');

        self::assertSame(1, substr_count($layout, 'id="s-register"'), 'the motif is drawn once');
        self::assertMatchesRegularExpression(
            '/<symbol id="s-register" viewBox="0 0 24 24">.*?<\/symbol>/s',
            $layout,
        );
        // Every use is decoration, never an announced image.
        self::assertDoesNotMatchRegularExpression('/<svg class="register-mark"(?![^>]*aria-hidden)/', $layout);
    }

    public function testTheMarkSitsOnTheLeadEntryNotOnTheFeaturedFlag(): void
    {
        $card = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/partials/page-card.php');
        $css = $this->css();

        self::assertStringContainsString('register-mark', $card);
        self::assertStringContainsString('aria-hidden="true"', $card);
        // The mark follows the positional lead, never the placement flag.
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child \.register-mark\s*\{[^}]*position:\s*absolute/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.register-mark\s*\{[^}]*width:\s*22px/s', $css);
        // A wrapped long title must not run under the mark.
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child h2\s*\{[^}]*padding-right:\s*2\.5rem/s',
            $css,
        );
        // Neither may the date row: the mark occupies the card's top-right corner,
        // and the date is right-aligned straight into it.
        self::assertMatchesRegularExpression(
            '/\.page-card:first-child \.page-card__top\s*\{[^}]*padding-right:\s*2\.2rem/s',
            $css,
            'the lead card date row clears the mark',
        );
    }

    public function testTheArticleMarkIsAWatermarkThatYieldsOnAPhone(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__) . '/themes/clarity/page.php');
        $css = $this->css();

        self::assertMatchesRegularExpression('/class="article-head".{0,200}?register-mark/s', $page);
        // 96px, not the 140 the first draft named: a one-line article head is
        // about 100px tall, so a 140px mark cannot sit inside it at any offset.
        self::assertMatchesRegularExpression(
            '/\.article-head \.register-mark\s*\{[^}]*width:\s*96px/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.article-head \.register-mark\s*\{[^}]*opacity:\s*0\.06/s', $css);
        self::assertMatchesRegularExpression('/\.article-head \.register-mark\s*\{[^}]*pointer-events:\s*none/s', $css);
        // It must sit inside the head, not hang out of it: a negative top offset
        // reaches past the block and paints over the first paragraph's line box.
        self::assertDoesNotMatchRegularExpression(
            '/\.article-head \.register-mark\s*\{[^}]*top:\s*-/s',
            $css,
            'the watermark stays within the head it belongs to',
        );
        // It yields below 1024, not only on a phone: at tablet widths a one-line
        // article head is shorter than the 140px mark, which then overhangs into
        // the first paragraph instead of sitting inside the head.
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\) \{\s*\.article-head \.register-mark \{ display: none; \}/s',
            $css,
            'the watermark yields wherever the head is shorter than the mark',
        );
    }

    public function testEveryFieldHoldsItsCeiling(): void
    {
        $css = $this->css();

        // Under text: graphite at 8% or less.
        self::assertMatchesRegularExpression('/--field-graph:[^;]*rgba\(107, 111, 118, 0\.08\)/s', $css);
        self::assertMatchesRegularExpression('/--field-hatch:[^;]*rgba\(107, 111, 118, 0\.07\)/s', $css);
        // Inside the footer band: a band colour, and quietly.
        self::assertMatchesRegularExpression('/--field-pinstripe:[^;]*rgba\(159, 202, 255, 0\.18\)/s', $css);
        // The band field is texture, not a second surface.
        self::assertLessThanOrEqual(1.6, $this->contrast('#303030', '#464f59'));

        // Applied to real elements.
        self::assertMatchesRegularExpression('/\.index-head\s*\{[^}]*background-image:\s*var\(--field-graph\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-card__top\s*\{[^}]*background-image:\s*var\(--field-hatch\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*background-image:[^;]*var\(--field-pinstripe\)/s', $css);

        // No field may reintroduce a neutral black.
        self::assertStringNotContainsString('rgba(0, 0, 0', $css);
    }

    public function testTheDesignSystemRecordsThePressSheet(): void
    {
        $design = (string) file_get_contents(dirname(__DIR__) . '/DESIGN.md');

        self::assertStringContainsString('The Registration Ink Rule', $design);
        self::assertStringContainsString('#a52a2a', strtolower($design));
        self::assertStringContainsString('The Press Sheet', $design);
        // The palette entry itself names the redesign, not just a stray mention.
        self::assertMatchesRegularExpression(
            '/- \*\*Inland Blue\*\* \(`#1b70b7`, Clarity[^)]*The Press Sheet/s',
            $design,
            'the Primary entry names the theme its redesign made',
        );
    }

    /**
     * A pinstripe is a pair of hairlines on a diagonal, not a grid. Three things
     * can go wrong and none of them is catchable by eye at build time: the angle
     * falls back to 90deg (a venetian blind), the strokes widen into a fill, or
     * the ink fades below the point where it prints at all.
     */
    public function testTheFooterPinstripeIsADiagonalPair(): void
    {
        $css = $this->css();
        $stripe = $this->pinstripe($css);

        // A diagonal. 90deg is the vertical blind this replaced.
        self::assertSame(135.0, $stripe['angle'], 'the stripe runs on the diagonal, not upright');

        // A pair, not one line: the two hairlines are what read as woven cloth
        // rather than as a ruled grid.
        self::assertCount(2, $stripe['strokes'], 'the pinstripe is a pair of hairlines');

        foreach ($stripe['strokes'] as $stroke) {
            self::assertLessThanOrEqual(1.5, $stroke['width'], 'each stroke is a hairline, not a band');
        }

        // Total ink in the repeat, as a share of the repeat.
        $coverage = $stripe['ink'] / $stripe['pitch'];
        self::assertLessThanOrEqual(0.25, $coverage, 'the stripe covers at most a quarter of the weave');
        self::assertGreaterThanOrEqual(0.10, $coverage, 'the stripe must be dense enough to read as cloth');

        // The colour must be the band's own token, and the ratio derived from it.
        $accent = $this->token($css, '--footer-accent');
        self::assertSame(
            $accent,
            sprintf('#%02x%02x%02x', $stripe['red'], $stripe['green'], $stripe['blue']),
            'the pinstripe is drawn in the band token, not in the page inks',
        );

        $line = $this->blend('#303030', $accent, $stripe['alpha']);
        self::assertLessThanOrEqual(1.6, $this->contrast('#303030', $line), 'under the quiet ceiling');
        self::assertGreaterThan(1.2, $this->contrast('#303030', $line), 'and visible on the band');
    }

    /**
     * A diagonal must not be tiled to a square background-size: the gradient then
     * restarts every tile and the stripes seam into a chevron at the joins.
     */
    public function testTheDiagonalIsNotTiledIntoSeams(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.site-footer\s*\{[^}]*background-image:[^;]*var\(--field-pinstripe\)/s',
            $css,
            'the footer paints the pinstripe',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.site-footer\s*\{[^}]*background-size:\s*\d/s',
            $css,
            'a diagonal gradient must not be forced to a square tile',
        );
    }

    public function testTheFriezeIsAppliedAndIsTheSizeTheSheetSpecifies(): void
    {
        $css = $this->css();

        // A field nothing paints is dead CSS; this asserts the application.
        self::assertMatchesRegularExpression(
            '/\.index-head::after\s*\{[^}]*background-image:\s*var\(--field-frieze\)/s',
            $css,
            'the frieze field is applied, not only declared',
        );
        self::assertMatchesRegularExpression('/\.index-head::after\s*\{[^}]*height:\s*18px/s', $css);
        self::assertMatchesRegularExpression('/\.index-head::after\s*\{[^}]*content:\s*""/s', $css);
    }

    public function testTheRubricOpensBothRegistersTheSheetNames(): void
    {
        $css = $this->css();

        // Both placements the spec names: the index's lead line and the article's
        // facts line. A class no template emits is not a placement.
        self::assertMatchesRegularExpression(
            '/\.index-head__lead\s*\{[^}]*border-top:\s*2px solid var\(--ink-plate\)/s',
            $css,
            'the lead line is opened by the rubric',
        );
        self::assertMatchesRegularExpression(
            '/\.article-meta\s*\{[^}]*border-top:\s*2px solid var\(--ink-plate\)/s',
            $css,
            'the article facts line is opened by the rubric',
        );
        self::assertStringNotContainsString(
            '.rubric {',
            $css,
            'no rule class ships that no template emits',
        );
    }

    /**
     * Every decorative SVG must be hidden from the accessibility tree. The old
     * guard scanned layout.php, which emits no `class="register-mark"` at all,
     * so it could never fail; this scans the files that actually emit them.
     */
    public function testEveryRegisterMarkIsHiddenFromAssistiveTechnology(): void
    {
        $root = dirname(__DIR__) . '/themes/clarity';
        $found = 0;

        foreach (['page.php', 'partials/page-card.php', 'home.php', 'layout.php'] as $file) {
            $markup = (string) file_get_contents($root . '/' . $file);

            preg_match_all('/<svg class="register-mark"[^>]*>/', $markup, $matches);
            foreach ($matches[0] as $tag) {
                ++$found;
                self::assertStringContainsString('aria-hidden="true"', $tag, $file . ' keeps the mark out of the tree');
                self::assertStringContainsString('focusable="false"', $tag, $file . ' keeps the mark out of the tab order');
            }
        }

        self::assertGreaterThanOrEqual(2, $found, 'the mark is used in both placements this theme claims');
    }

    private function blend(string $background, string $foreground, float $alpha): string
    {
        $bg = array_map(static fn (int $i): int => (int) hexdec(substr(ltrim($background, '#'), $i, 2)), [0, 2, 4]);
        $fg = array_map(static fn (int $i): int => (int) hexdec(substr(ltrim($foreground, '#'), $i, 2)), [0, 2, 4]);

        $mixed = [];
        foreach ([0, 1, 2] as $channel) {
            $mixed[] = (int) round($bg[$channel] * (1 - $alpha) + $fg[$channel] * $alpha);
        }

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    /**
     * The pinstripe's angle, strokes, repeat and ink, validated. Returns a shape
     * the assertions can index without re-deriving it from a raw preg_match.
     *
     * @return array{angle: float, alpha: float, red: int, green: int, blue: int,
     *               pitch: float, ink: float, strokes: list<array{width: float}>}
     */
    private function pinstripe(string $css): array
    {
        if (preg_match('/--field-pinstripe:\s*repeating-linear-gradient\(\s*([\d.]+)deg,(.+?)\);/s', $css, $outer) !== 1) {
            throw new RuntimeException('--field-pinstripe is not in the form this test can measure');
        }

        $angle = (float) $outer[1];
        $body = $outer[2];

        // A CSS zero is unitless: `0 1px`, not `0px 1px`.
        preg_match_all('/rgba\((\d+), (\d+), (\d+), ([\d.]+)\) ([\d.]+)(?:px)? ([\d.]+)px/', $body, $ink, PREG_SET_ORDER);
        if ($ink === []) {
            throw new RuntimeException('--field-pinstripe declares no ink stops');
        }

        $strokes = [];
        $total = 0.0;
        foreach ($ink as $stop) {
            $width = (float) $stop[6] - (float) $stop[5];
            $strokes[] = ['width' => $width];
            $total += $width;
        }

        // The last transparent stop closes the repeat, so its end is the pitch.
        if (preg_match_all('/transparent ([\d.]+)(?:px)? ([\d.]+)px/', $body, $clear, PREG_SET_ORDER) === 0) {
            throw new RuntimeException('--field-pinstripe has no closing transparent stop');
        }
        $last = end($clear);
        if ($last === false) {
            throw new RuntimeException('--field-pinstripe has no closing transparent stop');
        }
        $pitch = (float) $last[2];

        return [
            'angle' => $angle,
            'red' => (int) $ink[0][1],
            'green' => (int) $ink[0][2],
            'blue' => (int) $ink[0][3],
            'alpha' => (float) $ink[0][4],
            'strokes' => $strokes,
            'ink' => $total,
            'pitch' => $pitch,
        ];
    }

    private function contrast(string $background, string $foreground): float
    {
        $l1 = $this->luminance($background);
        $l2 = $this->luminance($foreground);
        [$light, $dark] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channel = static function (int $offset) use ($hex): float {
            $value = (float) hexdec(substr($hex, $offset, 2)) / 255.0;

            if ($value <= 0.03928) {
                return $value / 12.92;
            }

            return (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(0) + 0.7152 * $channel(2) + 0.0722 * $channel(4);
    }
}
