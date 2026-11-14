<?php

declare(strict_types=1);

namespace Segara\Auth;

use RuntimeException;
use Segara\Http\Request;

final class Csrf
{
    public const SESSION_KEY = '_segara_csrf_token';
    public const FORM_FIELD = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $this->session->start();
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public function validate(Request $request): bool
    {
        if ($request->method() !== 'POST') {
            return false;
        }

        $this->session->start();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        $provided = $request->post(self::FORM_FIELD);
        if (!is_string($expected) || !is_string($provided)) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function requireValid(Request $request): void
    {
        if (!$this->validate($request)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }
}
