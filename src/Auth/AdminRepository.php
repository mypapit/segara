<?php

declare(strict_types=1);

namespace Segara\Auth;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * @phpstan-type Admin array{id: string, username: string, display_name: string, password_hash: string, auth_epoch: int, created_at: string, updated_at: string}
 * @psalm-type Admin = array{id: string, username: string, display_name: string, password_hash: string, auth_epoch: int, created_at: string, updated_at: string}
 * @phpstan-type AdminSummary array{id: string, username: string, display_name: string, created_at: string, updated_at: string}
 * @psalm-type AdminSummary = array{id: string, username: string, display_name: string, created_at: string, updated_at: string}
 */
final class AdminRepository
{
    private const ADMIN_COLUMNS = 'id, username, display_name, password_hash, auth_epoch, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return Admin */
    public function create(string $username, string $displayName, string $password): array
    {
        $this->validateUsername($username);
        $this->validateDisplayName($displayName);
        $this->validatePassword($password);

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $id = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO admins (id, username, display_name, password_hash, auth_epoch, created_at, updated_at) '
            . 'VALUES (:id, :username, :display_name, :password_hash, 1, :created_at, :updated_at)'
        );

        try {
            $statement->execute([
                'id' => $id,
                'username' => $username,
                'display_name' => $displayName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'admins.username')) {
                throw new InvalidArgumentException('Username is already in use by another administrator.', 0, $exception);
            }
            throw $exception;
        }

        $admin = $this->findById($id);
        if ($admin === null) {
            throw new RuntimeException('Created administrator could not be read back.');
        }

        return $admin;
    }

    /** @return Admin|null */
    public function findById(string $id): ?array
    {
        return $this->find('id', $id);
    }

    /** @return Admin|null */
    public function findByUsername(string $username): ?array
    {
        return $this->find('username', $username);
    }

    /** @return list<AdminSummary> */
    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, created_at, updated_at FROM admins ORDER BY username ASC'
        );
        $statement->execute();
        $administrators = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Administrator row has an invalid storage type.');
            }
            $administrators[] = [
                'id' => $this->rowString($row, 'id'),
                'username' => $this->rowString($row, 'username'),
                'display_name' => $this->rowString($row, 'display_name'),
                'created_at' => $this->rowString($row, 'created_at'),
                'updated_at' => $this->rowString($row, 'updated_at'),
            ];
        }

        return $administrators;
    }

    /**
     * Delete another administrator while atomically preserving at least one account.
     */
    public function delete(string $id, string $currentAdminId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM admins WHERE id = :id AND id <> :current_admin_id '
            . 'AND (SELECT COUNT(*) FROM admins) > 1'
        );
        $statement->execute(['id' => $id, 'current_admin_id' => $currentAdminId]);
        if ($statement->rowCount() === 1) {
            return;
        }
        if ($this->findById($id) === null) {
            throw new InvalidArgumentException('Administrator not found.');
        }
        if ($id === $currentAdminId) {
            throw new InvalidArgumentException('You cannot delete the account you are currently using.');
        }

        throw new InvalidArgumentException('The final administrator account cannot be deleted.');
    }

    public function count(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM admins');
        $statement->execute();
        $count = $statement->fetchColumn();
        if (!is_int($count) && !is_string($count)) {
            throw new RuntimeException('Administrator count has an invalid storage type.');
        }

        return (int) $count;
    }

    /**
     * Replace a password and invalidate every session using the previous epoch.
     *
     * @return Admin
     */
    public function updatePassword(string $id, string $password): array
    {
        $this->validatePassword($password);
        $statement = $this->pdo->prepare(
            'UPDATE admins SET password_hash = :password_hash, auth_epoch = auth_epoch + 1, '
            . 'updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Administrator not found.');
        }

        $admin = $this->findById($id);
        if ($admin === null) {
            throw new RuntimeException('Updated administrator could not be read back.');
        }

        return $admin;
    }

    /**
     * Verify a password and transparently upgrade an outdated hash after success.
     * A rehash preserves auth_epoch; an explicit password update increments it.
     *
     * @return Admin|null Null means the username or password did not match.
     */
    public function verifyAndRehashPassword(string $username, string $password): ?array
    {
        $admin = $this->findByUsername($username);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            return null;
        }
        if (!password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            return $admin;
        }

        $statement = $this->pdo->prepare(
            'UPDATE admins SET password_hash = :new_hash, updated_at = :updated_at '
            . 'WHERE id = :id AND password_hash = :old_hash'
        );
        $statement->execute([
            'new_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $admin['id'],
            'old_hash' => $admin['password_hash'],
        ]);
        if ($statement->rowCount() !== 1) {
            return null;
        }

        return $this->findById($admin['id']);
    }

    /** @return Admin|null */
    private function find(string $field, string $value): ?array
    {
        $sql = match ($field) {
            'id' => 'SELECT ' . self::ADMIN_COLUMNS . ' FROM admins WHERE id = :value',
            'username' => 'SELECT ' . self::ADMIN_COLUMNS . ' FROM admins WHERE username = :value',
            default => throw new InvalidArgumentException('Unsupported administrator lookup.'),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Administrator row has an invalid storage type.');
        }

        $authEpoch = $row['auth_epoch'] ?? null;
        if (!is_int($authEpoch) && !is_string($authEpoch)) {
            throw new RuntimeException('Administrator epoch has an invalid storage type.');
        }

        return [
            'id' => $this->rowString($row, 'id'),
            'username' => $this->rowString($row, 'username'),
            'display_name' => $this->rowString($row, 'display_name'),
            'password_hash' => $this->rowString($row, 'password_hash'),
            'auth_epoch' => (int) $authEpoch,
            'created_at' => $this->rowString($row, 'created_at'),
            'updated_at' => $this->rowString($row, 'updated_at'),
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Administrator column has an invalid storage type.');
        }

        return $value;
    }

    private function validateUsername(string $username): void
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/D', $username) !== 1) {
            throw new InvalidArgumentException(
                'Username must be 3-64 characters using only letters, numbers, dots, underscores, or hyphens.'
            );
        }
    }

    private function validateDisplayName(string $displayName): void
    {
        if ($displayName === '' || trim($displayName) === ''
            || !mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName) > 200
        ) {
            throw new InvalidArgumentException('Display name must contain 1-200 valid UTF-8 characters.');
        }
    }

    private function validatePassword(string $password): void
    {
        $length = strlen($password);
        if ($length < 12 || $length > 4096 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Password must contain 12-4096 bytes and no null bytes.');
        }
    }
}
