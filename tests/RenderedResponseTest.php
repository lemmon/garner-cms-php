<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Render\RenderedResponse;
use PHPUnit\Framework\TestCase;

final class RenderedResponseTest extends TestCase
{
    public function testWithHeaderSetsReplacesAndLeavesTheOriginalUntouched(): void
    {
        $original = RenderedResponse::json(['ok' => true]);
        $tagged = $original->withHeader('X-Robots-Tag', 'noindex');

        self::assertNull($original->header('X-Robots-Tag'));
        self::assertSame('noindex', $tagged->header('x-robots-tag'));
        self::assertSame('all', $tagged->withHeader('X-Robots-Tag', 'all')->header('X-Robots-Tag'));
        self::assertSame($original->body(), $tagged->body());
        self::assertSame('application/json; charset=utf-8', $tagged->contentType());
    }

    public function testWithCookieAddsSetCookieWithSafeDefaults(): void
    {
        $plain = RenderedResponse::text('ok');
        $cookies = $plain->withCookie('seen', '1')->cookies();

        self::assertSame([], $plain->cookies());
        self::assertCount(1, $cookies);
        self::assertStringContainsString('seen=1', $cookies[0]);
        self::assertStringContainsString('path=/', $cookies[0]);
        self::assertStringContainsString('httponly', $cookies[0]);
        self::assertStringContainsString('samesite=lax', $cookies[0]);
    }

    public function testWithCookieKeepsMultipleCookiesInOrder(): void
    {
        $cookies = RenderedResponse::text('ok')
            ->withCookie('first', 'a')
            ->withCookie('second', 'b', secure: true)
            ->cookies();

        self::assertCount(2, $cookies);
        self::assertStringStartsWith('first=a', $cookies[0]);
        self::assertStringStartsWith('second=b', $cookies[1]);
        self::assertStringContainsString('secure', $cookies[1]);
    }

    public function testRedirectCarriesLocationAlongsideExtraHeaders(): void
    {
        $response = RenderedResponse::redirect('/next', 303)->withHeader('X-Reason', 'form');

        self::assertSame(303, $response->status());
        self::assertSame('/next', $response->location());
        self::assertSame('form', $response->header('X-Reason'));
        self::assertSame('', $response->body());
    }

    public function testNoCacheControlHeaderIsSentByDefault(): void
    {
        self::assertNull(RenderedResponse::html('<p>hi</p>')->header('Cache-Control'));
    }

    public function testValidatorHeadersDoNotResurrectCacheControl(): void
    {
        $response = RenderedResponse::html('<p>hi</p>')
            ->withHeader('ETag', '"v1"')
            ->withHeader('Last-Modified', 'Sat, 04 Jul 2026 00:00:00 GMT');

        self::assertSame('"v1"', $response->header('ETag'));
        self::assertNull($response->header('Cache-Control'));
    }

    public function testExplicitCacheControlIsKeptByteVerbatim(): void
    {
        $plain = RenderedResponse::html('<p>hi</p>')->withHeader('Cache-Control', 'max-age=60');
        $public = RenderedResponse::html('<p>hi</p>')
            ->withHeader('Cache-Control', 'public, max-age=60')
            ->withHeader('ETag', '"v1"');
        $quoted = RenderedResponse::html('<p>hi</p>')->withHeader(
            'Cache-Control',
            'no-cache="Set-Cookie", private',
        );

        self::assertSame('max-age=60', $plain->header('Cache-Control'));
        self::assertSame('public, max-age=60', $public->header('Cache-Control'));
        self::assertSame('no-cache="Set-Cookie", private', $quoted->header('Cache-Control'));
    }

    public function testSendOutputsTheBody(): void
    {
        ob_start();
        RenderedResponse::text('hello')->send();

        self::assertSame('hello', ob_get_clean());
    }
}
