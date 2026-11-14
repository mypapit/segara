<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Blocks\BlockTypes;
use Segara\Auth\Session;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Media\MediaRepository;
use Segara\Menus\MenuRepository;
use Segara\Storage\WriteConflict;
use Segara\Support\Html;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class BlockController
{
    private const LOCATIONS = ['left', 'right', 'footer'];

    public function __construct(
        private readonly Config $config,
        private readonly BlockRepository $blocks,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ?BlockRenderer $preview = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?MenuRepository $menus = null,
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

        // The "Add a block" form is a GET form: it comes back as
        // ?action=blocks&location=…&type=…, and that second step must show the
        // settings for the chosen pair. Dropping these two silently returned the
        // administrator to the default type, so the whole flow did nothing.
        $requestedLocation = $request->query('location');
        $location = is_string($requestedLocation) && in_array($requestedLocation, self::LOCATIONS, true)
            ? $requestedLocation
            : null;

        $requestedType = $request->query('type');
        $type = null;
        if (is_string($requestedType) && $requestedType !== '' && array_key_exists($requestedType, BlockTypes::all())) {
            $type = $location === null || BlockTypes::allows($requestedType, $location) ? $requestedType : null;
        }

        return $this->render(
            200,
            $current,
            null,
            is_string($notice) ? $notice : null,
            null,
            $type,
            $location,
        );
    }

    public function create(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $type = $request->post('type');
        $location = $request->post('location');
        $submittedType = is_string($type) ? $type : 'quick-facts';
        $submittedLocation = is_string($location) ? $location : 'left';
        $submitted = $this->settingsFromPost($request, $submittedType);

        try {
            if (!is_string($type) || !is_string($location)) {
                throw new InvalidArgumentException('Choose a location and a block type.');
            }
            if (!BlockTypes::allows($type, $location)) {
                throw new InvalidArgumentException(BlockTypes::label($type) . ' cannot be placed there.');
            }
            $this->blocks->create([
                'location' => $location,
                'type' => $type,
                'settings' => $submitted,
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage(), null, null, $submittedType, $submittedLocation, $submitted);
        }

        return $this->redirect($this->blocksUrl() . '&notice=saved', 303);
    }

    public function edit(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }

        $id = $request->method() === 'GET' ? $request->query('id') : $request->post('block_id');
        if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return $this->render(422, $current, 'Invalid block reference.');
        }
        $block = $this->blocks->getById($id);
        if ($block === null) {
            return $this->render(422, $current, 'That block no longer exists.');
        }

        if ($request->method() === 'GET') {
            return $this->render(200, $current, null, null, $block);
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('GET, POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.', null, $block);
        }

        $revision = $request->post('revision');
        if (!is_string($revision) || preg_match('/\A\d+\z/D', $revision) !== 1) {
            return $this->render(422, $current, 'Invalid block revision.', null, $block);
        }

        $type = $request->post('type');
        $location = $request->post('location');
        $submittedType = is_string($type) ? $type : $block['type'];
        $submittedLocation = is_string($location) ? $location : $block['location'];
        $submitted = $this->settingsFromPost($request, $submittedType);

        try {
            if (!is_string($type) || !is_string($location)) {
                throw new InvalidArgumentException('Choose a location and a block type.');
            }
            if (!BlockTypes::allows($type, $location)) {
                throw new InvalidArgumentException(BlockTypes::label($type) . ' cannot be placed there.');
            }
            $this->blocks->update($id, (int) $revision, [
                'location' => $location,
                'type' => $type,
                'settings' => $submitted,
            ]);
        } catch (WriteConflict $conflict) {
            return $this->render(409, $current, 'Someone else changed this block. Reload and try again.', null, $this->blocks->getById($id));
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage(), null, $block, $submittedType, $submittedLocation, $submitted);
        }

        return $this->redirect($this->blocksUrl() . '&notice=saved', 303);
    }

    public function confirmDelete(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        $id = $request->query('id');
        if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return $this->render(422, $current, 'Invalid block reference.');
        }
        $block = $this->blocks->getById($id);
        if ($block === null) {
            return $this->render(422, $current, 'That block no longer exists.');
        }

        $csrfToken = $this->csrf->token();
        $deleteUrl = $this->url('block-delete');
        $blocksUrl = $this->blocksUrl();
        $basePath = $this->config->basePath;
        $blockId = $block['id'];
        $label = BlockTypes::label($block['type']);
        $location = ucfirst($block['location']);
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $template = dirname(__DIR__, 2) . '/themes/admin/delete-block.php';
        $render = static function () use ($template, $csrfToken, $deleteUrl, $blocksUrl, $basePath, $blockId, $label, $location, $accountsUrl, $logoutUrl): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render block deletion.');
                }

                return $body;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        };

        return (new Response(
            200,
            SecurityHeaders::forConfig($this->config)
                + ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow'],
            $render(),
        ))->asSensitive();
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFromPost(Request $request, string $type): array
    {
        $settings = $request->post('settings');
        if (!is_array($settings)) {
            return [];
        }

        $text = static function (mixed $value): string {
            return is_string($value) ? trim($value) : '';
        };

        return match ($type) {
            'menu' => ['menu_id' => $text($settings['menu_id'] ?? null)],
            'images' => [
                'title' => $text($settings['title'] ?? null),
                'image_ids' => array_values(array_filter(
                    is_array($settings['image_ids'] ?? null) ? $settings['image_ids'] : [],
                    static fn(mixed $id): bool => is_string($id) && $id !== ''
                )),
            ],
            'rss' => ['title' => $text($settings['title'] ?? null)],
            'social' => [
                'title' => $text($settings['title'] ?? null),
                'networks' => $this->rows($settings['networks'] ?? null, ['provider', 'url']),
            ],
            'external-links' => [
                'title' => $text($settings['title'] ?? null),
                'links' => $this->rows($settings['links'] ?? null, ['label', 'url']),
            ],
            'quick-facts' => [
                'title' => $text($settings['title'] ?? null),
                'facts' => $this->rows($settings['facts'] ?? null, ['text', 'bullet']),
            ],
            'custom-inline' => [
                'title' => $text($settings['title'] ?? null),
                'html' => $text($settings['html'] ?? null),
            ],
            'copyright' => ['text' => $text($settings['text'] ?? null)],
            'address' => ['text' => $text($settings['text'] ?? null)],
            'blurb' => [
                'title' => $text($settings['title'] ?? null),
                'text' => $text($settings['text'] ?? null),
                'image_id' => $text($settings['image_id'] ?? null),
            ],
            default => [],
        };
    }

    /**
     * Repeating rows: drop the ones left blank and reindex, because array_is_list
     * fails on the gaps a removed row leaves behind.
     *
     * @param list<string> $fields
     * @return list<array<string, string>>
     */
    private function rows(mixed $input, array $fields): array
    {
        if (!is_array($input)) {
            return [];
        }

        $rows = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $built = [];
            $empty = true;
            foreach ($fields as $field) {
                $value = is_string($row[$field] ?? null) ? trim($row[$field]) : '';
                $built[$field] = $value;
                if ($value !== '') {
                    $empty = false;
                }
            }
            if (!$empty) {
                $rows[] = $built;
            }
        }

        return $rows;
    }

    public function delete(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $id = $request->post('block_id');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid block reference.');
        }
        if ($request->post('confirmation') !== 'delete') {
            return $this->render(422, $current, 'Confirm the deletion before removing a block.');
        }
        try {
            $this->blocks->delete($id);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->blocksUrl(), 303);
    }

    public function reorder(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $location = $request->post('location');
        $order = $request->post('order');
        $decoded = is_string($order) ? json_decode($order, true) : null;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return $this->render(422, $current, 'Order must be a JSON array of block IDs.');
        }

        $ids = [];
        foreach ($decoded as $entry) {
            if (!is_string($entry)) {
                return $this->render(422, $current, 'Order must be a JSON array of block IDs.');
            }
            $ids[] = $entry;
        }

        try {
            $this->blocks->reorder(is_string($location) ? $location : '', $ids);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->blocksUrl(), 303);
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
     * @param array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}|null $editing
     * @param array<string, mixed>|null $submittedSettings
     */
    private function render(int $status, array $current, ?string $error = null, ?string $notice = null, ?array $editing = null, ?string $selectedType = null, ?string $selectedLocation = null, ?array $submittedSettings = null): Response
    {
        $blocksByLocation = [
            'left' => $this->blocks->listByLocation('left'),
            'right' => $this->blocks->listByLocation('right'),
            'footer' => $this->blocks->listByLocation('footer'),
        ];
        $previews = [];
        foreach ($blocksByLocation as $locationBlocks) {
            foreach ($locationBlocks as $block) {
                $previews[$block['id']] = $this->preview === null
                    ? $this->fallbackPreview($block)
                    : $this->preview->render($block);
            }
        }

        $csrfToken = $this->csrf->token();
        $reorderUrl = $this->url('block-reorder');
        $createUrl = $this->url('block-create');
        $deleteUrl = $this->url('block-delete');
        $confirmDeleteUrl = $this->url('block-delete-confirm');
        $editUrl = $this->url('block-edit');
        $types = BlockTypes::all();
        $mediaOptions = $this->media?->list() ?? [];
        $menuOptions = $this->menus?->listMenus() ?? [];
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/blocks.php';

        $render = static function () use (
            $template, $blocksByLocation, $previews, $csrfToken, $reorderUrl, $createUrl,
            $deleteUrl, $confirmDeleteUrl, $editUrl, $types, $mediaOptions, $menuOptions, $accountsUrl, $logoutUrl,
            $basePath, $error, $notice, $editing, $selectedType, $selectedLocation, $submittedSettings
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render block administration.');
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

    /** @param array{id: string, location: string, position: int, type: string, settings_json: string, revision: int} $block */
    private function fallbackPreview(array $block): string
    {
        return '<div class="block block--preview"><strong>' . Html::escape($block['type']) . '</strong>'
            . ' <span>#' . $block['position'] . '</span></div>';
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

    private function blocksUrl(): string
    {
        return $this->url('blocks');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
