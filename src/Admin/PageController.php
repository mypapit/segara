<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\WriteConflict;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @phpstan-import-type Page from PageRepository
 * @psalm-import-type Admin from AdminRepository
 * @psalm-import-type Page from PageRepository
 */
final class PageController
{
    private const STATES = ['published', 'private', 'draft', 'trash'];
    private const SCHEMA_TYPES = ['WebPage', 'Article', 'ProfilePage', 'ContactPage', 'AboutPage'];
    /** Only these submit names are understood; anything else is a 422, not a guess. */
    private const INTENTS = ['draft', 'publish', 'save'];

    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ?MediaRepository $media = null,
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

        $notice = $request->query('notice');
        $filter = $request->query('state');
        $state = is_string($filter) && in_array($filter, self::STATES, true) ? $filter : null;

        return $this->renderIndex(200, $current, null, is_string($notice) ? $notice : null, $state);
    }

    public function create(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() === 'GET') {
            return $this->renderForm(200, $current);
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->renderForm(400, $current, 'Invalid request.', $this->values($request));
        }

        if (!$this->knownIntent($request)) {
            return $this->renderForm(422, $current, 'Choose Save or Save and publish.', $this->values($request));
        }
        $values = $this->values($request);

        try {
            $values['state'] = $this->stateOrFail($values['state']);
            $author = $this->author($values['author_id'], $current);
            $this->pages->create([
                'title' => $values['title'],
                'slug' => $values['slug'],
                'body_markdown' => $values['body_markdown'],
                'description' => $values['description'],
                'state' => $values['state'],
                'placement' => $values['placement'],
                'schema_type' => $values['schema_type'],
                'tags' => $values['tags'],
                'published_at' => $this->publishedAt($request),
                'password_hash' => $this->passwordHash($request, $values['state'], null),
                'author_id' => $author['id'],
                'author_name' => $author['name'],
                'video_path' => $this->videoPath($request),
                'show_contact_form' => $this->showContactForm($request),
            ]);
        } catch (InvalidArgumentException $exception) {
            if (!in_array($values['state'], self::STATES, true)) {
                $values['state'] = 'draft';
            }

            return $this->renderForm(422, $current, $exception->getMessage(), $values, null);
        }

        return $this->redirect($this->pagesUrl() . '&notice=saved', 303);
    }

    public function edit(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() === 'GET') {
            $page = $this->pageFromQuery($request);
            if ($page === null) {
                return $this->notFound();
            }

            return $this->renderForm(200, $current, null, null, $page);
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->renderForm(400, $current, 'Invalid request.', $this->values($request), $this->pageFromPost($request));
        }

        $page = $this->pageFromPost($request);
        if ($page === null || !is_string($request->post('revision')) || preg_match('/^\d+$/', $request->post('revision')) !== 1) {
            return $this->renderForm(422, $current, 'Invalid page reference.', $this->values($request), $page);
        }
        if (!$this->knownIntent($request)) {
            return $this->renderForm(422, $current, 'Choose Save or Save and publish.', $this->values($request), $page);
        }
        $revision = (int) $request->post('revision');
        $values = $this->values($request);

        try {
            $values['state'] = $this->stateOrFail($values['state']);
            $author = $this->author($values['author_id'], $current, $page);
            $this->pages->update($page['id'], $revision, [
                'title' => $values['title'],
                'slug' => $values['slug'],
                'body_markdown' => $values['body_markdown'],
                'description' => $values['description'],
                'state' => $values['state'],
                'placement' => $values['placement'],
                'schema_type' => $values['schema_type'],
                'tags' => $values['tags'],
                'published_at' => $this->publishedAt($request, $page),
                'password_hash' => $this->passwordHash($request, $values['state'], $page['password_hash']),
                'author_id' => $author['id'],
                'author_name' => $author['name'],
                'video_path' => $this->videoPath($request),
                'show_contact_form' => $this->showContactForm($request),
            ]);
        } catch (WriteConflict $exception) {
            return $this->renderForm(409, $current, $exception->getMessage(), null, $this->pages->getById($page['id']));
        } catch (InvalidArgumentException $exception) {
            $fresh = $this->pages->getById($page['id']);
            // A rejected submission must not present a state the page is not in:
            // with an unknown value no <option> matches, the browser highlights
            // Draft and the next click would silently write it.
            if (!in_array($values['state'], self::STATES, true)) {
                $values['state'] = $fresh['state'] ?? 'draft';
            }

            return $this->renderForm(422, $current, $exception->getMessage(), $values, $fresh);
        }

        return $this->redirect($this->pagesUrl() . '&notice=saved', 303);
    }

    public function trash(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->renderIndex(400, $current, 'Invalid request.');
        }
        $page = $this->pageFromPost($request);
        $revision = $request->post('revision');
        if ($page === null || !is_string($revision) || preg_match('/^\d+$/', $revision) !== 1) {
            return $this->renderIndex(422, $current, 'Invalid page reference.');
        }

        try {
            $this->pages->transition($page['id'], (int) $revision, 'trash');
        } catch (WriteConflict $exception) {
            return $this->renderIndex(409, $current, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->pagesUrl(), 303);
    }

    public function restore(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->renderIndex(400, $current, 'Invalid request.');
        }

        $page = $this->pageFromPost($request);
        $revision = $request->post('revision');
        if ($page === null || !is_string($revision) || preg_match('/\A\d+\z/D', $revision) !== 1) {
            return $this->renderIndex(422, $current, 'Invalid page reference.');
        }

        if ($page['state'] !== 'trash') {
            return $this->renderIndex(422, $current, 'Only a trashed page can be restored.');
        }

        try {
            // Restore always lands on draft: republishing a page the owner
            // trashed on purpose would be a content-integrity defect.
            $this->pages->transition($page['id'], (int) $revision, 'draft');
        } catch (WriteConflict $exception) {
            return $this->renderIndex(409, $current, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->pagesUrl() . '&notice=restored', 303);
    }

    public function delete(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() === 'GET') {
            $page = $this->pageFromQuery($request);
            if ($page === null) {
                return $this->notFound();
            }

            return $this->renderDelete(200, $current, $page);
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->renderDelete(400, $current, $this->pageFromPost($request), 'Invalid request.');
        }
        $page = $this->pageFromPost($request);
        $revision = $request->post('revision');
        $confirmation = $request->post('confirmation');
        if ($page === null || !is_string($revision) || preg_match('/^\d+$/', $revision) !== 1) {
            return $this->notFound();
        }
        if (!is_string($confirmation) || !hash_equals($page['title'], $confirmation)) {
            return $this->renderDelete(422, $current, $page, 'Type the exact page title to confirm permanent deletion.');
        }

        try {
            $this->pages->delete($page['id'], (int) $revision);
        } catch (WriteConflict $exception) {
            return $this->renderDelete(409, $current, $this->pages->getById($page['id']), $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->renderDelete(422, $current, $this->pages->getById($page['id']), $exception->getMessage());
        }

        return $this->redirect($this->pagesUrl(), 303);
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

    /** @return Page|null */
    private function pageFromQuery(Request $request): ?array
    {
        $id = $request->query('id');

        return is_string($id) && $this->validId($id) ? $this->pages->getById($id) : null;
    }

    /** @return Page|null */
    private function pageFromPost(Request $request): ?array
    {
        $id = $request->post('page_id');

        return is_string($id) && $this->validId($id) ? $this->pages->getById($id) : null;
    }

    /**
     * @param Admin $current
     * @param array{title: string, slug: string, body_markdown: string, description: string, video_path: string, show_contact_form: int, placement: string, state: string, schema_type: string, tags: list<string>, author_id: string, published_at: string}|null $values
     * @param Page|null $page
     */
    private function renderForm(int $status, array $current, ?string $error = null, ?array $values = null, ?array $page = null): Response
    {
        $editing = $page !== null;
        $values ??= $editing
            ? [
                'title' => $page['title'],
                'slug' => $page['slug'],
                'body_markdown' => $page['body_markdown'],
                'description' => $page['description'],
                'video_path' => $page['video_path'] ?? '',
                'show_contact_form' => $page['show_contact_form'],
                'placement' => (string) $page['placement'],
                'state' => $page['state'],
                'schema_type' => $page['schema_type'],
                'tags' => $page['tags'],
                'author_id' => $page['author_id'] ?? '',
                'published_at' => $page['published_at'] === null ? '' : substr($page['published_at'], 0, 10),
            ]
            : [
                'title' => '', 'slug' => '', 'body_markdown' => '', 'description' => '', 'video_path' => '',
                'show_contact_form' => 0, 'placement' => 'home', 'state' => 'draft', 'schema_type' => 'WebPage',
                'tags' => [], 'author_id' => '', 'published_at' => '',
            ];
        $admins = $this->admins->all();
        $media = $this->media === null ? [] : $this->media->list();
        $previewUrl = $page !== null ? $this->url('page-preview') . '&id=' . $page['id'] : null;
        $newPageMessage = 'Save the draft first, then preview.';
        $pageId = $editing ? $page['id'] : null;
        $revision = $editing ? $page['revision'] : null;
        $csrfToken = $this->csrf->token();
        $formAction = $editing ? $this->url('page-edit') : $this->url('page-create');
        $pagesUrl = $this->pagesUrl();
        $logoutUrl = $this->url('logout');
        $trashUrl = $editing ? $this->url('page-trash') : null;
        $deleteUrl = $editing ? $this->url('page-delete') . '&id=' . $pageId : null;
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/page-form.php';
        $render = static function () use (
            $template, $csrfToken, $formAction, $pagesUrl, $logoutUrl, $trashUrl, $deleteUrl,
            $basePath, $error, $values, $pageId, $revision, $admins, $previewUrl, $newPageMessage, $media
        ): string {
            $title = $values['title'];
            $slug = $values['slug'];
            $bodyMarkdown = $values['body_markdown'];
            $description = $values['description'];
            $videoPath = $values['video_path'];
            $showContactForm = $values['show_contact_form'];
            $placement = $values['placement'];
            $state = $values['state'];
            $schemaType = $values['schema_type'];
            $tags = $values['tags'];
            $authorId = $values['author_id'];
            $publishedAtIso = $values['published_at'];
            $mediaItems = $media;
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render page form.');
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

    /** @param Admin $current */
    private function renderIndex(int $status, array $current, ?string $error = null, ?string $notice = null, ?string $state = null): Response
    {
        $pages = $this->pages->listAdmin($state);
        $counts = $this->pages->stateCounts();
        $activeState = $state;
        $csrfToken = $this->csrf->token();
        $newPageUrl = $this->url('page-create');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $editUrlPrefix = $this->url('page-edit') . '&id=';
        $restoreUrl = $this->url('page-restore');
        $pagesUrl = $this->pagesUrl();
        $template = dirname(__DIR__, 2) . '/themes/admin/pages.php';
        $render = static function () use (
            $template, $pages, $csrfToken, $newPageUrl, $accountsUrl, $logoutUrl, $basePath,
            $editUrlPrefix, $error, $notice, $counts, $activeState, $restoreUrl, $pagesUrl
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render page list.');
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

    /**
     * @param Admin $current
     * @param Page|null $page
     */
    private function renderDelete(int $status, array $current, ?array $page, ?string $error = null): Response
    {
        if ($page === null) {
            return $this->notFound();
        }
        $csrfToken = $this->csrf->token();
        $deleteUrl = $this->url('page-delete');
        $pagesUrl = $this->pagesUrl();
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $pageId = $page['id'];
        $pageTitle = $page['title'];
        $revision = $page['revision'];
        $template = dirname(__DIR__, 2) . '/themes/admin/delete-page.php';
        $render = static function () use (
            $template, $csrfToken, $deleteUrl, $pagesUrl, $logoutUrl, $basePath, $pageId, $pageTitle, $revision, $error
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render deletion confirmation.');
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

    /**
     * Raw, echo-safe form values. The access password is deliberately absent: this
     * array is fed straight back into the form after a validation error.
     *
     * @return array{title: string, slug: string, body_markdown: string, description: string, video_path: string, show_contact_form: int, placement: string, state: string, schema_type: string, tags: list<string>, author_id: string, published_at: string}
     */
    private function values(Request $request): array
    {
        $title = $request->post('title');
        $slug = $request->post('slug');
        $body = $request->post('body_markdown');
        $description = $request->post('description');
        $video = $request->post('video_path');
        $author = $request->post('author_id');
        $date = $request->post('published_at');

        return [
            'title' => is_string($title) ? $title : '',
            'slug' => is_string($slug) ? $slug : '',
            'body_markdown' => is_string($body) ? $body : '',
            'description' => is_string($description) ? $description : '',
            'video_path' => is_string($video) ? $video : '',
            'show_contact_form' => $request->post('show_contact_form') === null ? 0 : 1,
            'placement' => $this->placement($request),
            'state' => $this->state($request),
            'schema_type' => $this->schemaType($request),
            'tags' => $this->tags($request),
            'author_id' => is_string($author) ? $author : '',
            'published_at' => is_string($date) ? trim($date) : '',
        ];
    }

    /** @throws InvalidArgumentException when the submitted state is not one of the four */
    private function stateOrFail(string $state): string
    {
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('State must be published, private, draft, or trash.');
        }

        return $state;
    }

    private function knownIntent(Request $request): bool
    {
        $intent = $request->post('intent');

        return $intent === null || (is_string($intent) && in_array($intent, self::INTENTS, true));
    }

    private function state(Request $request): string
    {
        // "Save and publish" wins over the select, so a contradictory pair
        // (state=draft + intent=publish) resolves the same way every time.
        if ($request->post('intent') === 'publish') {
            return 'published';
        }
        $state = $request->post('state');
        if (!is_string($state) || $state === '') {
            // Legacy two-button form.
            $intent = $request->post('intent');

            return is_string($intent) && $intent === 'publish' ? 'published' : 'draft';
        }

        // An unknown value is carried through so the caller can reject it; it is
        // never coerced, because silently writing "archived" as a draft would
        // publish-or-hide content the caller did not choose.
        return $state;
    }

    private function schemaType(Request $request): string
    {
        $value = $request->post('schema_type');

        return in_array($value, self::SCHEMA_TYPES, true) ? (string) $value : 'WebPage';
    }

    /** @return list<string> */
    private function tags(Request $request): array
    {
        $raw = $request->post('tags');
        if (!is_string($raw)) {
            return [];
        }
        $tags = [];
        foreach (explode(',', $raw) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param Admin $current
     * @param Page|null $existing
     * @return array{id: ?string, name: string}
     */
    private function author(string $authorId, array $current, ?array $existing = null): array
    {
        if ($authorId !== '') {
            $admin = $this->validId($authorId) ? $this->admins->findById($authorId) : null;
            if ($admin !== null) {
                return ['id' => $admin['id'], 'name' => $admin['display_name']];
            }
        }
        if ($existing !== null) {
            return ['id' => $existing['author_id'], 'name' => $existing['author_name']];
        }

        return ['id' => $current['id'], 'name' => $current['display_name']];
    }

    /**
     * An empty field keeps the date the page already has. A filled one is a
     * calendar date in UTC; a date that names the same day as the stored value
     * keeps the stored instant, so saving the form does not destroy the
     * time-of-day that drives homepage, feed and metadata ordering.
     *
     * @param Page|null $existing
     */
    private function publishedAt(Request $request, ?array $existing = null): ?string
    {
        $value = $request->post('published_at');
        if (!is_string($value) || trim($value) === '') {
            return $existing === null ? null : $existing['published_at'];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
        // createFromFormat rolls 2026-02-31 into March rather than failing, so
        // compare the round-trip and reject anything that is not that exact day.
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('Publication date must be a calendar date.');
        }

        if ($existing !== null && is_string($existing['published_at'])
            && substr($existing['published_at'], 0, 10) === $date->format('Y-m-d')
        ) {
            return $existing['published_at'];
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }

    private function passwordHash(Request $request, string $state, ?string $existingHash): ?string
    {
        $plain = $request->post('password');
        $plain = is_string($plain) ? $plain : '';

        if ($plain !== '') {
            if (strlen($plain) < 8 || strlen($plain) > 4096) {
                throw new InvalidArgumentException('Access password must be between 8 and 4096 characters.');
            }

            return password_hash($plain, PASSWORD_DEFAULT);
        }

        if ($state === 'private' && $existingHash === null) {
            throw new InvalidArgumentException('A private page needs an access password.');
        }

        // Trash keeps the hash so a restore can re-privatise without inventing a
        // new password; every other state drops protection it no longer needs.
        return in_array($state, ['private', 'trash'], true) ? $existingHash : null;
    }

    private function placement(Request $request): string
    {
        $value = $request->post('placement');

        return in_array($value, ['none', 'home', 'featured'], true) ? (string) $value : 'home';
    }

    private function videoPath(Request $request): ?string
    {
        $value = $request->post('video_path');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function showContactForm(Request $request): int
    {
        return $request->post('show_contact_form') === null ? 0 : 1;
    }

    private function validId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/D', $id) === 1;
    }

    private function methodNotAllowed(string $allow): Response
    {
        return (new Response(
            405,
            SecurityHeaders::forConfig($this->config) + ['Allow' => $allow],
            'Method not allowed.',
        ))->asSensitive();
    }

    private function notFound(): Response
    {
        return (new Response(404, SecurityHeaders::forConfig($this->config), 'Not found.'))->asSensitive();
    }

    private function redirect(string $location, int $status = 302): Response
    {
        return (new Response($status, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function pagesUrl(): string
    {
        return $this->url('pages');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
