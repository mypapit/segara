<?php

declare(strict_types=1);

namespace Segara\Settings;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class SettingsRepository
{
    /** @var list<string> Writable setting keys. Secrets stay in local config, never here. */
    public const KEYS = [
        'site_name', 'site_tagline', 'site_description', 'locale', 'timezone', 'currency', 'fuzzy_dates', 'theme', 'homepage_id',
        'page_date_format', 'page_date_pattern',
        'primary_menu_id',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT key, value FROM settings');
        $statement->execute();
        $settings = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Settings row has an invalid storage type.');
            }
            $settings[$this->rowString($row, 'key')] = $this->rowString($row, 'value');
        }

        return $settings;
    }

    public function get(string $key): ?string
    {
        $this->assertKey($key);
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** @param array<mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || !in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException('Unknown setting key.');
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('Setting value must be a string.');
            }
        }
        if ($values === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO settings (key, value, revision, updated_at) VALUES (:key, :value, 1, :updated) '
                . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value, revision = revision + 1, updated_at = excluded.updated_at'
            );
            $now = gmdate('Y-m-d\TH:i:s\Z');
            foreach ($values as $key => $value) {
                $statement->execute(['key' => $key, 'value' => $value, 'updated' => $now]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function assertKey(string $key): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException('Unknown setting key.');
        }
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Settings column has an invalid storage type.');
        }

        return $value;
    }
}
