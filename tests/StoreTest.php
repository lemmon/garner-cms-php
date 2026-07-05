<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Store;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-store-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSetGetRoundTrip(): void
    {
        $store = $this->store();

        $store->set('greeting', 'hello');

        self::assertSame('hello', $store->get('greeting'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $store = $this->store();
        $store->set('present', 1);

        self::assertSame('fallback', $store->get('missing', 'fallback'));
    }

    public function testAStoredNullWinsOverTheDefault(): void
    {
        $store = $this->store();
        $store->set('nothing', null);

        self::assertNull($store->get('nothing', 'fallback'));
        self::assertTrue($store->has('nothing'));
    }

    public function testSetIsAnUpsert(): void
    {
        $store = $this->store();

        $store->set('counter', 1);
        $store->set('counter', 2);

        self::assertSame(2, $store->get('counter'));
        self::assertSame(1, $store->count());
    }

    public function testAddInsertsWhenAbsent(): void
    {
        $store = $this->store();

        self::assertTrue($store->add('email:abc', ['email' => 'a@example.test']));
        self::assertSame(['email' => 'a@example.test'], $store->get('email:abc'));
    }

    public function testAddReturnsFalseOnDuplicateAndKeepsTheOriginal(): void
    {
        $store = $this->store();

        self::assertTrue($store->add('email:abc', ['email' => 'first@example.test']));
        self::assertFalse($store->add('email:abc', ['email' => 'second@example.test']));
        self::assertSame(['email' => 'first@example.test'], $store->get('email:abc'));
    }

    public function testHasAndRemove(): void
    {
        $store = $this->store();
        $store->set('key', 'value');

        self::assertTrue($store->has('key'));

        $store->remove('key');

        self::assertFalse($store->has('key'));
        self::assertNull($store->get('key'));
    }

    public function testRemoveOnMissingKeyIsANoOp(): void
    {
        $store = $this->store();

        $store->remove('never-stored');

        self::assertFalse($store->has('never-stored'));
    }

    public function testItemsReturnsACollectionKeyedByFullKeyFilteredByPrefix(): void
    {
        $store = $this->store();
        $store->set('email:b', ['email' => 'b@example.test']);
        $store->set('email:a', ['email' => 'a@example.test']);
        $store->set('settings:theme', 'dark');

        $items = $store->items('email:');

        self::assertInstanceOf(Collection::class, $items);
        self::assertSame(
            [
                'email:a' => ['email' => 'a@example.test'],
                'email:b' => ['email' => 'b@example.test'],
            ],
            $items->all(),
        );
    }

    public function testItemsWithoutPrefixReturnsEverything(): void
    {
        $store = $this->store();
        $store->set('a', 1);
        $store->set('b', 2);

        self::assertSame(['a' => 1, 'b' => 2], $store->items()->all());
    }

    public function testPrefixMatchesLiterallyNotAsLikeWildcards(): void
    {
        $store = $this->store();
        $store->set('a_c', 'underscore');
        $store->set('abc', 'other');
        $store->set('a%c', 'percent');

        self::assertSame(['a_c' => 'underscore'], $store->items('a_')->all());
        self::assertSame(1, $store->count('a%'));
    }

    public function testPrefixMatchingIsCaseSensitive(): void
    {
        $store = $this->store();
        $store->set('email:a', 'lower');
        $store->set('Email:b', 'upper');

        self::assertSame(['email:a' => 'lower'], $store->items('email:')->all());
        self::assertSame(['Email:b' => 'upper'], $store->items('Email:')->all());
        self::assertSame(1, $store->count('email:'));
    }

    public function testPrefixMatchingHandlesMultibyteCharacters(): void
    {
        $store = $this->store();
        $store->set('héllo:a', 1);
        $store->set('hello:b', 2);

        self::assertSame(['héllo:a' => 1], $store->items('héllo:')->all());
        self::assertSame(1, $store->count('héllo:'));
    }

    public function testCountByPrefix(): void
    {
        $store = $this->store();
        $store->set('email:a', 1);
        $store->set('email:b', 2);
        $store->set('settings:theme', 'dark');

        self::assertSame(2, $store->count('email:'));
        self::assertSame(3, $store->count());
        self::assertSame(0, $store->count('nothing:'));
    }

    public function testFileIsOnlyCreatedOnFirstWrite(): void
    {
        $path = $this->root . '/store.sqlite';
        $store = new Store($path);

        self::assertNull($store->get('anything'));
        self::assertFalse($store->has('anything'));
        self::assertSame(0, $store->count());
        self::assertTrue($store->items()->isEmpty());
        $store->remove('anything');
        self::assertFileDoesNotExist($path);

        $store->set('key', 'value');

        self::assertFileExists($path);
    }

    public function testJsonRoundTripFidelity(): void
    {
        $store = $this->store();
        $value = [
            'string' => 'héllo/world',
            'int' => 42,
            'float' => 1.5,
            'wholeFloat' => 2.0,
            'bool' => true,
            'null' => null,
            'list' => [1, 2, 3],
            'map' => ['nested' => ['deep' => 'value']],
        ];

        $store->set('everything', $value);

        self::assertSame($value, $store->get('everything'));
    }

    public function testScalarAndListValuesRoundTrip(): void
    {
        $store = $this->store();

        $store->set('scalar', 'hello');
        $store->set('flag', true);
        $store->set('list', ['a', 'b']);

        self::assertSame('hello', $store->get('scalar'));
        self::assertTrue($store->get('flag'));
        self::assertSame(['a', 'b'], $store->get('list'));
    }

    public function testANonJsonEncodableValueIsRejectedLoudly(): void
    {
        $store = $this->store();

        $this->expectException(InvalidArgumentException::class);
        $store->set('bad', NAN);
    }

    public function testValuesAreStoredAsInspectableJson(): void
    {
        $path = $this->root . '/store.sqlite';
        $store = new Store($path);
        $store->set('email:abc', ['email' => 'a@example.test']);

        $pdo = new \PDO('sqlite:' . $path);
        $row = $pdo->query("SELECT value, created, updated FROM store WHERE key = 'email:abc'");
        $data = $row === false ? false : $row->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($data);
        self::assertSame('{"email":"a@example.test"}', $data['value']);
        self::assertNotEmpty($data['created']);
        self::assertNotEmpty($data['updated']);
    }

    public function testTheFileAndACreatedDirectoryAreOwnerOnly(): void
    {
        $path = $this->root . '/nested/store.sqlite';
        $store = new Store($path);

        $store->set('key', 'value');

        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o700, fileperms($this->root . '/nested') & 0o777);
    }

    public function testAPreExistingDirectoryKeepsItsPermissions(): void
    {
        $directory = $this->root . '/existing';
        mkdir($directory, 0o755);
        chmod($directory, 0o755);

        new Store($directory . '/store.sqlite')->set('key', 'value');

        self::assertSame(0o755, fileperms($directory) & 0o777);
    }

    public function testASymlinkedStoreFileIsRefusedOnWrite(): void
    {
        $target = $this->root . '/elsewhere.sqlite';
        touch($target);
        $path = $this->root . '/store.sqlite';
        symlink($target, $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        new Store($path)->set('key', 'value');
    }

    public function testASymlinkedStoreFileIsRefusedOnRead(): void
    {
        // Seed a real store elsewhere, then link to it: the read path
        // (is_file() is true for a link to a file) must refuse too.
        $target = $this->root . '/elsewhere.sqlite';
        new Store($target)->set('key', 'value');
        $path = $this->root . '/store.sqlite';
        symlink($target, $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        new Store($path)->get('key');
    }

    public function testADanglingSymlinkIsRefusedOnReadNotTreatedAsEmpty(): void
    {
        $path = $this->root . '/store.sqlite';
        symlink($this->root . '/planted-target.sqlite', $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        new Store($path)->get('key');
    }

    public function testADanglingSymlinkIsRefusedRatherThanCreatingItsTarget(): void
    {
        $path = $this->root . '/store.sqlite';
        symlink($this->root . '/planted-target.sqlite', $path);

        // try/catch rather than expectException(): the test must keep
        // running after the throw to assert the target was never created.
        try {
            new Store($path)->set('key', 'value');
            self::fail('Expected a RuntimeException');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symlink', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->root . '/planted-target.sqlite');
    }

    public function testAPreCreatedFileIsTightenedTo0600OnFirstWrite(): void
    {
        $path = $this->root . '/store.sqlite';
        touch($path);
        chmod($path, 0o644);

        new Store($path)->set('key', 'value');

        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function testWritingAfterReadingFirstStillHardensTheFile(): void
    {
        $path = $this->root . '/store.sqlite';
        touch($path);
        chmod($path, 0o644);

        $store = new Store($path);
        // The read connects first, so the write path never sees a
        // missing file — hardening must not depend on that.
        self::assertNull($store->get('key'));
        $store->set('key', 'value');

        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame('value', $store->get('key'));
    }

    public function testADeleteOnlyOperationAlsoHardensTheFile(): void
    {
        $path = $this->root . '/store.sqlite';
        new Store($path)->set('key', 'value');
        chmod($path, 0o644);

        new Store($path)->remove('key');

        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function testReadsOnAFileWithoutTheStoreTableAnswerEmpty(): void
    {
        // The window a concurrent first write opens: the file exists
        // (connect creates it) but the schema doesn't yet. An empty
        // pre-created file is the same shape.
        $path = $this->root . '/store.sqlite';
        touch($path);
        $store = new Store($path);

        self::assertNull($store->get('key'));
        self::assertSame('fallback', $store->get('key', 'fallback'));
        self::assertFalse($store->has('key'));
        self::assertTrue($store->items()->isEmpty());
        self::assertSame(0, $store->count());
    }

    public function testASchemalessFileBecomesReadableOnceSomethingWrites(): void
    {
        $path = $this->root . '/store.sqlite';
        touch($path);

        $readingStore = new Store($path);
        self::assertFalse($readingStore->has('key'));

        new Store($path)->set('key', 'value');

        // The same reading instance picks the table up on its next read.
        self::assertSame('value', $readingStore->get('key'));
    }

    public function testConcurrentInstancesShareTheSameFile(): void
    {
        $path = $this->root . '/store.sqlite';

        $first = new Store($path);
        $second = new Store($path);

        self::assertTrue($first->add('email:abc', ['email' => 'a@example.test']));
        self::assertFalse($second->add('email:abc', ['email' => 'b@example.test']));
    }

    private function store(): Store
    {
        return new Store($this->root . '/store.sqlite');
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
