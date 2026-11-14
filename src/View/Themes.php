<?php

declare(strict_types=1);

namespace Segara\View;

use InvalidArgumentException;
use RuntimeException;

final class Themes
{
    /** @var list<string> Fixed public theme allowlist. */
    public const ALL = ['aubergine', 'clarity', 'editorial', 'folio', 'scholar', 'slate'];

    public function __construct(private readonly string $themeRoot)
    {
    }

    /**
     * Load and validate one allowlisted theme's descriptor.
     *
     * @return array{id: string, name: string, sidebars: int, description: string, swatch: array{surface: string, primary: string, accent: string}}
     */
    public function info(string $id): array
    {
        if (!in_array($id, self::ALL, true)) {
            throw new InvalidArgumentException('Unknown theme.');
        }

        $file = $this->themeRoot . '/' . $id . '/theme.php';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Theme descriptor is unavailable.');
        }

        $info = require $file;
        if (!is_array($info)
            || ($info['id'] ?? null) !== $id
            || !is_string($info['name'] ?? null) || trim($info['name']) === ''
            || !is_int($info['sidebars'] ?? null)
            || !in_array($info['sidebars'], [0, 1, 2], true)
        ) {
            throw new RuntimeException('Theme descriptor is invalid.');
        }

        $description = $info['description'] ?? '';
        if (!is_string($description)) {
            throw new RuntimeException('Theme descriptor is invalid.');
        }

        $defaultSwatch = ['surface' => '#ffffff', 'primary' => '#1a1a1a', 'accent' => '#1a6d5c'];
        $swatch = $info['swatch'] ?? [];
        $cleanSwatch = [];
        foreach ($defaultSwatch as $key => $fallback) {
            $value = is_array($swatch) ? ($swatch[$key] ?? null) : null;
            $cleanSwatch[$key] = is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1
                ? $value
                : $fallback;
        }

        return [
            'id' => $id,
            'name' => $info['name'],
            'sidebars' => $info['sidebars'],
            'description' => $description,
            'swatch' => $cleanSwatch,
        ];
    }

    public function root(): string
    {
        return $this->themeRoot;
    }
}
