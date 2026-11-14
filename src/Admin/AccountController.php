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

/**
 * @phpstan-import-type Admin from AdminRepository
 * @phpstan-import-type AdminSummary from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 * @psalm-import-type AdminSummary from AdminRepository
 */
final class AccountController
{
    public function __construct(
        private readonly Config $config,
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

        $username = $request->post('username');
        $displayName = $request->post('display_name');
        $password = $request->post('password');
        $confirmation = $request->post('password_confirmation');
        $values = [
            'username' => is_string($username) ? $username : '',
            'display_name' => is_string($displayName) ? $displayName : '',
        ];
        if (!is_string($username) || !is_string($displayName) || !is_string($password) || !is_string($confirmation)) {
            return $this->render(422, $current, 'Enter a valid username, display name, and matching passwords.', $values);
        }
        if (!hash_equals($password, $confirmation)) {
            return $this->render(422, $current, 'The new passwords do not match.', $values);
        }

        try {
            $this->admins->create($username, $displayName, $password);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage(), $values);
        }

        return $this->redirect($this->accountsUrl(), 303);
    }

    public function changePassword(Request $request): Response
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

        $id = $request->post('admin_id');
        $currentPassword = $request->post('current_password');
        $password = $request->post('password');
        $confirmation = $request->post('password_confirmation');
        if (!is_string($id) || !$this->validId($id)
            || !is_string($currentPassword) || !$this->validPasswordShape($currentPassword)
            || !is_string($password) || !$this->validPasswordShape($password) || !is_string($confirmation)
        ) {
            return $this->render(422, $current, 'Enter a valid account and matching passwords.');
        }

        $target = $this->admins->findById($id);
        if ($target === null) {
            return $this->render(404, $current, 'Administrator not found.');
        }
        if ($target['id'] !== $current['id']) {
            return $this->render(403, $current, 'You can only change your own password.');
        }
        if (!password_verify($currentPassword, $current['password_hash'])) {
            return $this->render(422, $current, 'The current password is incorrect.');
        }
        if (!hash_equals($password, $confirmation)) {
            return $this->render(422, $current, 'The new passwords do not match.');
        }

        try {
            $updated = $this->admins->updatePassword($current['id'], $password);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }
        $this->session->login($updated['id'], $updated['auth_epoch']);

        return $this->redirect($this->accountsUrl(), 303);
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

        $id = $request->post('admin_id');
        if (!is_string($id) || !$this->validId($id)) {
            return $this->render(422, $current, 'Invalid administrator account.');
        }
        $target = $this->admins->findById($id);
        if ($target === null) {
            return $this->render(404, $current, 'Administrator not found.');
        }
        if ($target['id'] === $current['id']) {
            return $this->render(422, $current, 'You cannot delete the account you are currently using.');
        }

        try {
            $this->admins->delete($target['id'], $current['id']);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->accountsUrl(), 303);
    }

    public function logout(Request $request): Response
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

        $this->session->logout();

        return $this->redirect($this->loginUrl(), 303);
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
     * @param array{username: string, display_name: string} $createValues
     */
    private function render(
        int $status,
        array $current,
        ?string $error = null,
        array $createValues = ['username' => '', 'display_name' => ''],
    ): Response {
        $administrators = $this->admins->all();
        $currentAdminId = $current['id'];
        $csrfToken = $this->csrf->token();
        $accountsUrl = $this->accountsUrl();
        $createUrl = $this->url('account-create');
        $passwordUrl = $this->url('account-password');
        $deleteUrl = $this->url('account-delete');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/accounts.php';
        $render = static function () use (
            $template,
            $administrators,
            $currentAdminId,
            $csrfToken,
            $accountsUrl,
            $createUrl,
            $passwordUrl,
            $deleteUrl,
            $logoutUrl,
            $basePath,
            $error,
            $createValues,
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render administrator accounts.');
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

    private function validId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/D', $id) === 1;
    }

    private function validPasswordShape(string $password): bool
    {
        $length = strlen($password);
        return $length >= 12 && $length <= 4096 && !str_contains($password, "\0");
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function accountsUrl(): string
    {
        return $this->url('accounts');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
