<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Cache;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

final class CacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testSetGetAndHasRoundTrip(): void
    {
        $cache = $this->cache();

        $cache->set('greeting', 'hello');

        self::assertSame('hello', $cache->get('greeting'));
        self::assertTrue($cache->has('greeting'));
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function testStoredNullWinsOverTheDefault(): void
    {
        $cache = $this->cache();
        $cache->set('nothing', null);

        self::assertNull($cache->get('nothing', 'fallback'));
        self::assertTrue($cache->has('nothing'));
    }

    public function testSerializationPreservesPhpValueShapesAndClasses(): void
    {
        $cache = $this->cache();
        $emptyObject = new stdClass();
        $object = new DummyCacheValue('example', [1, 2, 3]);
        $value = [
            'empty_array' => [],
            'empty_object' => $emptyObject,
            'whole_float' => 2.0,
            'object' => $object,
        ];

        $cache->set('shapes', $value);
        $stored = $cache->get('shapes');

        self::assertIsArray($stored);
        self::assertSame([], $stored['empty_array']);
        self::assertInstanceOf(stdClass::class, $stored['empty_object']);
        self::assertSame([], get_object_vars($stored['empty_object']));
        self::assertSame(2.0, $stored['whole_float']);
        self::assertInstanceOf(DummyCacheValue::class, $stored['object']);
        self::assertSame('example', $stored['object']->name);
        self::assertSame([1, 2, 3], $stored['object']->items);
    }

    public function testSetIsAnUpsert(): void
    {
        $cache = $this->cache();

        $cache->set('counter', 1);
        $cache->set('counter', 2);

        self::assertSame(2, $cache->get('counter'));
    }

    public function testRemoveDeletesAValue(): void
    {
        $cache = $this->cache();
        $cache->set('key', 'value');

        $cache->remove('key');

        self::assertFalse($cache->has('key'));
        self::assertNull($cache->get('key'));
    }

    public function testReadsAndMaintenanceDoNotCreateAnUnusedCache(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);

        self::assertNull($cache->get('missing'));
        self::assertFalse($cache->has('missing'));
        $cache->remove('missing');
        $cache->clear();

        self::assertFileDoesNotExist($path);
    }

    public function testRememberComputesOnlyOnAMiss(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $callback = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        self::assertSame('computed', $cache->remember('key', $callback, 60));
        self::assertSame('computed', $cache->remember('key', $callback, 60));
        self::assertSame(1, $calls);
    }

    public function testRememberRecognizesACachedNull(): void
    {
        $cache = $this->cache();
        $cache->set('nullable', null);
        $calls = 0;

        $value = $cache->remember('nullable', static function () use (&$calls): string {
            $calls++;

            return 'computed';
        });

        self::assertNull($value);
        self::assertSame(0, $calls);
    }

    public function testRememberDoesNotCacheAThrownResult(): void
    {
        $cache = $this->cache();

        try {
            $cache->remember('key', $this->throwCacheFailure(...));
            self::fail('Expected the callback exception');
        } catch (RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        self::assertFalse($cache->has('key'));
    }

    public function testExpiredValuesBehaveAsMissesAndAreRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('short', 'value', 60);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET expires = 1 WHERE key = 'short'");

        self::assertSame('fallback', $cache->get('short', 'fallback'));
        self::assertFalse($cache->has('short'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testCorruptCleanupDoesNotBlockOrDeleteAConcurrentRefresh(): void
    {
        $path = $this->root . '/cache.sqlite';
        new Cache($path)->set('refreshing', new DummyCacheConcurrentRefreshValue());
        DummyCacheConcurrentRefreshValue::$cachePath = $path;

        self::assertSame('fallback', new Cache($path)->get('refreshing', 'fallback'));
        self::assertSame('fresh', new Cache($path)->get('refreshing'));
    }

    public function testNonPositiveTtlDoesNotStoreTheValue(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);

        $cache->set('zero', 'value', 0);

        self::assertFalse($cache->has('zero'));
        self::assertFileDoesNotExist($path);
    }

    public function testClearRemovesEveryValue(): void
    {
        $cache = $this->cache();
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->clear();

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testCorruptPayloadBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('broken', 'value');

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET value = 'not-a-serialized-value' WHERE key = 'broken'");

        self::assertSame('fallback', $cache->get('broken', 'fallback'));
        self::assertFalse($cache->has('broken'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testDeprecationsAndNoticesFromSerializationHooksAreNotFailures(): void
    {
        $cache = $this->cache();

        $cache->set('object', new DummyCacheDeprecatedHooksValue('kept'));
        $value = $cache->get('object');

        self::assertInstanceOf(DummyCacheDeprecatedHooksValue::class, $value);
        self::assertSame('kept', $value->name);
        // The row must survive the read: recording the notice as corruption
        // would have deleted it and made every future read recompute.
        self::assertInstanceOf(DummyCacheDeprecatedHooksValue::class, $cache->get('object'));
    }

    public function testHasAnswersFromPresenceAndExpiryWithoutDecodingThePayload(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET value = 'not-a-serialized-value' WHERE key = 'key'");

        // has() does not decode, so the corrupt payload still counts as
        // present; the first get() detects it, removes the row, and has()
        // agrees again.
        self::assertTrue($cache->has('key'));
        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
    }

    public function testHasAloneRemovesAnExpiredRow(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('short', 'value', 60);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET expires = 1 WHERE key = 'short'");

        self::assertFalse($cache->has('short'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testUnavailableSerializedClassBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('old-object', 'value');

        $payload = 'O:17:"MissingCacheValue":0:{}';
        $pdo = new PDO('sqlite:' . $path);
        $statement = $pdo->prepare('UPDATE cache SET value = :value WHERE key = :key');
        $statement->execute([':value' => $payload, ':key' => 'old-object']);

        self::assertSame('fallback', $cache->get('old-object', 'fallback'));
        self::assertFalse($cache->has('old-object'));
    }

    public function testDeepUnavailableSerializedClassBehavesAsAMiss(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('old-object', 'value');

        $payload = 'O:17:"MissingCacheValue":0:{}';

        for ($depth = 0; $depth < 65; $depth++) {
            $payload = 'a:1:{i:0;' . $payload . '}';
        }

        $pdo = new PDO('sqlite:' . $path);
        $statement = $pdo->prepare('UPDATE cache SET value = :value WHERE key = :key');
        $statement->execute([':value' => $payload, ':key' => 'old-object']);

        self::assertSame('fallback', $cache->get('old-object', 'fallback'));
        self::assertFalse($cache->has('old-object'));
    }

    public function testClosuresAndResourcesAreRejected(): void
    {
        $cache = $this->cache();

        try {
            $cache->set('closure', static fn(): string => 'nope');
            self::fail('Expected a closure to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be serialized', $exception->getMessage());
        }

        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $cache->set('resource', ['stream' => $resource]);
            self::fail('Expected a resource to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resource', $exception->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function testDeepResourcesAreRejectedRatherThanSilentlyChanged(): void
    {
        $cache = $this->cache();
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        $value = $resource;

        for ($depth = 0; $depth < 65; $depth++) {
            $value = [$value];
        }

        try {
            $cache->set('deep-resource', $value);
            self::fail('Expected a deeply nested resource to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('safe inspection limits', $exception->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function testRecursiveArraysAreRejectedWithinTheInspectionBudget(): void
    {
        $value = [];
        $value['left'] = &$value;
        $value['right'] = &$value;

        try {
            $this->cache()->set('recursive', $value);
            self::fail('Expected a recursive array to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('safe inspection limits', $exception->getMessage());
        }
    }

    public function testASharedNonCyclicObjectAcrossManyArrayEntriesIsInspectedOnce(): void
    {
        // More entries than the whole container budget: the shared object
        // must be charged once, not once per reference, for this to pass.
        $cache = $this->cache();
        $shared = new DummyCacheValue('shared', []);
        $value = array_fill(0, 150_000, $shared);

        $cache->set('shared-refs', $value);
        $stored = $cache->get('shared-refs');

        self::assertIsArray($stored);
        self::assertCount(150_000, $stored);
        self::assertInstanceOf(DummyCacheValue::class, $stored[0]);
        self::assertSame('shared', $stored[0]->name);
    }

    public function testALargeDecodedApiResponseSizedValueRoundTrips(): void
    {
        // Tens of thousands of *distinct* containers, the shape of a large
        // decoded JSON feed — the use case the container budget is sized for.
        $cache = $this->cache();
        $value = array_fill(0, 50_000, ['id' => 1, 'title' => 'item']);

        $cache->set('feed', $value);
        $stored = $cache->get('feed');

        self::assertIsArray($stored);
        self::assertCount(50_000, $stored);
        self::assertSame(['id' => 1, 'title' => 'item'], $stored[0]);
    }

    public function testAValueExceedingTheContainerBudgetIsRejected(): void
    {
        // 100,000 children plus the root array is one container over budget.
        $value = array_fill(0, 100_000, []);

        try {
            $this->cache()->set('too-complex', $value);
            self::fail('Expected the over-budget value to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('safe inspection limits', $exception->getMessage());
        }
    }

    public function testCorruptDatabaseFileBehavesAsAMissOnRead(): void
    {
        $path = $this->root . '/cache.sqlite';
        file_put_contents($path, 'not a sqlite database');
        $cache = new Cache($path);

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
    }

    public function testCorruptDatabaseFileFailsClearlyOnWrite(): void
    {
        $path = $this->root . '/cache.sqlite';
        file_put_contents($path, 'not a sqlite database');
        $cache = new Cache($path);

        try {
            $cache->set('key', 'value');
            self::fail('Expected the corrupt database file to be rejected');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cache file', $exception->getMessage());
        }
    }

    public function testClearThrowsForACorruptDatabaseFileInsteadOfClaimingSuccess(): void
    {
        $path = $this->root . '/cache.sqlite';
        file_put_contents($path, 'not a sqlite database');
        $cache = new Cache($path);

        try {
            $cache->clear();
            self::fail('Expected the corrupt database file to be rejected');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('unusable', $exception->getMessage());
        }

        self::assertFileExists($path);
    }

    public function testVeryLargeTtlDoesNotOverflowToAFloat(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value', PHP_INT_MAX);

        self::assertSame('value', $cache->get('key'));

        $pdo = new PDO('sqlite:' . $path);
        $statement = $pdo->query("SELECT typeof(expires) AS type FROM cache WHERE key = 'key'");
        self::assertInstanceOf(PDOStatement::class, $statement);
        self::assertSame('integer', $statement->fetchColumn());
    }

    public function testNonIntegerExpiresValueBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value', 60);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET expires = 1.5 WHERE key = 'key'");

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testNonStringCacheValueBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('UPDATE cache SET value = 12345 WHERE key = \'key\'');

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testTablePageCorruptionDiscoveredDuringLookupBehavesAsAMiss(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        // Simulate the table's own pages becoming unreadable after reader()
        // already confirmed the table exists in sqlite_master (which stays
        // cached as schemaReady on this instance) — a concurrent DROP
        // reproduces the same "schema known good, query still fails" shape
        // as real page-level corruption discovered only once a query
        // actually touches the table's data.
        $other = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('DROP TABLE cache');

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
    }

    public function testTablePageCorruptionMakesClearThrowInsteadOfClaimingSuccess(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        $other = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('DROP TABLE cache');

        // has() first, so $unusable is set the same way a real caller would
        // discover it, before clear() is asked to report on it.
        self::assertFalse($cache->has('key'));

        try {
            $cache->clear();
            self::fail('Expected the unusable cache to be rejected');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('unusable', $exception->getMessage());
        }
    }

    public function testCorruptBlobValueBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        $pdo = new PDO('sqlite:' . $path);
        $statement = $pdo->prepare('UPDATE cache SET value = :value WHERE key = :key');
        $statement->bindValue(':value', "\xDE\xAD\xBE\xEF", PDO::PARAM_LOB);
        $statement->bindValue(':key', 'key');
        $statement->execute();
        $type = $pdo->query('SELECT typeof(value) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $type);
        self::assertSame('blob', $type->fetchColumn());
        $type->closeCursor();

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testCorruptRealValueBehavesAsAMissAndIsRemoved(): void
    {
        $path = $this->root . '/cache.sqlite';
        $cache = new Cache($path);
        $cache->set('key', 'value');

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec("UPDATE cache SET value = 3.14 WHERE key = 'key'");
        $type = $pdo->query('SELECT typeof(value) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $type);
        self::assertSame('real', $type->fetchColumn());
        $type->closeCursor();

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertFalse($cache->has('key'));
        $count = $pdo->query('SELECT COUNT(*) FROM cache');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testClearReportsWhetherAnythingWasActuallyCleared(): void
    {
        $cache = $this->cache();

        self::assertFalse($cache->clear());

        $cache->set('key', 'value');

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('key'));
    }

    public function testFileAndCreatedDirectoryAreOwnerOnly(): void
    {
        $path = $this->root . '/nested/cache.sqlite';
        new Cache($path)->set('key', 'value');

        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o700, fileperms($this->root . '/nested') & 0o777);
    }

    public function testReadHardensAnExistingCacheBeforeUnserializingValues(): void
    {
        $path = $this->root . '/cache.sqlite';
        new Cache($path)->set('key', new DummyCachePermissionValue());
        chmod($path, 0o644);
        DummyCachePermissionValue::$cachePath = $path;

        self::assertInstanceOf(DummyCachePermissionValue::class, new Cache($path)->get('key'));

        $permissions = DummyCachePermissionValue::observedPermissions();
        self::assertIsInt($permissions);
        self::assertSame(0o600, $permissions & 0o777);
    }

    public function testExistingReadonlyFileIsHardenedBeforeOpeningTheConnection(): void
    {
        $path = $this->root . '/cache.sqlite';
        new Cache($path)->set('key', 'old');
        chmod($path, 0o400);
        $cache = new Cache($path);

        self::assertSame('old', $cache->get('key'));
        $cache->set('key', 'new');

        self::assertSame('new', $cache->get('key'));
        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function testSymlinkedCacheFileIsRefused(): void
    {
        $target = $this->root . '/elsewhere.sqlite';
        new Cache($target)->set('key', 'value');
        $path = $this->root . '/cache.sqlite';
        symlink($target, $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        new Cache($path)->get('key');
    }

    private function cache(): Cache
    {
        return new Cache($this->root . '/cache.sqlite');
    }

    private function throwCacheFailure(): string
    {
        throw new RuntimeException('failed');
    }
}
