<?php

declare(strict_types=1);

namespace Segara\Auth;

use Closure;
use PDO;
use RuntimeException;

final class LoginThrottle
{
    private const WINDOW_SECONDS = 900;
    private const RETRY_SECONDS = 300;
    private const MAX_FAILURES = 5;
    private const MAX_ENTRIES = 500;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|callable(): int $clock */
    public function __construct(private readonly PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    /** Return the remaining retry delay, or zero when an attempt is permitted. */
    public function retryAfter(string $key): int
    {
        $statement = $this->pdo->prepare(
            'SELECT failures, window_started, retry_after FROM throttle WHERE key = :key'
        );
        $statement->execute(['key' => $this->key($key)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return 0;
        }

        $now = $this->now();
        $windowStarted = $this->integerColumn($row['window_started'] ?? null);
        $retryAfter = $this->integerColumn($row['retry_after'] ?? null);
        if ($windowStarted + self::WINDOW_SECONDS <= $now || $retryAfter <= $now) {
            return 0;
        }

        return $retryAfter - $now;
    }

    /** Record one failed attempt and return the resulting retry delay in seconds. */
    public function recordFailure(string $key): int
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $this->pruneExpired($now);
            $statement = $this->pdo->prepare(
                'SELECT failures, window_started, retry_after FROM throttle WHERE key = :key'
            );
            $statement->execute(['key' => $this->key($key)]);
            $row = $statement->fetch();

            $failures = 0;
            $windowStarted = $now;
            $retryAfter = 0;
            if (is_array($row)) {
                $storedRetryAfter = $this->integerColumn($row['retry_after'] ?? null);
                if ($storedRetryAfter > $now) {
                    $this->pdo->commit();
                    return $storedRetryAfter - $now;
                }

                $storedWindowStarted = $this->integerColumn($row['window_started'] ?? null);
                $storedFailures = $this->integerColumn($row['failures'] ?? null);
                if ($storedWindowStarted + self::WINDOW_SECONDS > $now && $storedFailures < self::MAX_FAILURES) {
                    $failures = $storedFailures;
                    $windowStarted = $storedWindowStarted;
                }
            }

            ++$failures;
            if ($failures >= self::MAX_FAILURES) {
                $retryAfter = $now + self::RETRY_SECONDS;
            }
            $write = $this->pdo->prepare(
                'INSERT INTO throttle (key, failures, window_started, retry_after, updated_at) '
                . 'VALUES (:key, :failures, :window_started, :retry_after, :updated_at) '
                . 'ON CONFLICT(key) DO UPDATE SET failures = excluded.failures, '
                . 'window_started = excluded.window_started, retry_after = excluded.retry_after, '
                . 'updated_at = excluded.updated_at'
            );
            $write->execute([
                'key' => $this->key($key),
                'failures' => $failures,
                'window_started' => $windowStarted,
                'retry_after' => $retryAfter,
                'updated_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
            ]);

            $bound = $this->pdo->prepare(
                'DELETE FROM throttle WHERE rowid IN ('
                . 'SELECT rowid FROM throttle ORDER BY updated_at DESC, rowid DESC LIMIT -1 OFFSET :limit)'
            );
            $bound->bindValue('limit', self::MAX_ENTRIES, PDO::PARAM_INT);
            $bound->execute();
            $this->pdo->commit();

            return max(0, $retryAfter - $now);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function reset(string $key): void
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM throttle WHERE key = :key');
            $statement->execute(['key' => $this->key($key)]);
            $this->pruneExpired($now);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function pruneExpired(int $now): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM throttle WHERE window_started + :window <= :now AND retry_after <= :retry_now'
        );
        $statement->bindValue('window', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $statement->bindValue('now', $now, PDO::PARAM_INT);
        $statement->bindValue('retry_now', $now, PDO::PARAM_INT);
        $statement->execute();
    }

    private function key(string $key): string
    {
        return hash('sha256', $key);
    }

    private function integerColumn(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Throttle row has an invalid storage type.');
        }

        return (int) $value;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
