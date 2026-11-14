<?php

declare(strict_types=1);

namespace Segara\Contact;

use InvalidArgumentException;
use PDO;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Http\Request;

final class ContactController
{
    private const WINDOW_SECONDS = 900;
    private const MAX_SUBMISSIONS = 10;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Render the contact form, processing a submission on POST. Returns the full
     * form fragment including any saved confirmation or validation error.
     */
    public function form(Request $request): string
    {
        $saved = null;
        $error = null;
        if ($request->method() === 'POST') {
            [$saved, $error] = $this->submit($request);
        }

        $csrfToken = $this->csrf->token();
        $template = dirname(__DIR__, 2) . '/themes/shared/contact-form.php';
        ob_start();
        try {
            require $template;
            $html = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        return is_string($html) ? $html : '';
    }

    /** @return array{?string, ?string} */
    private function submit(Request $request): array
    {
        $honeypot = $request->post('website');
        if ($honeypot !== null && $honeypot !== '') {
            return [null, null];
        }
        if (!$this->csrf->validate($request)) {
            return [null, 'The form expired. Please try again.'];
        }
        if ($this->throttled()) {
            return [null, 'Too many messages. Please try again later.'];
        }

        try {
            $this->messages->create([
                'name' => $request->post('name'),
                'email' => $request->post('email'),
                'subject' => $request->post('subject'),
                'body' => $request->post('message'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return [null, $exception->getMessage()];
        }

        $this->recordSubmission();

        return ['Message saved. No email was sent.', null];
    }

    private function throttled(): bool
    {
        $now = time();
        $statement = $this->pdo->prepare('SELECT failures, window_started FROM throttle WHERE key = :key');
        $statement->execute(['key' => $this->throttleKey()]);
        $row = $statement->fetch();
        if ($row === false || !is_array($row)) {
            return false;
        }
        $startedValue = $row['window_started'] ?? $now;
        if (!is_int($startedValue) && !is_string($startedValue)) {
            return false;
        }
        if ($now - (int) $startedValue >= self::WINDOW_SECONDS) {
            return false;
        }
        $failures = $row['failures'] ?? 0;
        if (!is_int($failures) && !is_string($failures)) {
            return false;
        }

        return (int) $failures >= self::MAX_SUBMISSIONS;
    }

    private function recordSubmission(): void
    {
        $now = time();
        $key = $this->throttleKey();
        $statement = $this->pdo->prepare(
            'INSERT INTO throttle (key, failures, window_started, retry_after, updated_at) '
            . 'VALUES (:key, 1, :now, :retry, :updated) '
            . 'ON CONFLICT(key) DO UPDATE SET '
            . 'failures = CASE WHEN :now - throttle.window_started >= :window THEN 1 ELSE throttle.failures + 1 END, '
            . 'window_started = CASE WHEN :now - throttle.window_started >= :window THEN :now ELSE throttle.window_started END, '
            . 'retry_after = :retry, updated_at = :updated'
        );
        $statement->execute([
            'key' => $key,
            'now' => $now,
            'retry' => $now + self::WINDOW_SECONDS,
            'updated' => gmdate('Y-m-d\TH:i:s\Z'),
            'window' => self::WINDOW_SECONDS,
        ]);

        // Bounded storage: retire expired contact throttle windows.
        $this->pdo->prepare('DELETE FROM throttle WHERE key LIKE :prefix AND retry_after < :cutoff')
            ->execute(['prefix' => 'contact:%', 'cutoff' => $now]);
    }

    private function throttleKey(): string
    {
        return 'contact:' . $this->session->id();
    }
}
