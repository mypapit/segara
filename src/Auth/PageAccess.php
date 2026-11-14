<?php

declare(strict_types=1);

namespace Segara\Auth;

use PDO;
use Segara\Http\Request;

final class PageAccess
{
    public function __construct(private readonly PDO $pdo, private readonly Session $session, private readonly Csrf $csrf)
    {
    }

    public function unlocked(string $pageId, ?string $hash = null): bool
    {
        $this->session->start();
        $unlocked = $_SESSION['_segara_unlocked_pages'] ?? null;
        if (!is_array($unlocked) || !isset($unlocked[$pageId])) {
            return false;
        }
        // Keep the flag tied to the hash that granted it, so rotating the
        // password closes every session that unlocked the old one.
        if ($hash !== null && $unlocked[$pageId] !== hash('sha256', $hash)) {
            return false;
        }

        return true;
    }

    public function challenge(string $pageId, string $hash, Request $request): bool
    {
        if ($request->method() !== 'POST' || !$this->csrf->validate($request)) {
            return false;
        }
        $password = $request->post('password');
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            return false;
        }
        $key = hash('sha256', 'page-access:' . $pageId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT failures, window_started, retry_after FROM throttle WHERE key = :key');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();
            $now = time();
            $retryAfter = is_array($row) ? $row['retry_after'] ?? null : null;
            if (is_numeric($retryAfter) && (int) $retryAfter > $now) {
                $this->pdo->commit();
                return false;
            }
            if (password_verify($password, $hash)) {
                $delete = $this->pdo->prepare('DELETE FROM throttle WHERE key = :key');
                $delete->execute(['key' => $key]);
                $this->pdo->commit();
                $this->session->start();
        $unlockedPages = $_SESSION['_segara_unlocked_pages'] ?? null;
                if (!is_array($unlockedPages)) {
                    $unlockedPages = [];
                }
                $unlockedPages[$pageId] = hash('sha256', $hash);
                $_SESSION['_segara_unlocked_pages'] = $unlockedPages;
                return true;
            }
            $windowStarted = is_array($row) ? $row['window_started'] ?? null : null;
            $priorFailures = is_array($row) ? $row['failures'] ?? null : null;
            $activeWindow = is_numeric($windowStarted) && (int) $windowStarted + 900 > $now;
            $failures = $activeWindow && is_numeric($priorFailures) ? (int) $priorFailures + 1 : 1;
            $retry = $failures >= 5 ? $now + 300 : 0;
            $write = $this->pdo->prepare('INSERT INTO throttle (key, failures, window_started, retry_after, updated_at) VALUES (:key,:failures,:started,:retry,:updated) ON CONFLICT(key) DO UPDATE SET failures=excluded.failures, window_started=excluded.window_started, retry_after=excluded.retry_after, updated_at=excluded.updated_at');
            $write->execute(['key'=>$key, 'failures'=>$failures, 'started'=>$now, 'retry'=>$retry, 'updated'=>gmdate('Y-m-d\\TH:i:s\\Z')]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return false;
    }
}
