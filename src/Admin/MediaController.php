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
use Segara\Media\ImageUpload;
use Segara\Media\MediaRepository;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class MediaController
{
    public function __construct(
        private readonly Config $config,
        private readonly MediaRepository $media,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ImageUpload $upload,
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

        return $this->render(200, $current);
    }

    /** @param array<string, mixed> $file */
    public function upload(Request $request, array $file): Response
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

        $altText = $request->post('alt_text');
        $decorative = $request->post('decorative') === '1' ? 1 : 0;
        $altText = is_string($altText) ? $altText : '';

        try {
            $stored = $this->upload->store($file);
            $this->media->create([
                'original_name' => $stored['original_name'],
                'stored_path' => $stored['stored_path'],
                'mime' => $stored['mime'],
                'size' => $stored['size'],
                'alt_text' => $altText,
                'decorative' => $decorative,
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->mediaUrl(), 303);
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

        $mediaId = $request->post('media_id');
        if (!is_string($mediaId) || preg_match('/\A[0-9a-f]{32}\z/D', $mediaId) !== 1) {
            return $this->render(422, $current, 'Invalid media reference.');
        }

        try {
            $this->media->delete($mediaId, $this->upload->root());
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->mediaUrl(), 303);
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

    /** @param Admin $current */
    private function render(int $status, array $current, ?string $error = null): Response
    {
        $media = $this->media->list();
        $csrfToken = $this->csrf->token();
        $uploadUrl = $this->url('media-upload');
        $deleteUrl = $this->url('media-delete');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $mediaUrlPrefix = $basePath . '/media.php?id=';
        $template = dirname(__DIR__, 2) . '/themes/admin/media.php';
        $render = static function () use (
            $template, $media, $csrfToken, $uploadUrl, $deleteUrl, $accountsUrl, $logoutUrl, $basePath, $mediaUrlPrefix, $error
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render media library.');
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

    private function mediaUrl(): string
    {
        return $this->url('media');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
