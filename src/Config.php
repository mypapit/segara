<?php

declare(strict_types=1);

namespace Segara;

use InvalidArgumentException;

final class Config
{
    private function __construct(
        public readonly string $baseUrl,
        public readonly string $basePath,
        public readonly string $environment,
        public readonly int $sessionIdleTimeout,
        public readonly int $sessionAbsoluteTimeout,
        public readonly bool $cacheEnabled,
        public readonly ?string $captchaProvider,
        public readonly ?string $captchaSiteKey,
        public readonly ?string $captchaSecretKey,
        public readonly string $authBasicRealm,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $session = self::arrayValue($values, 'session');
        $idleTimeout = self::positiveInteger($session, 'idle_timeout', 1800);
        $absoluteTimeout = self::positiveInteger($session, 'absolute_timeout', 28800);
        if ($absoluteTimeout < $idleTimeout) {
            throw new InvalidArgumentException('session.absolute_timeout must not be shorter than the idle timeout.');
        }

        $cache = self::arrayValue($values, 'cache');
        $cacheEnabled = $cache['enabled'] ?? false;
        if (!is_bool($cacheEnabled)) {
            throw new InvalidArgumentException('cache.enabled must be a boolean.');
        }

        [$captchaProvider, $captchaSiteKey, $captchaSecretKey] = self::captcha($values);
        $auth = self::arrayValue($values, 'auth');
        $basicRealm = $auth['basic_realm'] ?? 'Segara Administration';
        if (!is_string($basicRealm) || $basicRealm === '' || strlen($basicRealm) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $basicRealm) === 1
        ) {
            throw new InvalidArgumentException('auth.basic_realm must be a non-empty single-line string of at most 200 bytes.');
        }

        return new self(
            self::canonicalOrigin($values['base_url'] ?? null),
            self::basePath($values['base_path'] ?? ''),
            self::environment($values['environment'] ?? 'production'),
            $idleTimeout,
            $absoluteTimeout,
            $cacheEnabled,
            $captchaProvider,
            $captchaSiteKey,
            $captchaSecretKey,
            $basicRealm,
        );
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Configuration file is missing or not readable.');
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new InvalidArgumentException('Configuration file must return an array.');
        }

        /** @var array<string, mixed> $values */
        return self::fromArray($values);
    }

    private static function canonicalOrigin(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('base_url must be a valid HTTP or HTTPS canonical origin.');
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path']) !== []
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || (isset($parts['path']) && $parts['path'] !== '/')
        ) {
            throw new InvalidArgumentException('base_url must contain only an HTTP or HTTPS scheme, host, and optional port.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port < 1) {
            throw new InvalidArgumentException('base_url contains an invalid port.');
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if ($port !== null) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function basePath(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            throw new InvalidArgumentException('base_path must be a string of at most 2048 bytes.');
        }
        if ($value === '') {
            return '';
        }

        if ($value === '/' || !str_starts_with($value, '/') || str_ends_with($value, '/')
            || str_contains($value, '//') || str_contains($value, '\\')
            || str_contains($value, '?') || str_contains($value, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1
        ) {
            throw new InvalidArgumentException('base_path must be empty or an absolute, normalized URL path without a trailing slash.');
        }

        foreach (explode('/', substr($value, 1)) as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || $decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/') || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            ) {
                throw new InvalidArgumentException('base_path contains an invalid path segment.');
            }
        }

        return $value;
    }

    private static function environment(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['production', 'development', 'test'], true)) {
            throw new InvalidArgumentException('environment must be production, development, or test.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function arrayValue(array $values, string $key): array
    {
        $value = $values[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException($key . ' must be an array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function positiveInteger(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('session.' . $key . ' must be a positive integer.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array{?string, ?string, ?string}
     */
    private static function captcha(array $values): array
    {
        $captcha = self::arrayValue($values, 'captcha');
        $provider = $captcha['provider'] ?? null;
        $siteKey = $captcha['site_key'] ?? null;
        $secretKey = $captcha['secret_key'] ?? null;

        if ($provider === null) {
            if ($siteKey !== null || $secretKey !== null) {
                throw new InvalidArgumentException('captcha keys require a provider.');
            }

            return [null, null, null];
        }

        if (!is_string($provider) || !in_array($provider, ['recaptcha', 'turnstile'], true)) {
            throw new InvalidArgumentException('captcha.provider must be recaptcha, turnstile, or null.');
        }
        foreach (['site_key' => $siteKey, 'secret_key' => $secretKey] as $name => $key) {
            if (!is_string($key) || $key === '' || strlen($key) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            ) {
                throw new InvalidArgumentException('captcha.' . $name . ' must be configured with the provider.');
            }
        }

        /** @var string $siteKey */
        /** @var string $secretKey */
        return [$provider, $siteKey, $secretKey];
    }
}
