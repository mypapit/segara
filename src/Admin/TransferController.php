<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use RuntimeException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Pages\PageRepository;
use Segara\Transfer\WxrExport;
use Segara\Transfer\WxrImport;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class TransferController
{
    private const MAX_IMPORT_BYTES = 10485760;

    public function __construct(
        private readonly Config $config,
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

        return $this->render(200, $current);
    }

    public function export(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'GET') {
            return $this->methodNotAllowed('GET');
        }

        $xml = (new WxrExport($this->config, $this->pages))->export();

        return (new Response(200, SecurityHeaders::forConfig($this->config) + [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="segara-export.xml"',
        ], $xml))->asSensitive();
    }

    public function import(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Your session expired. Please try again.');
        }

        $file = $request->files('import_file');
        if ($file === null) {
            return $this->render(400, $current, 'No file was uploaded.');
        }
        $error = $this->uploadError($file);
        if ($error !== null) {
            return $this->render(400, $current, $error);
        }
        $tmp = $file['tmp_name'];
        if (!is_file($tmp)) {
            return $this->render(400, $current, 'Uploaded file is missing.');
        }
        $xml = file_get_contents($tmp);
        if ($xml === false) {
            return $this->render(400, $current, 'Uploaded file could not be read.');
        }
        try {
            $result = (new WxrImport($this->pages))->import($xml, self::MAX_IMPORT_BYTES);
        } catch (InvalidArgumentException $exception) {
            return $this->render(400, $current, $exception->getMessage());
        }

        return $this->render(200, $current, null, $this->summary($result));
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
    private function render(int $status, array $current, ?string $error = null, ?string $result = null): Response
    {
        $csrfToken = $this->csrf->token();
        $exportUrl = $this->url('transfer-export');
        $importUrl = $this->url('transfer-import');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/transfer.php';
        $render = static function () use (
            $template,
            $csrfToken,
            $exportUrl,
            $importUrl,
            $accountsUrl,
            $logoutUrl,
            $basePath,
            $error,
            $result
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new RuntimeException('Unable to render transfer page.');
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

    /** @param array{imported: int, conflicts: list<string>, errors: list<string>} $result */
    private function summary(array $result): string
    {
        $parts = ['Imported ' . $result['imported'] . ' page(s).'];
        if ($result['conflicts'] !== []) {
            $parts[] = 'Slug conflicts skipped: ' . implode(', ', $result['conflicts']) . '.';
        }
        if ($result['errors'] !== []) {
            $parts[] = 'Errors: ' . implode('; ', $result['errors']) . '.';
        }

        return implode(' ', $parts);
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
    private function uploadError(array $file): ?string
    {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return 'No file was uploaded.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'Import file is too large.';
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed.';
        }
        if ($file['size'] > self::MAX_IMPORT_BYTES) {
            return 'Import file is too large.';
        }

        return null;
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
