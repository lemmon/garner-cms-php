<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\OriginCheck;
use Garner\Core\Request;
use PHPUnit\Framework\TestCase;

final class OriginCheckTest extends TestCase
{
    private const string FORM = 'application/x-www-form-urlencoded';

    /**
     * @param array<string, mixed> $server
     */
    private static function post(array $server = [], string $contentType = self::FORM): Request
    {
        return Request::create('http://example.test/subscribe', 'POST', [
            'CONTENT_TYPE' => $contentType,
            ...$server,
        ]);
    }

    public function testSameOriginFormPostPasses(): void
    {
        self::assertFalse(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'http://example.test',
        ])));
    }

    public function testCrossOriginFormPostIsRejected(): void
    {
        self::assertTrue(OriginCheck::rejects(self::post(['HTTP_ORIGIN' => 'http://evil.test'])));

        // Port and sibling-subdomain differences are cross-origin.
        self::assertTrue(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'http://example.test:8080',
        ])));
        self::assertTrue(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'http://sub.example.test',
        ])));

        // The literal "null" origin (sandboxed iframe, data: URL) never matches.
        self::assertTrue(OriginCheck::rejects(self::post(['HTTP_ORIGIN' => 'null'])));
    }

    public function testHttpsOriginMatchesHttpBaseBehindProtocolBlindProxy(): void
    {
        // TLS terminated upstream, no X-Forwarded-Proto: PHP sees http, the
        // browser saw https. Same host must still count as same-origin.
        self::assertFalse(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'https://example.test',
        ])));

        // The reverse direction stays cross-origin: an http page posting to
        // the https site is not a proxy artifact.
        $request = Request::create('https://example.test/subscribe', 'POST', [
            'CONTENT_TYPE' => self::FORM,
            'HTTP_ORIGIN' => 'http://example.test',
        ]);

        self::assertTrue(OriginCheck::rejects($request));
    }

    public function testSecFetchSiteOutranksAnUnverifiableOrigin(): void
    {
        // The browser's own site classification wins over the Origin
        // comparison, which can misfire when a proxy hides the scheme.
        self::assertFalse(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'https://something-else.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ])));
        self::assertTrue(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'http://example.test',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ])));
    }

    public function testOriginComparisonIsCaseInsensitive(): void
    {
        self::assertFalse(OriginCheck::rejects(self::post([
            'HTTP_ORIGIN' => 'HTTP://EXAMPLE.TEST',
        ])));
    }

    public function testSecFetchSiteDecidesWhenOriginIsAbsent(): void
    {
        self::assertTrue(OriginCheck::rejects(self::post([
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ])));
        self::assertTrue(OriginCheck::rejects(self::post([
            'HTTP_SEC_FETCH_SITE' => 'same-site',
        ])));
        self::assertFalse(OriginCheck::rejects(self::post([
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ])));
        self::assertFalse(OriginCheck::rejects(self::post(['HTTP_SEC_FETCH_SITE' => 'none'])));
    }

    public function testNonBrowserClientsWithoutSignalsPass(): void
    {
        self::assertFalse(OriginCheck::rejects(self::post()));
    }

    public function testOnlyFormContentTypesAreChecked(): void
    {
        $evil = ['HTTP_ORIGIN' => 'http://evil.test'];

        self::assertFalse(OriginCheck::rejects(self::post($evil, 'application/json')));
        self::assertTrue(OriginCheck::rejects(self::post(
            $evil,
            'multipart/form-data; boundary=----x',
        )));
        self::assertTrue(OriginCheck::rejects(self::post($evil, 'text/plain; charset=utf-8')));
        self::assertTrue(OriginCheck::rejects(self::post(
            $evil,
            'APPLICATION/X-WWW-FORM-URLENCODED',
        )));
    }

    public function testOnlyPostIsChecked(): void
    {
        $request = Request::create('http://example.test/subscribe', 'GET', [
            'CONTENT_TYPE' => self::FORM,
            'HTTP_ORIGIN' => 'http://evil.test',
        ]);

        self::assertFalse(OriginCheck::rejects($request));
    }
}
