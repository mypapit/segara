<?php

declare(strict_types=1);

namespace Segara\Admin;

use Segara\Auth\AdminRepository;
use Segara\Auth\CaptchaVerifier;
use Segara\Auth\Csrf;
use Segara\Auth\LoginThrottle;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class AuthController
{
    private const INVALID_CREDENTIALS = 'Invalid credentials.';
    private const THROTTLED = 'Too many attempts. Try again later.';

    public function __construct(
        private readonly Config $config,
        private readonly AdminRepository $admins,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CaptchaVerifier $captcha,
    ) {
    }

    public function login(Request $request): Response
    {
        $this->session->start();
        if ($request->method() === 'GET') {
            if ($this->authenticatedAdmin() !== null) {
                return $this->redirect($this->dashboardUrl());
            }

            return $this->renderLogin();
        }

        if (!$this->csrf->validate($request)) {
            return $this->renderLogin(400, 'Invalid request.');
        }

        $username = $request->post('username');
        $password = $request->post('password');
        $throttleKey = is_string($username) && strlen($username) <= 64 ? $username : '';
        $retryAfter = $this->throttle->retryAfter($throttleKey);
        if ($retryAfter > 0) {
            return $this->renderLogin(429, self::THROTTLED);
        }

        if (!is_string($username) || !is_string($password) || !$this->validCredentialShape($username, $password)) {
            return $this->failedLogin($throttleKey);
        }

        if ($this->config->captchaProvider !== null) {
            $field = $this->config->captchaProvider === 'recaptcha'
                ? 'g-recaptcha-response'
                : 'cf-turnstile-response';
            if (!$this->captcha->verify($request->post($field))) {
                return $this->failedLogin($throttleKey);
            }
        }

        $admin = $this->admins->verifyAndRehashPassword($username, $password);
        if ($admin === null) {
            return $this->failedLogin($throttleKey);
        }

        $this->throttle->reset($throttleKey);
        $this->session->login($admin['id'], $admin['auth_epoch']);

        return $this->redirect($this->dashboardUrl());
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

    private function validCredentialShape(string $username, string $password): bool
    {
        return preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/D', $username) === 1
            && strlen($password) >= 12 && strlen($password) <= 4096 && !str_contains($password, "\0");
    }

    private function failedLogin(string $throttleKey): Response
    {
        if ($this->throttle->recordFailure($throttleKey) > 0) {
            return $this->renderLogin(429, self::THROTTLED);
        }

        return $this->renderLogin(401, self::INVALID_CREDENTIALS);
    }

    private function renderLogin(int $status = 200, ?string $error = null): Response
    {
        $csrfToken = $this->csrf->token();
        $formAction = $this->loginUrl();
        $config = $this->config;
        $template = dirname(__DIR__, 2) . '/themes/admin/login.php';
        $render = static function () use ($template, $csrfToken, $formAction, $config, $error): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render administrator login.');
                }
                return $body;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        };

        return $this->response($status, $render(), $error === null ? [] : ['X-Robots-Tag' => 'noindex'], true);
    }

    /** @param array<string, string> $headers */
    private function response(int $status, string $body, array $headers = [], bool $captcha = false): Response
    {
        $security = SecurityHeaders::forConfig($this->config);
        if ($captcha && $this->config->captchaProvider === 'recaptcha') {
            $security['Content-Security-Policy'] = str_replace(
                "script-src 'self';",
                "script-src 'self' https://www.google.com/recaptcha/api.js https://www.gstatic.com; "
                    . 'frame-src https://www.google.com;',
                $security['Content-Security-Policy'],
            );
        } elseif ($captcha && $this->config->captchaProvider === 'turnstile') {
            $security['Content-Security-Policy'] = str_replace(
                "script-src 'self';",
                "script-src 'self' https://challenges.cloudflare.com/turnstile/v0/api.js; "
                    . 'frame-src https://challenges.cloudflare.com;',
                $security['Content-Security-Policy'],
            );
        }

        return (new Response($status, $security + $headers + ['Content-Type' => 'text/html; charset=UTF-8'], $body))
            ->asSensitive();
    }

    private function redirect(string $location): Response
    {
        return (new Response(302, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }

    private function loginUrl(): string
    {
        return $this->config->basePath . '/admin.php?action=login';
    }

    private function dashboardUrl(): string
    {
        return $this->config->basePath . '/admin.php';
    }
}
