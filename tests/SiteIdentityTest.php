<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Config;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

/**
 * "Site identity" in Settings only ever reached the admin form. The public
 * controllers never passed `site_name`, `site_description` or `locale` to the
 * renderer, so every page advertised "Segara" with no description no matter
 * what the owner saved. These tests pin the plumbing from the store to the page.
 */
final class SiteIdentityTest extends TestCase
{
    private PDO $pdo;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->settings = new SettingsRepository($this->pdo);
    }

    public function testTheStoredSiteNameReachesTheTitleTheBrandAndTheStructuredData(): void
    {
        $this->settings->setMany(['site_name' => 'Mobile Mypapit Policy Site']);

        $html = $this->renderHome();

        self::assertStringContainsString('<title>Mobile Mypapit Policy Site</title>', $html);
        self::assertStringContainsString('class="site-title"', $html);
        self::assertStringContainsString('Mobile Mypapit Policy Site', $html);
        self::assertStringNotContainsString('<title>Segara</title>', $html);
        // JSON-LD carries the same identity.
        self::assertStringContainsString('"name":"Mobile Mypapit Policy Site"', $html);
    }

    public function testTheStoredDescriptionFillsTheMetaDescription(): void
    {
        $this->settings->setMany([
            'site_name' => 'Segara',
            'site_description' => 'Notes from the field on teaching and research.',
        ]);

        $html = $this->renderHome();

        self::assertStringContainsString(
            '<meta name="description" content="Notes from the field on teaching and research.">',
            $html
        );
    }

    public function testTheStoredLocaleBecomesTheDocumentLanguage(): void
    {
        // Settings accepts the underscore form; the document needs BCP 47.
        $this->settings->setMany(['locale' => 'ms_MY']);

        $html = $this->renderHome();

        self::assertStringContainsString('<html lang="ms-MY">', $html);
    }

    public function testAnUnusableStoredLocaleFallsBackInsteadOfBreakingThePage(): void
    {
        $this->settings->setMany(['locale' => 'not a locale!!']);

        $html = $this->renderHome();

        self::assertStringContainsString('<html lang="en">', $html);
    }

    public function testAPageStillOverridesTheSiteDescription(): void
    {
        $this->settings->setMany(['site_description' => 'Site level text']);

        $html = (new Renderer($this->config(), null, $this->settings))->render('clarity', 'page', [
            'title' => 'A page',
            'body' => '<p>Body</p>',
            'description' => 'Page level text',
            'challenge' => false,
            'csrfToken' => '',
            'path' => '/page',
        ]);

        self::assertStringContainsString('content="Page level text"', $html);
        self::assertStringNotContainsString('content="Site level text"', $html);
    }

    /**
     * The slate theme's jumbotron reads $site_tagline. Prove the renderer hands
     * it to templates by rendering a probe theme that only echoes it.
     */
    public function testTheStoredTaglineReachesTheTemplates(): void
    {
        $this->settings->setMany(['site_tagline' => 'Notes on teaching and research']);

        $html = (new Renderer($this->config(), $this->probeTheme(), $this->settings))->render('clarity', 'home', [
            'pages' => [],
            'basePath' => '',
        ]);

        self::assertStringContainsString('TAGLINE:Notes on teaching and research', $html);
    }

    public function testAnAbsentTaglineRendersNothingRatherThanBreakingThePage(): void
    {
        $html = (new Renderer($this->config(), $this->probeTheme(), $this->settings))->render('clarity', 'home', [
            'pages' => [],
            'basePath' => '',
        ]);

        self::assertStringContainsString('TAGLINE:', $html);
        self::assertStringNotContainsString('TAGLINE:Segara', $html);
    }

    /**
     * A throwaway theme root: the real clarity templates with a home that only
     * prints the tagline, so the assertion is about the data contract, not a
     * theme's markup. The theme id must stay allowlisted, hence "clarity".
     */
    private function probeTheme(): string
    {
        $root = $this->storePath . '/probe-themes';
        if (!is_dir($root . '/clarity')) {
            mkdir($root . '/clarity', 0777, true);
        }
        foreach (['theme.php', 'layout.php', 'page.php', 'error.php', '500.php'] as $file) {
            copy(dirname(__DIR__) . '/themes/clarity/' . $file, $root . '/clarity/' . $file);
        }
        file_put_contents($root . '/clarity/home.php', '<?php echo "TAGLINE:" . $site_tagline;');
        // The layout renders the shared head/banner partials from the same root.
        if (!is_dir($root . '/shared')) {
            symlink(dirname(__DIR__) . '/themes/shared', $root . '/shared');
        }

        return $root;
    }

    private function renderHome(): string
    {
        return (new Renderer($this->config(), null, $this->settings))->render('clarity', 'home', [
            'pages' => [],
            'basePath' => '',
        ]);
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
