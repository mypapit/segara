<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Segara\Config;

final class ConfigTest extends TestCase
{
    public function testCanonicalConfigurationHasSecureDefaults(): void
    {
        $_SERVER['HTTP_HOST'] = 'attacker.invalid';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'also-attacker.invalid';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        try {
            $config = Config::fromArray([
                'base_url' => 'https://www.example.test/',
                'environment' => 'production',
            ]);

            self::assertSame('https://www.example.test', $config->baseUrl);
            self::assertSame('', $config->basePath);
            self::assertSame('production', $config->environment);
            self::assertSame(1800, $config->sessionIdleTimeout);
            self::assertSame(28800, $config->sessionAbsoluteTimeout);
            self::assertFalse($config->cacheEnabled);
            self::assertNull($config->captchaProvider);
            self::assertNull($config->captchaSiteKey);
            self::assertNull($config->captchaSecretKey);
            self::assertSame('Segara Administration', $config->authBasicRealm);
        } finally {
            unset(
                $_SERVER['HTTP_HOST'],
                $_SERVER['HTTP_X_FORWARDED_HOST'],
                $_SERVER['HTTP_X_FORWARDED_PROTO'],
            );
        }
    }

    public function testExplicitOperationalConfigurationIsSupported(): void
    {
        $config = Config::fromArray([
            'base_url' => 'http://localhost:8080',
            'base_path' => '/segara',
            'environment' => 'development',
            'session' => [
                'idle_timeout' => 1800,
                'absolute_timeout' => 28800,
            ],
            'cache' => ['enabled' => false],
            'captcha' => [
                'provider' => 'turnstile',
                'site_key' => 'test-site-key',
                'secret_key' => 'test-secret-key',
            ],
            'auth' => ['basic_realm' => 'Example Administration'],
        ]);

        self::assertSame('http://localhost:8080', $config->baseUrl);
        self::assertSame('/segara', $config->basePath);
        self::assertSame(1800, $config->sessionIdleTimeout);
        self::assertSame(28800, $config->sessionAbsoluteTimeout);
        self::assertFalse($config->cacheEnabled);
        self::assertSame('turnstile', $config->captchaProvider);
        self::assertSame('test-site-key', $config->captchaSiteKey);
        self::assertSame('test-secret-key', $config->captchaSecretKey);
        self::assertSame('Example Administration', $config->authBasicRealm);
    }

    public function testExampleConfigurationLoadsWithSafeLocalhostDefaults(): void
    {
        $config = Config::load(dirname(__DIR__) . '/settings/config.example.php');

        self::assertSame('http://localhost:8080', $config->baseUrl);
        self::assertSame('', $config->basePath);
        self::assertSame('development', $config->environment);
        self::assertSame(1800, $config->sessionIdleTimeout);
        self::assertSame(28800, $config->sessionAbsoluteTimeout);
        self::assertFalse($config->cacheEnabled);
        self::assertNull($config->captchaProvider);
        self::assertNull($config->captchaSiteKey);
        self::assertNull($config->captchaSecretKey);
    }

    #[DataProvider('invalidOrigins')]
    public function testInvalidCanonicalOriginsAreRejected(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('base_url');

        Config::fromArray(['base_url' => $baseUrl]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'missing scheme' => ['example.test'];
        yield 'scheme relative' => ['//example.test'];
        yield 'non HTTP scheme' => ['ftp://example.test'];
        yield 'userinfo' => ['https://user:secret@example.test'];
        yield 'path belongs in base path' => ['https://example.test/segara'];
        yield 'query' => ['https://example.test?value=1'];
        yield 'fragment' => ['https://example.test/#fragment'];
        yield 'zero port' => ['https://example.test:0'];
        yield 'port above range' => ['https://example.test:65536'];
        yield 'non numeric port' => ['https://example.test:https'];
        yield 'multiple ports' => ['https://example.test:443:444'];
        yield 'empty value' => [''];
    }

    #[DataProvider('invalidBasePaths')]
    public function testMalformedBasePathsAreRejected(string $basePath): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('base_path');

        Config::fromArray([
            'base_url' => 'https://example.test',
            'base_path' => $basePath,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBasePaths(): iterable
    {
        yield 'missing leading slash' => ['segara'];
        yield 'root should be empty' => ['/'];
        yield 'trailing slash' => ['/segara/'];
        yield 'duplicate slash' => ['/segara//nested'];
        yield 'backslash' => ['/segara\\nested'];
        yield 'dot segment' => ['/segara/./nested'];
        yield 'parent segment' => ['/segara/../nested'];
        yield 'encoded parent segment' => ['/segara/%2e%2e/nested'];
        yield 'encoded slash' => ['/segara/%2fnested'];
        yield 'query' => ['/segara?value=1'];
        yield 'fragment' => ['/segara#fragment'];
        yield 'malformed percent encoding' => ['/segara/%zz'];
        yield 'null byte' => ["/segara\0/nested"];
    }

    public function testIncompleteCaptchaConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('captcha');

        Config::fromArray([
            'base_url' => 'https://example.test',
            'captcha' => ['provider' => 'recaptcha'],
        ]);
    }

    public function testBasicRealmCannotInjectHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('basic_realm');

        Config::fromArray([
            'base_url' => 'https://example.test',
            'auth' => ['basic_realm' => "Segara\r\nX-Injected: yes"],
        ]);
    }
}
