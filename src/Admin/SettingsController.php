<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Content\PageDate;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Support\Html;
use Segara\View\Themes;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class SettingsController
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'site_name' => 'Segara',
        'site_tagline' => '',
        'site_description' => '',
        'locale' => 'en',
        'timezone' => 'UTC',
        'currency' => 'MYR',
        'fuzzy_dates' => '0',
        'page_date_format' => PageDate::DEFAULT_MODE,
        'page_date_pattern' => PageDate::DEFAULT_PATTERN,
        'theme' => 'clarity',
        'homepage_id' => '',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly PageRepository $pages,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'GET') {
            return $this->methodNotAllowed('GET');
        }

        return $this->render(200, $current, $this->values());
    }

    public function update(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, $this->values(), 'Invalid request.');
        }

        try {
            $values = $this->validated($request);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $this->draftValues($request), $exception->getMessage());
        }

        $this->settings->setMany($values);

        return $this->redirect($this->settingsUrl(), 303);
    }

    /** @return array<string, string> */
    private function values(): array
    {
        // Stored values win: `+` keeps the left operand, so defaults on the left
        // masked every saved value and the form always re-rendered "Segara".
        return $this->settings->all() + self::DEFAULTS;
    }

    /** @return array<string, string> */
    private function draftValues(Request $request): array
    {
        $draft = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            $value = $request->post($key);
            if (is_string($value)) {
                $draft[$key] = $value;
            }
        }
        if ($request->post('fuzzy_dates') === null) {
            $draft['fuzzy_dates'] = '0';
        }

        return $draft;
    }

    /** @return array<string, string> */
    private function validated(Request $request): array
    {
        $siteName = $request->post('site_name');
        if (!is_string($siteName) || trim($siteName) === '' || !mb_check_encoding($siteName, 'UTF-8')
            || mb_strlen($siteName) > 300 || str_contains($siteName, "\0")
        ) {
            throw new InvalidArgumentException('Site name must be non-empty UTF-8 of at most 300 characters.');
        }

        $tagline = $request->post('site_tagline');
        if ($tagline !== null && (!is_string($tagline) || !mb_check_encoding($tagline, 'UTF-8')
            || mb_strlen($tagline) > 300 || str_contains($tagline, "\0"))
        ) {
            throw new InvalidArgumentException('Site tagline must be valid UTF-8 of at most 300 characters.');
        }

        $description = $request->post('site_description');
        if ($description !== null && (!is_string($description) || !mb_check_encoding($description, 'UTF-8')
            || mb_strlen($description) > 1000 || str_contains($description, "\0"))
        ) {
            throw new InvalidArgumentException('Site description must be valid UTF-8 of at most 1000 characters.');
        }

        $locale = $request->post('locale');
        if (!is_string($locale) || preg_match('/\A[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*\z/D', $locale) !== 1) {
            throw new InvalidArgumentException('Locale is invalid.');
        }

        $timezone = $request->post('timezone');
        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Timezone is invalid.');
        }

        $dateFormat = $request->post('page_date_format');
        if (!is_string($dateFormat)) {
            // A form or client that predates the field keeps the stored choice.
            $dateFormat = $this->settings->get('page_date_format') ?? PageDate::DEFAULT_MODE;
        }
        if (!in_array($dateFormat, PageDate::MODES, true)) {
            throw new InvalidArgumentException('Date format is not supported.');
        }

        $datePattern = $request->post('page_date_pattern');
        if (!is_string($datePattern)) {
            $datePattern = $this->settings->get('page_date_pattern') ?? PageDate::DEFAULT_PATTERN;
        }
        if (!mb_check_encoding($datePattern, 'UTF-8') || mb_strlen($datePattern) > PageDate::PATTERN_MAX
            || str_contains($datePattern, "\0")
        ) {
            throw new InvalidArgumentException('Date pattern must be valid UTF-8 of at most 64 characters.');
        }
        if ($dateFormat === 'custom' && !PageDate::isValidPattern($datePattern)) {
            throw new InvalidArgumentException('Custom date pattern must contain a date token such as F, j or Y, and only letters, digits, spaces and , . / : - ( ).');
        }
        if (!PageDate::isValidPattern($datePattern)) {
            // Unused by the chosen mode, but stored: keep a sane value.
            $datePattern = PageDate::DEFAULT_PATTERN;
        }

        $currency = $request->post('currency');
        if (!is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO 4217 code.');
        }

        $theme = $request->post('theme');
        if (!is_string($theme) || !in_array($theme, Themes::ALL, true)) {
            throw new InvalidArgumentException('Theme is not supported.');
        }

        $homepageId = $request->post('homepage_id');
        if ($homepageId === null || $homepageId === '') {
            $homepageId = '';
        } elseif (!is_string($homepageId) || $this->pages->getById($homepageId) === null) {
            throw new InvalidArgumentException('The selected homepage does not exist.');
        }

        return [
            'site_name' => $siteName,
            'site_tagline' => is_string($tagline) ? $tagline : '',
            'site_description' => is_string($description) ? $description : '',
            'locale' => $locale,
            'timezone' => $timezone,
            'currency' => $currency,
            // The relative-dates checkbox became part of page_date_format; keep
            // whatever is stored so a save does not silently rewrite the key.
            'fuzzy_dates' => $request->post('fuzzy_dates') === null
                ? (string) ($this->settings->get('fuzzy_dates') ?? '0')
                : '1',
            'page_date_format' => $dateFormat,
            'page_date_pattern' => $datePattern,
            'theme' => $theme,
            'homepage_id' => $homepageId,
        ];
    }

    /** @return Admin|null */
    private function authenticatedAdmin(): ?array
    {
        $adminId = $this->session->adminId();
        if ($adminId === null) {
            return null;
        }
        $admin = $this->admins->findById($adminId);
        if ($admin === null) {
            $this->session->logout();
            return null;
        }
        if (!$this->session->hasValidAuthentication($admin['auth_epoch'])) {
            return null;
        }

        return $admin;
    }

    /**
     * @param Admin $current
     * @param array<string, string> $values
     */
    private function render(int $status, array $current, array $values, ?string $error = null): Response
    {
        $themes = new Themes(dirname(__DIR__, 2) . '/themes');
        $themeOptions = [];
        foreach (Themes::ALL as $themeId) {
            $themeOptions[] = $themes->info($themeId);
        }

        $csrfToken = $this->csrf->token();
        $settingsUrl = $this->settingsUrl();
        $formAction = $this->url('settings-update');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $canonicalBase = $this->config->baseUrl;
        $viewSiteUrl = $this->config->basePath . '/';
        $dateExample = (new PageDate())->format(
            gmdate('Y-m-d\TH:i:s\Z'),
            is_string($values['page_date_format'] ?? null) ? $values['page_date_format'] : PageDate::DEFAULT_MODE,
            is_string($values['page_date_pattern'] ?? null) ? $values['page_date_pattern'] : PageDate::DEFAULT_PATTERN,
            is_string($values['timezone'] ?? null) && in_array($values['timezone'], timezone_identifiers_list(), true)
                ? $values['timezone']
                : 'UTC',
        )['text'];
        // Only a published page can be the homepage: a draft, private or trashed
        // page would make the site unreachable and its row unrestorable.
        $pages = $this->pages->listAdmin('published');
        $template = dirname(__DIR__, 2) . '/themes/admin/settings.php';

        $render = static function () use (
            $template, $values, $themeOptions, $pages, $csrfToken, $settingsUrl, $formAction, $accountsUrl,
            $logoutUrl, $basePath, $canonicalBase, $viewSiteUrl, $dateExample, $error
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render settings administration.');
                }
                return $body;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        };

        return (new Response(
            $status,
            SecurityHeaders::forConfig($this->config)
                + ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow'],
            $render(),
        ))->asSensitive();
    }

    private function methodNotAllowed(string $allow): Response
    {
        return (new Response(
            405,
            SecurityHeaders::forConfig($this->config) + ['Allow' => $allow],
            'Method not allowed.',
        ))->asSensitive();
    }

    private function redirect(string $location, int $status = 302): Response
    {
        return (new Response($status, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function settingsUrl(): string
    {
        return $this->url('settings');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
