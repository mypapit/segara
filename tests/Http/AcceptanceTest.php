<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Segara\Blocks\BlockRepository;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;

/**
 * End-to-end acceptance suite against the PHP built-in server with an isolated
 * fixture store (SEGARA_ROOT). Exercises the public surface, admin
 * authentication/CSRF/session lifecycle, contact submission, feeds, and
 * content leakage over real HTTP.
 */
final class AcceptanceTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private static string $projectRoot;
    private static string $root;
    private static string $baseUrl;
    /** @var resource|null */
    private static $serverProcess = null;
    /** @var array<string, string> */
    private static array $cookies = [];

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 2);
        self::$root = sys_get_temp_dir() . '/segara-accept-' . bin2hex(random_bytes(8));
        $port = self::freePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $bootstrap = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$projectRoot . '/tests/Http/bootstrap-server.php')
            . ' ' . escapeshellarg(self::$root) . ' ' . escapeshellarg(self::$baseUrl) . ' ' . escapeshellarg(self::PASSWORD);
        exec($bootstrap . ' 2>&1', $output, $code);
        if ($code !== 0 || implode("\n", $output) !== 'READY') {
            throw new RuntimeException('Acceptance bootstrap failed: ' . implode("\n", $output));
        }

        $log = self::$root . '/server.log';
        $cmd = 'SEGARA_DATA=' . escapeshellarg(self::$root) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(self::$projectRoot . '/public')
            . ' ' . escapeshellarg(self::$projectRoot . '/public/router.php');
        $proc = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, self::$projectRoot);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start the PHP built-in server.');
        }
        self::$serverProcess = $proc;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $response = self::request('GET', '/');
            if ($response['status'] !== 0) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Server did not become ready. Log: ' . @file_get_contents($log));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeTree(self::$root);
    }

    public function testPublicPageRendersAuthoredSidebarBlocks(): void
    {
        $pdo = Database::connect(self::$root . '/store/segara.sqlite');
        $settings = new SettingsRepository($pdo);
        $settings->setMany(['theme' => 'aubergine']);
        (new BlockRepository($pdo))->create([
            'location' => 'left',
            'type' => 'quick-facts',
            'position' => 0,
            'settings' => ['title' => 'Acceptance', 'facts' => [['text' => 'AcceptanceSidebarMarker', 'bullet' => '']]],
        ]);

        try {
            $response = self::request('GET', '/');
            self::assertSame(200, $response['status']);
            // Aubergine declares one sidebar, so the rail sits on the right.
            self::assertStringContainsString('sidebar--rail', $response['body']);
            self::assertStringContainsString('AcceptanceSidebarMarker', $response['body']);

            // Clarity declares no sidebars: the stored block must not surface.
            $settings->setMany(['theme' => 'clarity']);
            $clarity = self::request('GET', '/');
            self::assertSame(200, $clarity['status']);
            self::assertStringNotContainsString('<aside', $clarity['body']);
            self::assertStringNotContainsString('AcceptanceSidebarMarker', $clarity['body']);
        } finally {
            $settings->setMany(['theme' => 'clarity']);
        }
    }

    public function testHomePageRendersWithMetadata(): void
    {
        $response = self::request('GET', '/');
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('<html', $response['body']);
        self::assertStringContainsString('application/ld+json', $response['body']);
    }

    public function testPublishedPageRendersAndDraftTrashMissingReturn404(): void
    {
        self::seedPage(['title' => 'Public Post', 'slug' => 'public-post', 'state' => 'published', 'body_markdown' => 'Visible **content**']);
        self::seedPage(['title' => 'Draft Post', 'slug' => 'draft-post', 'state' => 'draft', 'body_markdown' => 'Secret draft']);
        self::seedPage(['title' => 'Trashed Post', 'slug' => 'trashed-post', 'state' => 'trash']);

        $published = self::request('GET', '/public-post');
        self::assertSame(200, $published['status']);
        self::assertStringContainsString('Visible', $published['body']);

        self::assertSame(404, self::request('GET', '/draft-post')['status']);
        self::assertSame(404, self::request('GET', '/trashed-post')['status']);
        self::assertSame(404, self::request('GET', '/does-not-exist')['status']);
    }

    public function testPrivatePageChallengesWithoutLeakingBodyAndIsNoStore(): void
    {
        self::seedPage([
            'title' => 'Private Post', 'slug' => 'private-post', 'state' => 'private',
            'body_markdown' => 'TOP-SECRET-BODY', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $response = self::request('GET', '/private-post');
        self::assertSame(200, $response['status']);
        self::assertStringNotContainsString('TOP-SECRET-BODY', $response['body']);
        self::assertStringContainsString('password', strtolower($response['body']));
        self::assertStringContainsString('no-store', $response['headers']['cache-control'] ?? '');
    }

    public function testRssFeedListsPublishedOnly(): void
    {
        self::seedPage(['title' => 'Feed Item', 'slug' => 'feed-item', 'state' => 'published']);
        self::seedPage(['title' => 'Feed Draft', 'slug' => 'feed-draft', 'state' => 'draft']);

        $response = self::request('GET', '/rss.xml');
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('application/rss+xml', $response['headers']['content-type'] ?? '');
        self::assertStringContainsString('Feed Item', $response['body']);
        self::assertStringNotContainsString('Feed Draft', $response['body']);
    }

    public function testContactFormSubmitsAndRejectsBadCsrf(): void
    {
        self::seedPage(['title' => 'Contact Page', 'slug' => 'contact-page', 'state' => 'published', 'show_contact_form' => 1]);

        $page = self::request('GET', '/contact-page');
        self::assertStringContainsString('name="message"', $page['body']);
        $csrf = self::csrfFrom($page['body']);
        self::assertNotSame('', $csrf);

        $saved = self::request('POST', '/contact-page', [
            'name' => 'Alice', 'email' => 'alice@example.com', 'subject' => 'Hello', 'message' => 'Body', '_csrf' => $csrf,
        ]);
        self::assertStringContainsString('Message saved', $saved['body']);

        $rejected = self::request('POST', '/contact-page', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'subject' => 'x', 'message' => 'y', '_csrf' => 'wrong',
        ]);
        self::assertStringNotContainsString('Message saved', $rejected['body']);
    }

    public function testAnonymousAdminRedirectsToLogin(): void
    {
        self::$cookies = [];
        $response = self::request('GET', '/admin.php?action=pages');
        self::assertSame(302, $response['status']);
    }

    public function testLoginRejectsBadCsrf(): void
    {
        self::$cookies = [];
        self::request('GET', '/admin.php?action=login');
        $response = self::request('POST', '/admin.php?action=login', [
            'username' => 'admin', 'password' => self::PASSWORD, '_csrf' => 'invalid',
        ]);
        self::assertNotSame(302, $response['status']);
        // Still anonymous after the failed attempt.
        self::assertSame(302, self::request('GET', '/admin.php?action=pages')['status']);
    }

    public function testLoginDashboardNoStoreAndLogoutInvalidatesSession(): void
    {
        self::$cookies = [];
        $login = self::request('GET', '/admin.php?action=login');
        $csrf = self::csrfFrom($login['body']);
        self::assertNotSame('', $csrf);

        $auth = self::request('POST', '/admin.php?action=login', [
            'username' => 'admin', 'password' => self::PASSWORD, '_csrf' => $csrf,
        ]);
        self::assertSame(302, $auth['status']);

        $dashboard = self::request('GET', '/admin.php');
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('no-store', $dashboard['headers']['cache-control'] ?? '');
        self::assertStringContainsString('Administration', $dashboard['body']);

        $pages = self::request('GET', '/admin.php?action=pages');
        self::assertSame(200, $pages['status']);
        self::assertStringContainsString('Pages', $pages['body']);

        $logout = self::request('POST', '/admin.php?action=logout', ['_csrf' => self::csrfFrom($pages['body'])]);
        self::assertSame(303, $logout['status']);

        self::assertSame(302, self::request('GET', '/admin.php?action=pages')['status']);
    }

    public function testUnknownAdminActionOffersAWayBack(): void
    {
        self::$cookies = [];
        $response = self::request('GET', '/admin.php?action=no-such-action');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('That link does not open anything', $response['body']);
        // An operator on a stale bookmark needs the route that does exist.
        self::assertStringContainsString('href="/admin.php"', $response['body']);
        self::assertStringContainsString('no-store', $response['headers']['cache-control'] ?? '');
        // A stale link is not a server fault and must not be dressed as one.
        self::assertStringNotContainsString('Service unavailable', $response['body']);
    }

    public function testMalformedAdminActionShapeIsNotFoundNotACrash(): void
    {
        self::$cookies = [];
        // ?action[]=x arrives as an array. That is a client mistake; it used to
        // raise, get caught, and return 500 with nothing written to the log.
        $response = self::request('GET', '/admin.php?action%5B%5D=x');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('That link does not open anything', $response['body']);
    }

    public function testAdminBootFailureIsLoggedAndTheOperatorGetsAReference(): void
    {
        // No settings/config.php: the reporter itself cannot be constructed,
        // which is exactly when a silent 500 is most expensive.
        $root = sys_get_temp_dir() . '/segara-noboot-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));

        $command = 'SEGARA_DATA=' . escapeshellarg($root) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$projectRoot . '/public/admin.php');
        exec($command . ' 2>&1', $output, $code);
        $body = implode("\n", $output);

        self::assertSame(0, $code, 'the front controller reports through its response, not its exit code');
        self::assertMatchesRegularExpression('/Service unavailable\. Reference [0-9a-f]{16}\./', $body);

        self::assertSame(1, preg_match('/([0-9a-f]{16})/', $body, $match));
        $reference = $match[1] ?? '';
        self::assertNotSame('', $reference, 'the 500 body names a reference');

        $log = $root . '/store/logs/error.log';
        self::assertFileExists($log, 'a boot failure must leave a trace');
        self::assertStringContainsString($reference, (string) file_get_contents($log));

        @unlink($log);
        @rmdir($root . '/store/logs');
        @rmdir($root . '/store');
        @rmdir($root);
    }

    public function testAdminRuntimeFailureUsesTheSharedErrorPage(): void
    {
        // A readable config with an unreadable store: the reporter can be
        // built, so the failure must take the same route as every other 500 —
        // logged, and rendered as the shared page with a reference to quote.
        $root = sys_get_temp_dir() . '/segara-runtime-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/store', 0700, true));
        self::assertTrue(mkdir($root . '/settings', 0700, true));

        $config = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'base_url' => 'https://example.test',
            'base_path' => '',
            'environment' => 'test',
            'session' => ['idle_timeout' => 1800, 'absolute_timeout' => 28800],
            'cache' => ['enabled' => false],
            'captcha' => ['provider' => null, 'site_key' => null, 'secret_key' => null],
            'auth' => ['basic_realm' => 'Segara Administration'],
        ], true) . ";\n";
        self::assertNotFalse(file_put_contents($root . '/settings/config.php', $config));
        self::assertNotFalse(file_put_contents($root . '/store/segara.sqlite', 'this is not a database'));

        $command = 'SEGARA_DATA=' . escapeshellarg($root) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$projectRoot . '/public/admin.php');
        exec($command . ' 2>&1', $output, $code);
        $body = implode("\n", $output);

        self::assertStringContainsString('Service unavailable', $body);
        self::assertStringContainsString('quote the reference', $body, 'the shared 500 page, not a bare sentence');
        self::assertSame(1, preg_match('#<code>([0-9a-f]{16})</code>#', $body, $match));
        $reference = $match[1] ?? '';
        self::assertNotSame('', $reference, 'the shared 500 page names a reference');

        $log = $root . '/store/logs/error.log';
        self::assertFileExists($log, 'a runtime failure must leave a trace');
        self::assertStringContainsString($reference, (string) file_get_contents($log));

        foreach (['/store/logs/error.log', '/store/segara.sqlite', '/settings/config.php'] as $file) {
            @unlink($root . $file);
        }
        @rmdir($root . '/store/logs');
        @rmdir($root . '/store');
        @rmdir($root . '/settings');
        @rmdir($root);
    }

    /** @param array<string, mixed> $overrides */
    private static function seedPage(array $overrides): string
    {
        $pdo = Database::connect(self::$root . '/store/segara.sqlite');
        $page = (new PageRepository($pdo))->create($overrides + [
            'title' => 'Seeded', 'slug' => 'seeded', 'state' => 'published',
            'published_at' => '2026-10-25T00:00:00Z',
        ]);

        return $page['slug'];
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function request(string $method, string $path, array $form = []): array
    {
        $header = self::cookieHeader();
        $opts = [
            'http' => [
                'method' => $method,
                'header' => $header,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ],
        ];
        if ($method === 'POST' && $form !== []) {
            $opts['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opts['http']['content'] = http_build_query($form);
        }
        // Reset first: a failed request leaves the magic variable undefined.
        $http_response_header = [];
        $body = @file_get_contents(self::$baseUrl . $path, false, stream_context_create($opts));

        $status = 0;
        $headers = [];
        $responseHeaders = $http_response_header;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
            if (stripos($line, 'Set-Cookie:') === 0) {
                self::storeCookie($line);
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];
    }

    private static function cookieHeader(): string
    {
        if (self::$cookies === []) {
            return '';
        }
        $pairs = [];
        foreach (self::$cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }

    private static function storeCookie(string $header): void
    {
        $content = trim(substr($header, strlen('Set-Cookie:')));
        $first = trim(explode(';', $content, 2)[0]);
        if (!str_contains($first, '=')) {
            return;
        }
        [$name, $value] = explode('=', $first, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'deleted') === 0) {
            unset(self::$cookies[$name]);
        } else {
            self::$cookies[$name] = $value;
        }
    }

    private static function csrfFrom(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $html, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('No free port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name)) {
            throw new RuntimeException('No port name.');
        }
        $colon = strrpos($name, ':');
        if ($colon === false) {
            throw new RuntimeException('Unparseable port name: ' . $name);
        }

        return (int) substr($name, $colon + 1);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
