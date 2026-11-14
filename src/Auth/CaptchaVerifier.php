<?php

declare(strict_types=1);

namespace Segara\Auth;

use Closure;
use RuntimeException;
use Segara\Config;

final class CaptchaVerifier
{
    private const MAX_RESPONSE_BYTES = 65536;

    /** @var Closure(string, array<string, string>, array<string, bool|int>): string */
    private readonly Closure $transport;

    /**
     * @param null|callable(string, array<string, string>, array<string, bool|int>): string $transport
     */
    public function __construct(private readonly Config $config, ?callable $transport = null)
    {
        $this->transport = $transport === null
            ? Closure::fromCallable($this->request(...))
            : Closure::fromCallable($transport);
    }

    public function verify(mixed $response): bool
    {
        $provider = $this->config->captchaProvider;
        $secret = $this->config->captchaSecretKey;
        if ($provider === null || $secret === null || !in_array($provider, ['recaptcha', 'turnstile'], true)
            || !is_string($response) || $response === '' || strlen($response) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $response) === 1
        ) {
            return false;
        }

        $url = match ($provider) {
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        };
        $options = [
            'timeout' => 5,
            'follow_redirects' => false,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];

        try {
            $body = ($this->transport)($url, ['secret' => $secret, 'response' => $response], $options);
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return is_array($decoded) && ($decoded['success'] ?? null) === true;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, bool|int> $options
     */
    private function request(string $url, array $fields, array $options): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
                'content' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                'timeout' => $options['timeout'],
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $options['verify_peer'],
                'verify_peer_name' => $options['verify_peer_name'],
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
        $headers = $http_response_header;
        if ($body === false || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('CAPTCHA verification request failed.');
        }
        $status = $headers[0] ?? null;
        if (!is_string($status) || preg_match('/\AHTTP\/[0-9.]+ 200(?: |\z)/', $status) !== 1) {
            throw new RuntimeException('CAPTCHA verification returned an unsuccessful response.');
        }

        return $body;
    }
}
