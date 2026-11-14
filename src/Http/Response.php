<?php

declare(strict_types=1);

namespace Segara\Http;

use InvalidArgumentException;

final class Response
{
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP response status.');
        }

        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[self::headerName($name)] = self::headerValue($value);
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[self::headerName($name)] = self::headerValue($value);

        return $clone;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[self::headerName($name)] = self::headerValue($value);
        }

        return $clone;
    }

    public function asSensitive(): self
    {
        return $this->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
    }

    private static function headerName(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid HTTP response header name.');
        }

        return $name;
    }

    private static function headerValue(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid HTTP response header value.');
        }

        return $value;
    }
}
