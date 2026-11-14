<?php

declare(strict_types=1);

namespace Segara\Auth;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Segara\Config;

final class Session
{
    private const CREATED_AT = '_segara_created_at';
    private const LAST_ACTIVITY = '_segara_last_activity';
    private const ADMIN_ID = '_segara_admin_id';
    private const AUTH_EPOCH = '_segara_auth_epoch';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|callable(): int $clock */
    public function __construct(
        private readonly Config $config,
        private readonly string $savePath,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->enforceTimeouts();
            return;
        }

        $this->prepareSavePath();
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('segara_session');
        session_save_path($this->savePath);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->config->basePath === '' ? '/' : $this->config->basePath . '/',
            'secure' => parse_url($this->config->baseUrl, PHP_URL_SCHEME) === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }

        $now = $this->now();
        if (!isset($_SESSION[self::CREATED_AT], $_SESSION[self::LAST_ACTIVITY])) {
            $this->initializeTimestamps($now);
        } else {
            $this->enforceTimeouts();
        }
    }

    public function id(): string
    {
        $this->ensureStarted();
        $id = session_id();
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Active session has no identifier.');
        }

        return $id;
    }

    public function login(string $adminId, int $authEpoch): void
    {
        if ($adminId === '' || strlen($adminId) > 200 || $authEpoch < 0) {
            throw new InvalidArgumentException('Invalid administrator session identity.');
        }
        $this->ensureStarted();
        $this->regenerate();
        $_SESSION[self::ADMIN_ID] = $adminId;
        $_SESSION[self::AUTH_EPOCH] = $authEpoch;
    }

    public function logout(): void
    {
        $this->ensureStarted();
        $cookie = session_get_cookie_params();
        $sessionName = session_name();
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session identifier at logout.');
        }
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the session at logout.');
        }
        session_id('');
        if (ini_get('session.use_cookies') === '1' && is_string($sessionName)) {
            setcookie($sessionName, '', [
                'expires' => $this->now() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'],
            ]);
        }
    }

    public function privilegeChanged(): void
    {
        $this->ensureStarted();
        $this->regenerate();
    }

    public function adminId(): ?string
    {
        $this->ensureStarted();
        $this->enforceTimeouts();
        $adminId = $_SESSION[self::ADMIN_ID] ?? null;

        return is_string($adminId) ? $adminId : null;
    }

    public function hasValidAuthentication(int $currentAuthEpoch): bool
    {
        $this->ensureStarted();
        if (!$this->enforceTimeouts()) {
            return false;
        }

        $adminId = $_SESSION[self::ADMIN_ID] ?? null;
        $sessionEpoch = $_SESSION[self::AUTH_EPOCH] ?? null;
        if (!is_string($adminId) || $adminId === '' || !is_int($sessionEpoch)) {
            return false;
        }
        if ($sessionEpoch !== $currentAuthEpoch) {
            $this->invalidate();
            return false;
        }

        $_SESSION[self::LAST_ACTIVITY] = $this->now();
        return true;
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session identifier.');
        }
        unset($_SESSION[Csrf::SESSION_KEY]);
        $_SESSION[self::LAST_ACTIVITY] = $this->now();
    }

    private function enforceTimeouts(): bool
    {
        $createdAt = $_SESSION[self::CREATED_AT] ?? null;
        $lastActivity = $_SESSION[self::LAST_ACTIVITY] ?? null;
        if (!is_int($createdAt) || !is_int($lastActivity)) {
            $this->invalidate();
            return false;
        }

        $now = $this->now();
        if ($now < $createdAt || $now < $lastActivity
            || $now - $lastActivity >= $this->config->sessionIdleTimeout
            || $now - $createdAt >= $this->config->sessionAbsoluteTimeout
        ) {
            $this->invalidate();
            return false;
        }

        return true;
    }

    private function invalidate(): void
    {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to invalidate the session identifier.');
        }
        $this->initializeTimestamps($this->now());
    }

    private function initializeTimestamps(int $now): void
    {
        $_SESSION[self::CREATED_AT] = $now;
        $_SESSION[self::LAST_ACTIVITY] = $now;
    }

    private function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function prepareSavePath(): void
    {
        if ($this->savePath === '' || !str_starts_with($this->savePath, '/')
            || str_contains($this->savePath, "\0") || is_link($this->savePath)
        ) {
            throw new InvalidArgumentException('Session save path must be an absolute private directory path.');
        }
        if (!is_dir($this->savePath)
            && !mkdir($this->savePath, 0700, true)
            && !is_dir($this->savePath)
        ) {
            throw new RuntimeException('Unable to create the session save directory.');
        }
        $realPath = realpath($this->savePath);
        if ($realPath === false || !is_dir($realPath) || !is_writable($realPath)) {
            throw new RuntimeException('Session save directory is unavailable or unwritable.');
        }
    }
}
