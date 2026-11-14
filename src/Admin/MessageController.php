<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Contact\MessageRepository;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Support\Html;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class MessageController
{
    public function __construct(
        private readonly Config $config,
        private readonly MessageRepository $messages,
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

    public function markRead(Request $request): Response
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

        $id = $request->post('message_id');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid message reference.');
        }
        try {
            $this->messages->markRead($id);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->messagesUrl(), 303);
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

        $id = $request->post('message_id');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid message reference.');
        }
        try {
            $this->messages->delete($id);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->messagesUrl(), 303);
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
        $messages = $this->messages->list();
        $csrfToken = $this->csrf->token();
        $markReadUrl = $this->url('message-read');
        $deleteUrl = $this->url('message-delete');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/messages.php';
        $render = static function () use (
            $template, $messages, $csrfToken, $markReadUrl, $deleteUrl, $accountsUrl, $logoutUrl, $basePath, $error
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render message inbox.');
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

    private function messagesUrl(): string
    {
        return $this->url('messages');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
