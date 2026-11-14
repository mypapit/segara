<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Segara\Http\Request;
use Tests\Support\TestCase;

final class RequestTest extends TestCase
{
    public function testEmptyContentLengthIsTreatedAsNoBody(): void
    {
        // nginx/FastCGI sends CONTENT_LENGTH as an empty string for GET/HEAD
        // requests without a body; this must not be treated as malformed.
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => ''],
            [],
            [],
            '',
        );

        self::assertSame('GET', $request->method());
        self::assertSame('', $request->body());
    }

    public function testZeroContentLengthIsAccepted(): void
    {
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => '0'],
            [],
            [],
            '',
        );

        self::assertSame('GET', $request->method());
    }

    public function testValidContentLengthWithBodyIsAccepted(): void
    {
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '5'],
            [],
            [],
            'hello',
        );

        self::assertSame('POST', $request->method());
        self::assertSame('hello', $request->body());
    }

    public function testMalformedContentLengthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Request::fromArrays(['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => 'abc'], [], [], '');
    }

    public function testOversizedContentLengthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Request::fromArrays(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '999999999999'], [], [], '');
    }
}
