<?php

declare(strict_types=1);

namespace Segara\View;

use InvalidArgumentException;
use RuntimeException;
use Segara\Config;
use Segara\Content\PageDate;
use Segara\Settings\SettingsRepository;
use Throwable;

final class Renderer
{
    private const ADMIN_TEMPLATES = ['accounts', 'login'];
    private const SHARED_TEMPLATES = ['head'];
    private const PUBLIC_TEMPLATES = ['home', 'page', 'error'];

    private readonly string $themeRoot;
    private readonly Themes $themes;

    public function __construct(
        private readonly Config $config,
        ?string $themeRoot = null,
        private readonly ?SettingsRepository $settings = null,
    ) {
        $this->themeRoot = $themeRoot ?? dirname(__DIR__, 2) . '/themes';
        $this->themes = new Themes($this->themeRoot);
    }

    /**
     * The public theme chosen in Settings, falling back to the default when the
     * store is unavailable or the stored value is not an allowlisted theme.
     */
    public function activeTheme(): string
    {
        if ($this->settings === null) {
            return 'clarity';
        }
        $theme = $this->settings->all()['theme'] ?? 'clarity';

        return in_array($theme, Themes::ALL, true) ? $theme : 'clarity';
    }

    /** @param array<string, mixed> $data */
    public function render(string $theme, string $template, array $data = []): string
    {
        if ($theme === 'admin') {
            $this->assertTemplate($template, self::ADMIN_TEMPLATES);

            return $this->buffer($this->file($theme, $template), $data);
        }

        if ($theme === 'shared') {
            $this->assertTemplate($template, self::SHARED_TEMPLATES);
            $metadata = $data['metadata'] ?? null;
            if (!$metadata instanceof Metadata) {
                throw new InvalidArgumentException('The shared head template requires Metadata.');
            }
            $data['metadata'] = $metadata->headData();

            return $this->buffer($this->file($theme, $template), $data);
        }

        if (in_array($theme, Themes::ALL, true)) {
            $this->assertTemplate($template, self::PUBLIC_TEMPLATES);
            $data += ['basePath' => $this->config->basePath];
            // Site identity from Settings, so the name, description and language
            // the owner saved actually reach the page. A template that supplies
            // its own value still wins.
            $data += $this->identity($data);
            $pageDate = $this->pageDate($data);
            if ($pageDate !== null) {
                $data['page_date'] = $pageDate;
            }
            if ($template === 'home') {
                $data += ['heroTitle' => '', 'heroLead' => ''];
            }
            $content = $this->buffer($this->file($theme, $template), $data);

            return $this->layout($theme, $template, $data, $content);
        }

        throw new InvalidArgumentException('View theme or template is not in the fixed allowlist.');
    }

    public function head(Metadata $metadata, string $format = 'html'): string
    {
        if ($format !== 'html') {
            return '';
        }

        return $this->render('shared', 'head', ['metadata' => $metadata]);
    }

    /** @param array<string, mixed> $data */
    private function layout(string $theme, string $template, array $data, string $content): string
    {
        $metadata = $this->metadataFor($template, $data);
        $siteName = is_string($data['site_name'] ?? null) && $data['site_name'] !== ''
            ? $data['site_name']
            : 'Segara';

        $shell = $data + [
            'content' => $content,
            'head' => $this->render('shared', 'head', ['metadata' => $metadata]),
            'theme' => $theme,
            // The layout wraps home, page and error output, and a theme may want a
            // band above the column on the home page only. Passing the template
            // name would leak the renderer's vocabulary; a boolean does not.
            'isHome' => $template === 'home',
            'themeVersion' => $this->themeVersion($theme),
            'sidebars' => $this->themes->info($theme)['sidebars'],
            'language' => $metadata->language(),
            'siteName' => $siteName,
            'navigation' => $data['navigation'] ?? [],
            'leftSidebar' => $data['left_sidebar'] ?? '',
            'rightSidebar' => $data['right_sidebar'] ?? '',
            'footerHtml' => $data['footer_html'] ?? '',
            'hasCopyrightBlock' => $data['has_copyright_block'] ?? false,
            'announcement' => $data['announcement'] ?? null,
        ];

        return $this->buffer($this->file($theme, 'layout'), $shell);
    }

    /**
     * The stored site identity, used as the fallback for `site_name`,
     * `description` and `language` in every public template.
     *
     * @param array<string, mixed> $data
     * @return array{site_name: string, description: string, language: string}
     */
    private function identity(array $data): array
    {
        $stored = $this->settings?->all() ?? [];

        $pick = static function (string $key, string $default) use ($stored, $data): string {
            $given = $data[$key] ?? null;
            if (is_string($given) && $given !== '') {
                return $given;
            }
            $value = $stored[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : $default;
        };

        // Settings accepts `en_MY`, the metadata layer requires BCP 47 (`en-MY`).
        // An unusable stored locale must not take the public site down.
        $language = str_replace('_', '-', $pick('locale', 'en'));
        if (preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/D', $language) !== 1) {
            $language = 'en';
        }

        return [
            'site_name' => $pick('site_name', 'Segara'),
            'site_tagline' => $pick('site_tagline', ''),
            'description' => $pick('site_description', ''),
            'language' => $language,
        ];
    }

    /**
     * The date under an article, in the format Settings chose. A stored value
     * that cannot be parsed must never take a public page down, so a failure
     * means no date rather than a 500.
     *
     * @param array<string, mixed> $data
     * @return array{iso: string, text: string}|null
     */
    private function pageDate(array $data): ?array
    {
        $published = $data['page_published_at'] ?? null;
        if (!is_string($published) || trim($published) === '') {
            return null;
        }

        $stored = $this->settings?->all() ?? [];
        $mode = is_string($stored['page_date_format'] ?? null) ? $stored['page_date_format'] : PageDate::DEFAULT_MODE;
        $pattern = is_string($stored['page_date_pattern'] ?? null) ? $stored['page_date_pattern'] : PageDate::DEFAULT_PATTERN;
        $timezone = is_string($stored['timezone'] ?? null) && in_array($stored['timezone'], timezone_identifiers_list(), true)
            ? $stored['timezone']
            : 'UTC';
        $locale = str_replace('_', '-', is_string($stored['locale'] ?? null) ? $stored['locale'] : 'en');
        if (preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/D', $locale) !== 1) {
            $locale = 'en';
        }

        try {
            return (new PageDate())->format($published, $mode, $pattern, $timezone, $locale);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Stylesheet mtime, so a deploy cannot leave HTML and CSS out of sync
     * behind the one-hour max-age on theme.css.php.
     */
    private function themeVersion(string $theme): string
    {
        $path = dirname(__DIR__, 2) . '/themes/' . $theme . '/assets/theme.css';
        $mtime = is_file($path) ? filemtime($path) : false;

        return (string) ($mtime === false ? 0 : $mtime);
    }

    /** @param array<string, mixed> $data */
    private function metadataFor(string $template, array $data): Metadata
    {
        if (($data['metadata'] ?? null) instanceof Metadata) {
            return $data['metadata'];
        }

        $siteName = is_string($data['site_name'] ?? null) && $data['site_name'] !== ''
            ? $data['site_name']
            : 'Segara';
        $title = match ($template) {
            'error' => '404 — Page not found',
            'home' => $siteName,
            default => is_string($data['title'] ?? null) && $data['title'] !== ''
                ? $data['title']
                : $siteName,
        };

        $values = [
            'title' => $title,
            'site_name' => $siteName,
            'path' => is_string($data['path'] ?? null) && $data['path'] !== '' ? $data['path'] : '/',
        ];
        if ($template === 'error') {
            $values['robots'] = 'noindex, nofollow';
        }
        $description = $data['description'] ?? null;
        if (is_string($description) && $description !== '') {
            $values['description'] = $description;
        }

        return Metadata::fromArray($this->config, $values);
    }

    private function file(string $theme, string $template): string
    {
        $file = $this->themeRoot . '/' . $theme . '/' . $template . '.php';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('View template is unavailable.');
        }

        return $file;
    }

    /** @param list<string> $allowed */
    private function assertTemplate(string $template, array $allowed): void
    {
        if (!in_array($template, $allowed, true)) {
            throw new InvalidArgumentException('View theme or template is not in the fixed allowlist.');
        }
    }

    /** @param array<string, mixed> $data */
    private function buffer(string $file, array $data): string
    {
        $data += ['config' => $this->config, 'links' => new Links($this->config)];
        $render = static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            require $file;

            return '';
        };

        $level = ob_get_level();
        ob_start();
        try {
            $render();
            $output = ob_get_clean();
            if (!is_string($output)) {
                throw new RuntimeException('Unable to capture rendered view.');
            }

            return $output;
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
