<?php

declare(strict_types=1);

namespace Segara\Support;

/**
 * Append-only error log stored outside the web root. Rotates at a byte
 * threshold and keeps at most five files, and redacts credential-shaped keys
 * before anything is written.
 */
final class Logger
{
    private const DEFAULT_MAX_BYTES = 1048576;
    private const MAX_FILES = 5;

    public function __construct(
        private readonly string $logDir,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0770, true);
        }
    }

    /** @param array<string, mixed> $context */
    public function error(string $id, string $message, array $context = []): void
    {
        $path = rtrim($this->logDir, '/') . '/error.log';
        $this->rotate($path);

        $message = str_replace(["\r", "\n"], ' ', $message);
        $payload = json_encode(
            $this->redact($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false) {
            $payload = '{}';
        }
        $line = sprintf("[%s] %s %s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $id, $message, $payload);
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<mixed, mixed> $context
     * @return array<mixed, mixed>
     */
    public function redact(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            if ($this->sensitive((string) $key)) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    private function sensitive(string $key): bool
    {
        $key = strtolower($key);

        return str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'cookie')
            || str_contains($key, 'authorization')
            || str_contains($key, 'session');
    }

    private function rotate(string $path): void
    {
        if (!is_file($path) || filesize($path) < $this->maxBytes) {
            return;
        }
        $oldest = $path . '.' . (self::MAX_FILES - 1);
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($i = self::MAX_FILES - 2; $i >= 1; $i--) {
            $source = $path . '.' . $i;
            if (is_file($source)) {
                @rename($source, $path . '.' . ($i + 1));
            }
        }
        @rename($path, $path . '.1');
    }
}
