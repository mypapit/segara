<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Storage\Database;
use Tests\Support\TestCase;

final class CliAccountTest extends TestCase
{
    public function testHelpExitsSuccessfullyWithoutCreatingDatabase(): void
    {
        foreach (['setup', 'password-reset'] as $command) {
            $result = $this->runCommand($command, ['--help', '--database=' . $this->sqlitePath]);

            self::assertSame(0, $result['status'], $result['stderr']);
            self::assertStringContainsString('Usage:', $result['stdout']);
            self::assertFileDoesNotExist($this->sqlitePath);
        }
    }

    public function testSetupCreatesOnlyFirstAdministratorWithoutExposingPassword(): void
    {
        $password = 'first administrator password';
        $result = $this->runCommand('setup', [
            '--database=' . $this->sqlitePath,
            '--username=admin',
            '--display-name=Site Administrator',
        ], $password . "\n");

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('OK:', $result['stdout']);
        self::assertStringNotContainsString($password, $result['stdout'] . $result['stderr']);

        $admin = $this->adminRow();
        self::assertSame('admin', $admin['username']);
        self::assertTrue(password_verify($password, $admin['password_hash']));
        self::assertStringNotContainsString($password, $admin['password_hash']);

        $second = $this->runCommand('setup', [
            '--database=' . $this->sqlitePath,
            '--username=other',
        ], 'another administrator password' . "\n");
        self::assertNotSame(0, $second['status']);
        self::assertStringContainsString('already', $second['stderr']);
        self::assertStringNotContainsString('another administrator password', $second['stdout'] . $second['stderr']);
        self::assertSame($admin, $this->adminRow());
    }

    public function testPasswordResetUsesUsernameAndInvalidatesPreviousAuthenticationEpoch(): void
    {
        $oldPassword = 'old administrator password';
        $newPassword = 'new administrator password';
        $setup = $this->runCommand('setup', [
            '--database=' . $this->sqlitePath,
            '--username=admin',
        ], $oldPassword . "\n");
        self::assertSame(0, $setup['status'], $setup['stderr']);
        $before = $this->adminRow();

        $reset = $this->runCommand('password-reset', [
            '--database=' . $this->sqlitePath,
            '--username=admin',
        ], $newPassword . "\n");

        self::assertSame(0, $reset['status'], $reset['stderr']);
        self::assertStringNotContainsString($newPassword, $reset['stdout'] . $reset['stderr']);
        $after = $this->adminRow();
        self::assertSame((int) $before['auth_epoch'] + 1, (int) $after['auth_epoch']);
        self::assertNotSame($before['password_hash'], $after['password_hash']);
        self::assertFalse(password_verify($oldPassword, $after['password_hash']));
        self::assertTrue(password_verify($newPassword, $after['password_hash']));
    }

    public function testResetRejectsUnknownUsernameWithoutWriting(): void
    {
        $setup = $this->runCommand('setup', [
            '--database=' . $this->sqlitePath,
            '--username=admin',
        ], "initial administrator password\n");
        self::assertSame(0, $setup['status'], $setup['stderr']);
        $before = $this->adminRow();

        $reset = $this->runCommand('password-reset', [
            '--database=' . $this->sqlitePath,
            '--username=missing',
        ], "unused administrator password\n");

        self::assertNotSame(0, $reset['status']);
        self::assertStringContainsString('not found', $reset['stderr']);
        self::assertStringNotContainsString('unused administrator password', $reset['stdout'] . $reset['stderr']);
        self::assertSame($before, $this->adminRow());
    }

    public function testNonCliRequestsReturnGenericNotFound(): void
    {
        $root = dirname(__DIR__);
        foreach (['setup', 'password-reset'] as $command) {
            $server = $this->startWebServer($root, $command);
            try {
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
                $body = file_get_contents('http://127.0.0.1:' . $server['port'] . '/' . $command, false, $context);
                $status = $http_response_header[0] ?? '';

                self::assertSame('Not Found' . "\n", $body);
                self::assertStringContainsString(' 404 ', $status);
            } finally {
                proc_terminate($server['process']);
                proc_close($server['process']);
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runCommand(string $command, array $arguments, string $input = ''): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/' . $command, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, string> */
    private function adminRow(): array
    {
        $pdo = Database::connect($this->sqlitePath);
        $statement = $pdo->prepare('SELECT * FROM admins');
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $count = $pdo->prepare('SELECT COUNT(*) FROM admins');
        $count->execute();
        self::assertSame(1, (int) $count->fetchColumn());

        /** @var array<string, string> $row */
        return $row;
    }

    /** @return array{process: resource, port: int} */
    private function startWebServer(string $root, string $command): array
    {
        $router = $this->temporaryRoot . '/' . $command . '-router.php';
        file_put_contents($router, '<?php require ' . var_export($root . '/bin/' . $command, true) . ';');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? '');
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $separator = strrchr($address, ':');
        self::assertIsString($separator);
        $port = (int) substr($separator, 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return ['process' => $process, 'port' => $port];
            }
            usleep(20000);
        }

        proc_terminate($process);
        proc_close($process);
        self::fail('The test web server did not start.');
    }
}
