<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Request;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile as HttpFoundationUploadedFile;

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

    public function testHeaderIsCaseInsensitiveAndNullWhenAbsent(): void
    {
        $request = Request::create('http://example.test/', server: ['HTTP_X_PROBE' => 'on']);

        self::assertSame('on', $request->header('X-Probe'));
        self::assertSame('on', $request->header('x-probe'));
        self::assertNull($request->header('X-Missing'));
        self::assertSame('fallback', $request->header('X-Missing', 'fallback'));
    }

    public function testCookieFallsBackToDefault(): void
    {
        $request = Request::create('http://example.test/', cookies: ['seen' => '1']);

        self::assertSame('1', $request->cookie('seen'));
        self::assertSame('fallback', $request->cookie('missing', 'fallback'));
        self::assertNull($request->cookie('missing'));
    }

    public function testFormExposesSubmittedFields(): void
    {
        $request = Request::create('http://example.test/subscribe', 'POST', parameters: [
            'email' => 'reader@example.test',
            'tags' => ['a', 'b'],
        ]);

        self::assertSame(
            ['email' => 'reader@example.test', 'tags' => ['a', 'b']],
            $request->form(),
        );
        self::assertSame([], Request::create('http://example.test/')->form());
    }

    public function testBodyAndJsonDecodeThePayload(): void
    {
        $request = Request::create(
            'http://example.test/api',
            'POST',
            body: '{"email": "reader@example.test"}',
        );

        self::assertSame('{"email": "reader@example.test"}', $request->body());
        self::assertSame(['email' => 'reader@example.test'], $request->json());
        self::assertSame([], Request::create('http://example.test/api', 'POST')->json());
        self::assertSame([], Request::create('http://example.test/api', 'POST', body: '5')->json());
    }

    public function testJsonThrowsOnMalformedBody(): void
    {
        $this->expectException(JsonException::class);

        Request::create('http://example.test/api', 'POST', body: '{nope')->json();
    }

    public function testFileWrapsAnUploadAndMovesIt(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'garner-upload-');
        assert(is_string($source), 'tempnam() must produce a file for the upload fixture');
        file_put_contents($source, 'attachment-bytes');

        $request = Request::create('http://example.test/upload', 'POST', files: [
            'doc' => new HttpFoundationUploadedFile($source, 'notes.txt', 'text/plain', null, true),
        ]);

        $file = $request->file('doc');

        self::assertNotNull($file);
        self::assertNull($request->file('missing'));
        self::assertSame('notes.txt', $file->name());
        self::assertSame('text/plain', $file->clientMimeType());
        self::assertSame(16, $file->size());
        self::assertTrue($file->valid());

        $destination = sys_get_temp_dir() . '/garner-upload-dest-' . bin2hex(random_bytes(6));

        try {
            $moved = $file->moveTo($destination, 'saved.txt');

            self::assertSame($destination . '/saved.txt', $moved);
            self::assertSame('attachment-bytes', file_get_contents($moved));

            // Metadata still describes the submission after the move consumed
            // the temporary file — including through a repeat file() lookup,
            // which must return the same wrapper instance.
            self::assertSame('notes.txt', $file->name());
            self::assertSame(16, $file->size());
            self::assertTrue($file->valid());
            self::assertSame($file, $request->file('doc'));
        } finally {
            if (is_file($destination . '/saved.txt')) {
                unlink($destination . '/saved.txt');
            }

            if (is_dir($destination)) {
                rmdir($destination);
            }

            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testIsHtmxDetectsTheHxRequestHeader(): void
    {
        self::assertTrue(
            Request::create('http://example.test/', server: [
                'HTTP_HX_REQUEST' => 'true',
            ])->isHtmx(),
        );
        self::assertFalse(Request::create('http://example.test/')->isHtmx());
    }
}
