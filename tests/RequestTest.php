<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testBaseUrlInfersSchemeAndHostFromRequest(): void
    {
        self::assertSame(
            'http://example.test:8080',
            Request::create('http://example.test:8080/')->baseUrl(),
        );

        self::assertSame(
            'https://example.test:8080',
            Request::create('https://example.test:8080/')->baseUrl(),
        );
    }

    public function testBaseUrlHonorsForwardedProto(): void
    {
        $request = Request::create('http://example.test/', server: [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame('https://example.test', $request->baseUrl());
    }

    public function testBaseUrlFallsBackToLocalhostWithoutHost(): void
    {
        $request = Request::create('/', server: [
            'HTTP_HOST' => '',
            'SERVER_NAME' => '',
        ]);

        self::assertSame('http://localhost', $request->baseUrl());
    }

    public function testPathIsTheRoutePathWithoutQuery(): void
    {
        self::assertSame('/', Request::create('http://example.test/')->path());
        self::assertSame('/about', Request::create('http://example.test/about?x=1')->path());
        self::assertSame('/about/', Request::create('http://example.test/about/')->path());
    }

    public function testBasePathIsEmptyAtWebRoot(): void
    {
        self::assertSame('', Request::create('http://example.test/about')->basePath());
    }

    public function testSubdirectoryInstallSplitsBasePathFromRoutePath(): void
    {
        $request = Request::create('http://example.test/blog/about/?x=1', server: [
            'SCRIPT_NAME' => '/blog/index.php',
            'SCRIPT_FILENAME' => '/var/www/blog/index.php',
        ]);

        self::assertSame('/blog', $request->basePath());
        self::assertSame('/about/', $request->path());
        self::assertSame('x=1', $request->query());
    }

    public function testQueryIsVerbatimAndEmptyWhenAbsent(): void
    {
        self::assertSame('', Request::create('http://example.test/about')->query());
        self::assertSame(
            'b=2&a=1%20z',
            Request::create('http://example.test/about?b=2&a=1%20z')->query(),
        );
    }

    public function testMethodIsUppercased(): void
    {
        self::assertSame('GET', Request::create('http://example.test/')->method());
        self::assertSame('POST', Request::create('http://example.test/', 'post')->method());
    }
}
