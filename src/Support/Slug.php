<?php

declare(strict_types=1);

namespace Segara\Support;

use InvalidArgumentException;

final class Slug
{
    /** @var list<string> */
    private const RESERVED = [
        'admin',
        'admin.php',
        'media',
        'assets',
        'rss.xml',
        'sitemap.xml',
        'robots.txt',
        'llms.txt',
        'documents',
        'videos',
    ];

    public static function fromTitle(string $title): string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            throw new InvalidArgumentException('Slug source must be valid UTF-8.');
        }

        $ascii = transliterator_transliterate(
            'Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; Lower()',
            $title
        );
        if ($ascii === false) {
            throw new InvalidArgumentException('Slug could not be transliterated.');
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii));
        if ($slug === null) {
            throw new InvalidArgumentException('Slug could not be normalized.');
        }

        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 120);
        $slug = rtrim($slug, '-');

        return $slug === '' ? 'page' : $slug;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }
}
