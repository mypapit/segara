<?php

declare(strict_types=1);

namespace Segara\Storage;

use InvalidArgumentException;
use PDO;

final class Database
{
    public static function connect(string $path, string $journalMode = 'DELETE'): PDO
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('SQLite database path must not be empty or contain a null byte.');
        }

        $journalMode = strtoupper($journalMode);
        $journalPragma = match ($journalMode) {
            'DELETE' => 'PRAGMA journal_mode = DELETE',
            'WAL' => 'PRAGMA journal_mode = WAL',
            default => throw new InvalidArgumentException('SQLite journal mode must be DELETE or WAL.'),
        };

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec($journalPragma);

        return $pdo;
    }
}
