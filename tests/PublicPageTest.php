<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Segara\Auth\Csrf;
use Segara\Auth\PageAccess;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Pages\PageRepository;
use Segara\PublicSite\PageController;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

final class PublicPageTest extends TestCase
{
    private string $sessionPath;
    private PDO $pdo;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private PageAccess $access;
    private PageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionPath = $this->temporaryRoot . '/sessions';
        mkdir($this->sessionPath, 0700);
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->config = Config::fromArray(['base_url' => 'https://example.test']);
        $this->session = new Session($this->config, $this->sessionPath);
        $this->csrf = new Csrf($this->session);
        $this->access = new PageAccess($this->pdo, $this->session, $this->csrf);
        $this->controller = new PageController(
            new PageRepository($this->pdo),
            $this->access,
            new Renderer($this->config),
            $this->csrf,
        );
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
        parent::tearDown();
    }

    public function testClarityTemplatesAreAllowedAndRenderEscapedPageContent(): void
    {
        $config = Config::fromArray(['base_url' => 'https://example.test']);
        $renderer = new Renderer($config);
        $html = $renderer->render('clarity', 'page', [
            'title' => '<unsafe>', 'body' => '<p>safe rendered Markdown</p>', 'challenge' => false, 'csrfToken' => '',
        ]);
        self::assertStringContainsString('&lt;unsafe&gt;', $html);
        self::assertStringContainsString('<p>safe rendered Markdown</p>', $html);
        self::assertStringNotContainsString('<unsafe>', $html);
    }

    public function testErrorTemplateProducesPublicNotFoundDocument(): void
    {
        $config = Config::fromArray(['base_url' => 'https://example.test']);
        $html = (new Renderer($config))->render('clarity', 'error');
        self::assertStringContainsString('404 — Page not found', $html);
    }

    public function testPublishedPageRendersMarkdownBodyAndIsCacheable(): void
    {
        $this->makePage('hello-world', 'published', '# Hello');
        $response = $this->controller->show('hello-world', Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<h1>Hello</h1>', $response->body());
        self::assertArrayNotHasKey('Cache-Control', $response->headers());
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function nonPublicSlugs(): array
    {
        return [
            'missing' => ['missing-page', null],
            'draft' => ['draft-page', 'draft'],
            'trash' => ['trash-page', 'trash'],
            'uppercase' => ['UPPERCASE', null],
            'traversal' => ['../secret', null],
        ];
    }

    /**
     * @dataProvider nonPublicSlugs
     */
    #[DataProvider('nonPublicSlugs')]
    public function testNonPublicOrInvalidSlugsReturnSensitive404(string $slug, ?string $state): void
    {
        if ($state !== null) {
            $this->makePage($slug, $state, 'secret body');
        }
        $response = $this->controller->show($slug, Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertSame(404, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertStringContainsString('404', $response->body());
        self::assertStringNotContainsString('secret body', $response->body());
    }

    public function testPrivatePageChallengeHidesContentAndIsNotCacheable(): void
    {
        $this->makePage('private-page', 'private', 'secret payload here');
        $response = $this->controller->show('private-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertSame(200, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertStringContainsString('name="password"', $response->body());
        self::assertStringContainsString('name="_csrf"', $response->body());
        self::assertStringNotContainsString('secret payload here', $response->body());
    }

    public function testPrivatePageChallengeDoesNotDiscloseTheTitle(): void
    {
        (new PageRepository($this->pdo))->create([
            'title' => 'Board minutes confidential',
            'slug' => 'board-minutes',
            'body_markdown' => 'secret payload here',
            'state' => 'private',
            'password_hash' => password_hash('correct horse', PASSWORD_DEFAULT),
        ]);

        $response = $this->controller->show('board-minutes', Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Protected page', $response->body());
        self::assertStringNotContainsString('Board minutes confidential', $response->body());
        self::assertStringNotContainsString('board-minutes', $response->body());
    }

    public function testMissingAndUnpublishedPagesShareOneResponseBody(): void
    {
        $this->makePage('draft-page', 'draft', 'secret body');
        $this->makePage('trash-page', 'trash', 'secret body');

        $missing = $this->controller->show('no-such-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));
        $draft = $this->controller->show('draft-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));
        $trash = $this->controller->show('trash-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertSame(404, $draft->status());
        self::assertSame($missing->body(), $draft->body());
        self::assertSame($missing->body(), $trash->body());
    }

    public function testNotFoundCopyNeverHintsAtPublicationState(): void
    {
        foreach (['clarity', 'aubergine', 'editorial', 'folio', 'slate', 'scholar'] as $theme) {
            $body = (new Renderer($this->config))->render($theme, 'error');

            self::assertStringContainsString('404', $body, $theme);
            foreach (['publish', 'draft', 'private', 'trash', 'password'] as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $body, $theme . ' leaks "' . $word . '"');
            }
        }
    }

    public function testPrivatePageRejectsBadCsrfWithoutUnlocking(): void
    {
        $page = $this->makePage('private-page', 'private', 'secret payload here');
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'correct horse', '_csrf' => 'wrong-token'],
        );
        $response = $this->controller->show('private-page', $request);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="password"', $response->body());
        self::assertStringNotContainsString('secret payload here', $response->body());
        self::assertFalse($this->access->unlocked($page['id']));
    }

    public function testPrivatePageRejectsWrongPasswordWithoutUnlocking(): void
    {
        $page = $this->makePage('private-page', 'private', 'secret payload here');
        $token = $this->csrf->token();
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'not the password', '_csrf' => $token],
        );
        $response = $this->controller->show('private-page', $request);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="password"', $response->body());
        self::assertStringNotContainsString('secret payload here', $response->body());
        self::assertFalse($this->access->unlocked($page['id']));
    }

    public function testPrivatePageUnlocksOnValidPasswordAndShowsContent(): void
    {
        $page = $this->makePage('private-page', 'private', 'secret payload here');
        $token = $this->csrf->token();
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'correct horse', '_csrf' => $token],
        );
        $response = $this->controller->show('private-page', $request);

        self::assertSame(200, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertStringContainsString('secret payload here', $response->body());
        self::assertTrue($this->access->unlocked($page['id']));

        $followUp = $this->controller->show('private-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));
        self::assertSame(200, $followUp->status());
        self::assertSame('no-store, private', $followUp->headers()['Cache-Control']);
        self::assertStringContainsString('secret payload here', $followUp->body());
    }

    public function testPrivatePageThrottlesAfterRepeatedFailures(): void
    {
        $page = $this->makePage('private-page', 'private', 'secret payload here');
        $token = $this->csrf->token();

        for ($i = 0; $i < 5; $i++) {
            $badPassword = Request::fromArrays(
                ['REQUEST_METHOD' => 'POST'],
                ['password' => 'wrong', '_csrf' => $token],
            );
            self::assertFalse($this->access->challenge($page['id'], (string) $page['password_hash'], $badPassword));
            self::assertFalse($this->access->unlocked($page['id']));
        }

        $goodPassword = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'correct horse', '_csrf' => $token],
        );
        self::assertFalse($this->access->challenge($page['id'], (string) $page['password_hash'], $goodPassword));
        self::assertFalse($this->access->unlocked($page['id']));

        $statement = $this->pdo->query('SELECT retry_after FROM throttle');
        self::assertNotFalse($statement);
        self::assertGreaterThan(time(), (int) $statement->fetchColumn());
    }

    public function testAWrongPasswordSaysSoInsteadOfSilentlyRedrawingTheForm(): void
    {
        $this->makePage('private-page', 'private', 'secret payload here');
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'not the password', '_csrf' => $this->csrf->token()],
        );
        $response = $this->controller->show('private-page', $request);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('role="alert"', $response->body());
        self::assertStringContainsString('not correct', $response->body());
        self::assertStringNotContainsString('secret payload here', $response->body());
    }

    public function testTheFirstVisitShowsNoError(): void
    {
        $this->makePage('private-page', 'private', 'secret payload here');
        $response = $this->controller->show('private-page', Request::fromArrays(['REQUEST_METHOD' => 'GET']));

        self::assertStringNotContainsString('not correct', $response->body());
    }

    public function testRotatingThePasswordRevokesAnAlreadyUnlockedSession(): void
    {
        $page = $this->makePage('private-page', 'private', 'secret payload here');
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['password' => 'correct horse', '_csrf' => $this->csrf->token()],
        );
        $this->controller->show('private-page', $request);
        self::assertTrue($this->access->unlocked($page['id']));

        $rotated = password_hash('a different secret', PASSWORD_DEFAULT);
        self::assertFalse(
            $this->access->unlocked($page['id'], $rotated),
            'a session that unlocked the old hash must not survive rotation',
        );
        self::assertTrue($this->access->unlocked($page['id'], (string) $page['password_hash']));
    }

    /**
     * @return array{id: string, password_hash: ?string}
     */
    private function makePage(string $slug, string $state, string $body = ''): array
    {
        $input = [
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'body_markdown' => $body,
            'state' => $state,
        ];
        if ($state === 'private') {
            $input['password_hash'] = password_hash('correct horse', PASSWORD_DEFAULT);
        }

        return (new PageRepository($this->pdo))->create($input);
    }
}
