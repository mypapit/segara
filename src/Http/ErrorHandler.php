<?php

declare(strict_types=1);

namespace Segara\Http;

use Segara\Config;
use Segara\Support\Logger;
use Throwable;

/**
 * Production exception handling: suppress display, log a redacted record with
 * a random reference ID, and return a generic, non-cacheable 500 page that
 * leaks no paths, class names, or secrets.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    public function register(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_exception_handler(function (Throwable $exception): void {
            $this->handle($exception)->send();
        });
    }

    public function handle(Throwable $exception): Response
    {
        $id = bin2hex(random_bytes(8));
        $this->logger->error($id, $exception->getMessage(), [
            'class' => $exception::class,
            'line' => $exception->getLine(),
        ]);

        return (new Response(
            500,
            SecurityHeaders::forConfig($this->config)
                + ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
            $this->render500($id),
        ))->asSensitive();
    }

    private function render500(string $id): string
    {
        $template = dirname(__DIR__, 2) . '/themes/clarity/500.php';
        $errorId = $id;
        $basePath = $this->config->basePath;
        ob_start();
        try {
            require $template;
            $body = ob_get_clean();

            return is_string($body) ? $body : '';
        } catch (Throwable) {
            ob_end_clean();

            return '<!doctype html><html lang="en"><body><main><h1>Service unavailable</h1></main></body></html>';
        }
    }
}
