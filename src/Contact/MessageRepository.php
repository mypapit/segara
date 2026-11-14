<?php

declare(strict_types=1);

namespace Segara\Contact;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * @phpstan-type Message array{id: string, name: string, email: string, subject: string, body: string, is_read: int, created_at: string, updated_at: string}
 * @psalm-type Message = array{id: string, name: string, email: string, subject: string, body: string, is_read: int, created_at: string, updated_at: string}
 */
final class MessageRepository
{
    private const COLUMNS = 'id, name, email, subject, body, is_read, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return Message
     */
    public function create(array $input): array
    {
        $name = $this->text($input['name'] ?? null, 'Name', 200);
        $email = $this->email($input['email'] ?? null);
        $subject = $this->text($input['subject'] ?? null, 'Subject', 200);
        $body = $this->text($input['body'] ?? null, 'Message', 10000);

        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (id, name, email, subject, body, is_read, created_at, updated_at) '
            . 'VALUES (:id, :name, :email, :subject, :body, 0, :now, :now)'
        );
        $statement->execute([
            'id' => $id, 'name' => $name, 'email' => $email, 'subject' => $subject, 'body' => $body, 'now' => $now,
        ]);

        $message = $this->getById($id);
        if ($message === null) {
            throw new RuntimeException('Created message could not be read back.');
        }

        return $message;
    }

    /** @return list<Message> */
    public function list(): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM messages ORDER BY created_at DESC, id ASC');
        $statement->execute();
        $messages = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Message row has an invalid storage type.');
            }
            $messages[] = $this->hydrate($row);
        }

        return $messages;
    }

    /** @return Message|null */
    public function getById(string $id): ?array
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM messages WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Message row has an invalid storage type.');
        }

        return $this->hydrate($row);
    }

    public function markRead(string $id): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid message reference.');
        }
        $statement = $this->pdo->prepare('UPDATE messages SET is_read = 1, updated_at = :now WHERE id = :id');
        $statement->execute(['id' => $id, 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Message not found.');
        }
    }

    public function delete(string $id): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid message reference.');
        }
        $statement = $this->pdo->prepare('DELETE FROM messages WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Message not found.');
        }
    }

    /**
     * @param array<array-key, mixed> $row
     * @return Message
     */
    private function hydrate(array $row): array
    {
        $isRead = $row['is_read'] ?? null;
        if (!is_int($isRead) && !is_string($isRead)) {
            throw new RuntimeException('Message is_read has an invalid storage type.');
        }

        return [
            'id' => $this->rowString($row, 'id'),
            'name' => $this->rowString($row, 'name'),
            'email' => $this->rowString($row, 'email'),
            'subject' => $this->rowString($row, 'subject'),
            'body' => $this->rowString($row, 'body'),
            'is_read' => (int) $isRead,
            'created_at' => $this->rowString($row, 'created_at'),
            'updated_at' => $this->rowString($row, 'updated_at'),
        ];
    }

    private function email(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        return $value;
    }

    private function text(mixed $value, string $label, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > $maximum || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException($label . ' must be non-empty UTF-8 of at most ' . $maximum . ' characters.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Message column has an invalid storage type.');
        }

        return $value;
    }
}
