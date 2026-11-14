<?php

declare(strict_types=1);

namespace Segara;

final class Environment
{
    public const MINIMUM_PHP_VERSION = '8.4.1';

    /** @param list<string> $requiredExtensions */
    public function __construct(private readonly array $requiredExtensions)
    {
    }

    /**
     * @param list<string> $loadedExtensions
     * @param array{public: string, store: string, uploads: string, config: string, sqlite: string} $paths
     * @return array{ok: bool, errors: list<string>}
     */
    public function report(string $phpVersion, array $loadedExtensions, array $paths): array
    {
        $errors = [];

        if (version_compare($phpVersion, self::MINIMUM_PHP_VERSION, '<')) {
            $errors[] = 'PHP ' . self::MINIMUM_PHP_VERSION . ' or newer is required.';
        }

        $loaded = array_map('strtolower', $loadedExtensions);
        foreach ($this->requiredExtensions as $extension) {
            if (!in_array(strtolower($extension), $loaded, true)) {
                $errors[] = 'Missing PHP extension: ' . $extension . '.';
            }
        }

        $public = $this->canonicalPath($paths['public']);
        foreach (['store', 'uploads', 'config', 'sqlite'] as $name) {
            if ($this->isWithin($this->canonicalPath($paths[$name]), $public)) {
                $errors[] = 'Private storage must be outside the public document root.';
                break;
            }
        }

        foreach (['store', 'uploads'] as $name) {
            if (!is_dir($paths[$name]) || !is_writable($paths[$name])) {
                $errors[] = 'Runtime directory is missing or not writable: ' . $name . '.';
            }
        }

        $configTarget = is_file($paths['config']) ? $paths['config'] : dirname($paths['config']);
        if ((!is_file($paths['config']) && !is_dir($configTarget)) || !is_writable($configTarget)) {
            $errors[] = 'Configuration path is not writable.';
        }

        if (file_exists($paths['sqlite']) && (!is_file($paths['sqlite']) || !is_writable($paths['sqlite']))) {
            $errors[] = 'SQLite database path is not writable.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private function canonicalPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return $resolved;
        }

        $parent = $path;
        $suffix = [];
        while (!file_exists($parent) && dirname($parent) !== $parent) {
            array_unshift($suffix, basename($parent));
            $parent = dirname($parent);
        }

        $base = realpath($parent);
        if ($base === false) {
            $base = $parent;
        }

        return $base . ($suffix === [] ? '' : '/' . implode('/', $suffix));
    }

    private function isWithin(string $path, string $directory): bool
    {
        return $path === $directory
            || ($directory === '/' ? str_starts_with($path, '/') : str_starts_with($path, $directory . '/'));
    }
}
