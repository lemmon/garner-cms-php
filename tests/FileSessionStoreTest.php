<?php

declare(strict_types=1);

namespace Garner\Tests;

use ErrorException;
use Garner\Core\FileSessionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileSessionStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/garner-session-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->path);
    }

    public function testWriteThenReadReturnsData(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('abc123', ['user' => 'alice'], 3600);

        self::assertTrue($store->exists('abc123'));
        self::assertSame(['user' => 'alice'], $store->read('abc123'));
    }

    public function testUnknownIdReadsEmptyAndDoesNotExist(): void
    {
        $store = new FileSessionStore($this->path);

        self::assertFalse($store->exists('never-written'));
        self::assertSame([], $store->read('never-written'));
    }

    public function testExpiredDataIsTreatedAsAbsent(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('expired', ['user' => 'alice'], -1);

        self::assertFalse($store->exists('expired'));
        self::assertSame([], $store->read('expired'));
    }

    public function testDestroyRemovesData(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('abc123', ['user' => 'alice'], 3600);
        $store->destroy('abc123');

        self::assertFalse($store->exists('abc123'));
        self::assertSame([], $store->read('abc123'));
    }

    public function testDestroyingAnUnknownIdIsANoop(): void
    {
        $store = new FileSessionStore($this->path);

        $store->destroy('never-written');

        self::assertFalse($store->exists('never-written'));
    }

    public function testPathTraversalIdsAreRejectedRatherThanTrusted(): void
    {
        $store = new FileSessionStore($this->path);

        self::assertFalse($store->exists('../../etc/passwd'));
        self::assertSame([], $store->read('../../etc/passwd'));

        $this->expectException(RuntimeException::class);
        $store->write('../../etc/passwd', ['user' => 'alice'], 3600);
    }

    /**
     * The app registers an error handler that promotes warnings to
     * ErrorException. The store's filesystem calls race with concurrent
     * requests and gc (files can vanish or be unreadable between check and
     * use), so those expected warnings must be muted internally — an
     * unreadable session file behaves like an unknown session, not a 500.
     */
    public function testAnUnreadableFileReadsAsAbsentUnderAThrowingErrorHandler(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('abc123', ['user' => 'alice'], 3600);
        chmod($this->path . '/abc123.session', 0o000);

        $this->withThrowingErrorHandler(static function () use ($store): void {
            self::assertFalse($store->exists('abc123'));
            self::assertSame([], $store->read('abc123'));
        });
    }

    public function testAnUncreatableDirectoryThrowsARuntimeExceptionNotAPromotedWarning(): void
    {
        // The parent path is a plain file, so mkdir() can only warn and fail;
        // the store must swallow the warning and raise its own exception.
        mkdir($this->path, 0o700, true);
        file_put_contents($this->path . '/blocker', '');
        $store = new FileSessionStore($this->path . '/blocker/nested');

        $this->withThrowingErrorHandler(function () use ($store): void {
            $this->expectException(RuntimeException::class);
            $store->write('abc123', ['user' => 'alice'], 3600);
        });
    }

    private function withThrowingErrorHandler(callable $callback): void
    {
        set_error_handler(static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0,
        ): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }

    public function testDirectoryAndFilesAreCreatedOwnerOnly(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('abc123', ['user' => 'alice'], 3600);

        // Session files hold per-visitor state and their names are the
        // bearer-token ids; on a shared host neither may be readable (nor
        // the directory traversable) by other local users.
        self::assertSame(0o700, fileperms($this->path) & 0o777);
        self::assertSame(0o600, fileperms($this->path . '/abc123.session') & 0o777);
    }

    public function testGcSweepsStaleTempFilesButSparesFreshOnes(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('live', ['user' => 'alice'], 3600);

        $stale = $this->path . '/orphan.session.deadbeef.tmp';
        $fresh = $this->path . '/inflight.session.cafef00d.tmp';
        file_put_contents($stale, 'crashed mid-write');
        touch($stale, time() - 120);
        file_put_contents($fresh, 'write in progress');

        $store->gc();

        self::assertFalse(
            is_file($stale),
            'a temp file older than the grace period is residue of a crash',
        );
        self::assertTrue(is_file($fresh), 'a fresh temp file may belong to an in-flight write');
        self::assertTrue($store->exists('live'));
    }

    public function testGcSweepsExpiredSessionsButKeepsLiveOnes(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('live', ['user' => 'alice'], 3600);
        $store->write('dead', ['user' => 'bob'], -1);

        $store->gc();

        self::assertTrue($store->exists('live'));
        self::assertFalse(is_file($this->path . '/dead.session'));
    }

    /**
     * The reason this store uses serialize() rather than json_encode(): a
     * whole-number float is a documented JSON precision trap — depending on
     * serialize_precision, json_encode(2.0) can emit "2" indistinguishably
     * from an int, and the type is lost on decode. serialize() has no such
     * ambiguity.
     */
    public function testWholeNumberFloatsSurviveExactly(): void
    {
        $store = new FileSessionStore($this->path);
        $original = ['price' => 2.0, 'active' => false, 'count' => 0];

        $store->write('abc123', $original, 3600);

        self::assertSame($original, $store->read('abc123'));
    }

    /**
     * json_decode(..., true) always turns a JSON object into a plain PHP
     * array — a value that started life as an object has no way to say so
     * any more. unserialize() with allowed_classes: false still refuses to
     * instantiate the original class (closing the object-injection route),
     * but the value survives as an object (Incomplete_Class), not a plain
     * array — the distinction JSON can never preserve.
     */
    public function testAnObjectValueStaysAnObjectNotAPlainArray(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('abc123', ['profile' => new DummySessionValue()], 3600);

        $read = $store->read('abc123');

        self::assertIsObject($read['profile']);
    }

    public function testCorruptedFileContentsAreTreatedAsAbsentRatherThanFatal(): void
    {
        $store = new FileSessionStore($this->path);
        mkdir($this->path, 0o755, true);
        file_put_contents($this->path . '/corrupt.session', 'not a valid serialized payload');

        self::assertFalse($store->exists('corrupt'));
        self::assertSame([], $store->read('corrupt'));
    }

    public function testGcSweepsCorruptFilesTooNotJustExpiredOnes(): void
    {
        $store = new FileSessionStore($this->path);
        $store->write('live', ['user' => 'alice'], 3600);
        file_put_contents($this->path . '/corrupt.session', 'not a valid serialized payload');

        $store->gc();

        self::assertTrue($store->exists('live'));
        self::assertFalse(is_file($this->path . '/corrupt.session'));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
