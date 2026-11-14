<?php

declare(strict_types=1);

namespace Segara\Http;

use InvalidArgumentException;
use RuntimeException;

final class Request
{
    public const DEFAULT_MAX_BODY_BYTES = 1048576;

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<array-key, mixed> $query
     */
    private function __construct(
        private readonly string $requestMethod,
        private readonly array $post,
        private readonly array $cookies,
        private readonly string $body,
        private readonly array $query,
    ) {
    }

    public static function fromGlobals(int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, mixed> $cookies */
        $cookies = $_COOKIE;

        return self::fromArrays($server, $post, $cookies, null, $maxBodyBytes);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     */
    public static function fromArrays(
        array $server,
        array $post = [],
        array $cookies = [],
        ?string $body = '',
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): self {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum request body size must be positive.');
        }

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($method) || !in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP request method.');
        }

        $contentLength = $server['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null && $contentLength !== '') {
            if (!is_string($contentLength) && !is_int($contentLength)) {
                throw new InvalidArgumentException('Malformed Content-Length header.');
            }
            $length = (string) $contentLength;
            if (preg_match('/^(0|[1-9][0-9]*)$/', $length) !== 1) {
                throw new InvalidArgumentException('Malformed Content-Length header.');
            }
            if (strlen($length) > 10 || (int) $length > $maxBodyBytes) {
                throw new InvalidArgumentException('Request body exceeds the configured limit.');
            }
        }

        if ($body === null) {
            $body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
            if ($body === false) {
                throw new RuntimeException('Unable to read the request body.');
            }
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new InvalidArgumentException('Request body exceeds the configured limit.');
        }

        $query = [];
        $queryString = $server['QUERY_STRING'] ?? '';
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        return new self($method, $post, $cookies, $body, $query);
    }

    public function method(): string
    {
        return $this->requestMethod;
    }

    public function isMutation(): bool
    {
        return $this->requestMethod === 'POST';
    }

    public function post(string $key): mixed
    {
        return $this->post[$key] ?? null;
    }

    public function query(string $key): mixed
    {
        return $this->query[$key] ?? null;
    }

    public function cookie(string $key): mixed
    {
        return $this->cookies[$key] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function files(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }
        $name = $file['name'] ?? null;
        $type = $file['type'] ?? null;
        $tmpName = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($name) || !is_string($type) || !is_string($tmpName) || !is_int($error) || !is_int($size)) {
            return null;
        }

        return ['name' => $name, 'type' => $type, 'tmp_name' => $tmpName, 'error' => $error, 'size' => $size];
    }
}
