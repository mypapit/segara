<?php

declare(strict_types=1);

namespace Segara\Http;

use Segara\Config;

final class SecurityHeaders
{
    /**
     * style-src carries the pinned Bootstrap directory because the slate theme
     * loads Bootstrap 5.3.3 from jsDelivr. The source is path-scoped to that one
     * version, so it permits nothing else on the host; script-src is untouched.
     */
    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    /** @return array<string, string> */
    public static function headers(bool $configuredHttps): array
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        if ($configuredHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /** @return array<string, string> */
    public static function forConfig(Config $config): array
    {
        return self::headers(parse_url($config->baseUrl, PHP_URL_SCHEME) === 'https');
    }
}
