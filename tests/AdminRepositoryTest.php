<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Segara\Auth\AdminRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class AdminRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminRepository $admins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->admins = new AdminRepository($this->pdo);
    }

    public function testCreateHashesPasswordAndSupportsLookupAndCount(): void
    {
        $password = 'correct horse battery staple';
        $before = gmdate('Y-m-d\TH:i:s\Z');
        $admin = $this->admins->create('site.admin', 'Site Administrator', $password);
        $after = gmdate('Y-m-d\TH:i:s\Z');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $admin['id']);
        self::assertSame('site.admin', $admin['username']);
        self::assertSame('Site Administrator', $admin['display_name']);
        self::assertSame(1, $admin['auth_epoch']);
        self::assertNotSame($password, $admin['password_hash']);
        self::assertStringNotContainsString($password, $admin['password_hash']);
        self::assertTrue(password_verify($password, $admin['password_hash']));
        self::assertFalse(password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT));
        self::assertGreaterThanOrEqual($before, $admin['created_at']);
        self::assertLessThanOrEqual($after, $admin['created_at']);
        self::assertSame($admin['created_at'], $admin['updated_at']);
        self::assertSame($admin, $this->admins->findById($admin['id']));
        self::assertSame($admin, $this->admins->findByUsername('site.admin'));
        self::assertNull($this->admins->findByUsername('missing'));
        self::assertSame(1, $this->admins->count());
        self::assertSame($admin, $this->admins->verifyAndRehashPassword('site.admin', $password));
        self::assertNull($this->admins->verifyAndRehashPassword('site.admin', 'incorrect password'));
    }

    public function testDuplicateUsernameIsRejectedWithoutOverwritingAccount(): void
    {
        $original = $this->admins->create('admin', 'Original', 'original password');

        try {
            $this->admins->create('admin', 'Replacement', 'replacement password');
            self::fail('A duplicate administrator username was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already in use', $exception->getMessage());
        }

        self::assertSame($original, $this->admins->findByUsername('admin'));
        self::assertSame(1, $this->admins->count());
    }

    public function testPasswordUpdateRotatesHashAndIncrementsAuthenticationEpoch(): void
    {
        $admin = $this->admins->create('admin', 'Administrator', 'old password value');
        $oldHash = $admin['password_hash'];

        $updated = $this->admins->updatePassword($admin['id'], 'new password value');

        self::assertSame($admin['id'], $updated['id']);
        self::assertSame(2, $updated['auth_epoch']);
        self::assertNotSame($oldHash, $updated['password_hash']);
        self::assertFalse(password_verify('old password value', $updated['password_hash']));
        self::assertTrue(password_verify('new password value', $updated['password_hash']));
        self::assertNull($this->admins->verifyAndRehashPassword('admin', 'old password value'));
        self::assertNotNull($this->admins->verifyAndRehashPassword('admin', 'new password value'));
    }

    public function testSuccessfulVerificationRehashDoesNotInvalidateSessions(): void
    {
        $password = 'legacy password value';
        $admin = $this->admins->create('admin', 'Administrator', $password);
        $legacyHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $statement = $this->pdo->prepare('UPDATE admins SET password_hash = :password_hash WHERE id = :id');
        $statement->execute(['password_hash' => $legacyHash, 'id' => $admin['id']]);

        self::assertTrue(password_needs_rehash($legacyHash, PASSWORD_DEFAULT));
        $verified = $this->admins->verifyAndRehashPassword('admin', $password);
        self::assertNotNull($verified);
        self::assertNotSame($legacyHash, $verified['password_hash']);
        self::assertFalse(password_needs_rehash($verified['password_hash'], PASSWORD_DEFAULT));
        self::assertSame(1, $verified['auth_epoch']);
        self::assertTrue(password_verify($password, $verified['password_hash']));
    }

    #[DataProvider('invalidUsernames')]
    public function testInvalidUsernamesAreRejected(string $username): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->admins->create($username, 'Administrator', 'valid password value');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUsernames(): iterable
    {
        foreach (['', 'ab', str_repeat('a', 65), 'white space', 'slash/name', "line\nbreak", 'admin@example.test'] as $username) {
            yield $username => [$username];
        }
    }

    public function testDisplayNameAndPasswordBoundariesAreEnforced(): void
    {
        foreach (['', str_repeat('é', 201)] as $displayName) {
            try {
                $this->admins->create('admin', $displayName, 'valid password value');
                self::fail('An invalid display name was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->admins->count());
            }
        }

        foreach (['short', str_repeat('p', 4097)] as $password) {
            try {
                $this->admins->create('admin', 'Administrator', $password);
                self::fail('An invalid password was accepted.');
            } catch (InvalidArgumentException) {
                // Rejection must happen before an administrator is inserted.
            }
        }
    }

    public function testUpdatingMissingAdministratorFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->admins->updatePassword(str_repeat('0', 32), 'new password value');
    }
}
