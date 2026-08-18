<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\ContentIndex;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Direct ContentIndex coverage for behavior that's easiest to exercise below
 * the Application/Pages layer — here, hand-crafted index row shapes a real
 * content tree can never produce.
 */
final class ContentIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-content-index-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/routes', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /**
     * parentPath() only ever assigns a parent_path strictly shorter than its
     * own path, so a real scan can never produce a cycle — but a hand-edited or
     * foreign-restored index file could (A's parent is B, B's parent is A).
     * ancestors() tracks every path already walked in the recursive query, so a
     * cycle like that terminates cleanly right where it closes — not just
     * "eventually", via the depth bound alone, which would otherwise still
     * return hundreds of duplicate rows before giving up.
     */
    public function testAncestorsTerminatesOnACorruptedParentPathCycle(): void
    {
        $index = $this->indexWithHandCraftedRows(['/a' => '/b', '/b' => '/a']);

        $ancestors = $index->ancestors('/a');

        // The walk starts at '/a', steps to its parent '/b', then would step back
        // to '/a' — already visited, so it stops there. '/a' itself is always
        // excluded (it's the walk's own starting point), leaving exactly one
        // ancestor, not the hundreds of duplicate rows a depth bound alone would
        // produce.
        self::assertSame(
            [['path' => '/b', 'dir' => $this->routeDir('/b'), 'hidden' => false]],
            $ancestors,
        );
    }

    /**
     * ancestors()'s cycle guard joins visited paths with '|' delimiters. A
     * route path comes straight from a directory name, which the filesystem
     * places no restriction on — unlike RoutePath's own CLI-facing validation —
     * so a segment can legitimately contain a literal '|'. Visited entries are
     * recorded hex-encoded so a path's own '|' can never read as a delimiter:
     * without that, a path ending "...bar|" immediately followed by a "/foo"
     * entry reconstructs the exact substring "|/foo|" by coincidence, and the
     * guard would misread the real, never-visited "/foo" ancestor as already
     * seen and silently drop it from the chain.
     */
    public function testAncestorsHandlesAPathSegmentContainingTheDelimiterCharacter(): void
    {
        $index = $this->indexWithHandCraftedRows([
            '/foo' => null,
            '/foo/bar|' => '/foo',
            '/foo/bar|/baz' => '/foo/bar|',
        ]);

        $ancestors = $index->ancestors('/foo/bar|/baz');

        self::assertSame(
            [
                ['path' => '/foo', 'dir' => $this->routeDir('/foo'), 'hidden' => false],
                ['path' => '/foo/bar|', 'dir' => $this->routeDir('/foo/bar|'), 'hidden' => false],
            ],
            $ancestors,
        );
    }

    /**
     * The sharper collision shape the delimiter can produce: a deeper path
     * whose final segment repeats a genuine ancestor's name right after a
     * literal '|'. Escaping the delimiter inside recorded paths (say '\|') is
     * not enough here — the escaped form still contains a '|' byte, so the
     * marker for the real '/p' ancestor ('|/p|') is found spanning the escaped
     * pipe in '...q\|/p|' and the chain silently truncates to just '/p/q|'.
     * Hex-encoded entries contain no '|' at all, so a marker can only ever
     * match a whole visited entry.
     */
    public function testAncestorsKeepsAnAncestorWhoseNameReappearsBesideTheDelimiter(): void
    {
        $index = $this->indexWithHandCraftedRows([
            '/p' => null,
            '/p/q|' => '/p',
            '/p/q|/p' => '/p/q|',
        ]);

        $ancestors = $index->ancestors('/p/q|/p');

        self::assertSame(
            [
                ['path' => '/p', 'dir' => $this->routeDir('/p'), 'hidden' => false],
                ['path' => '/p/q|', 'dir' => $this->routeDir('/p/q|'), 'hidden' => false],
            ],
            $ancestors,
        );
    }

    /**
     * SQLite's LIKE is ASCII case-insensitive by default, so a naive
     * "path LIKE '/blog/%'" prefix match would also pull in an unrelated
     * '/Blog/...' subtree. Most filesystems can never produce two routes
     * differing only by case in the same parent (hence the hand-crafted
     * rows rather than a real scan — see this class's own docblock), but
     * one that does, or a caller passing a differently-cased scope path,
     * must not leak across the case boundary.
     */
    public function testDescendantsPrefixMatchIsCaseSensitive(): void
    {
        $index = $this->indexWithHandCraftedRows([
            '/blog' => null,
            '/blog/hello' => '/blog',
            '/Blog' => null,
            '/Blog/other' => '/Blog',
        ]);

        $descendants = $index->descendants('/blog');

        self::assertSame(
            [['path' => '/blog/hello', 'dir' => $this->routeDir('/blog/hello'), 'hidden' => false]],
            $descendants,
        );
    }

    /**
     * Same case-sensitivity requirement as descendants() (see above), for
     * the lightweight listing() projection page:list uses.
     */
    public function testListingDescendantsPrefixMatchIsCaseSensitive(): void
    {
        $index = $this->indexWithHandCraftedRows([
            '/blog' => null,
            '/blog/hello' => '/blog',
            '/Blog' => null,
            '/Blog/other' => '/Blog',
        ]);

        $rows = $index->listingDescendants('/blog');

        self::assertCount(1, $rows);
        self::assertSame('/blog/hello', $rows[0]['path']);
    }

    /**
     * Build a 'locked'-mode ContentIndex over a hand-crafted index file. Each
     * entry maps a route path to its stored parent_path; the row's dir mirrors
     * the path under routes/ (see routeDir()) and its id is the path itself.
     * 'locked' mode never rescans the filesystem, so the returned index reads
     * exactly these rows rather than rebuilding over them.
     *
     * @param array<string, string|null> $parentByPath
     */
    private function indexWithHandCraftedRows(array $parentByPath): ContentIndex
    {
        $sqlitePath = $this->root . '/runtime/index.sqlite';
        mkdir(dirname($sqlitePath), 0o777, true);

        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Reuse ContentIndex's own schema instead of a hand-duplicated copy, so a
        // future schema change can't silently drift this fixture away from what
        // production actually creates.
        $index = new ContentIndex($this->root . '/routes', $sqlitePath, 'locked');
        new ReflectionMethod(ContentIndex::class, 'createSchema')->invoke($index, $pdo);

        $insert = $pdo->prepare(
            'INSERT INTO pages'
            . ' (path, dir, id, template, title, created, depth, parent_path, draft, hidden,'
            . ' sort, endpoint)'
            . ' VALUES (:path, :dir, :id, NULL, NULL, NULL, 1, :parent_path, 0, 0, 0, 0)',
        );

        foreach ($parentByPath as $path => $parentPath) {
            $insert->execute([
                ':path' => $path,
                ':dir' => $this->routeDir($path),
                ':id' => $path,
                ':parent_path' => $parentPath,
            ]);
        }

        $meta = $pdo->prepare('INSERT INTO meta (key, value) VALUES (:key, :value)');
        $meta->execute([':key' => 'fingerprint', ':value' => 'irrelevant']);
        // Read from the class rather than hardcoded: a stale value here would make
        // 'locked' mode treat the fixture as schema-stale and silently rebuild
        // over these rows from the (empty) routes tree before a test reads them.
        $meta->execute([':key' => 'schema_version', ':value' => $this->currentSchemaVersion()]);

        return $index;
    }

    private function routeDir(string $path): string
    {
        return $this->root . '/routes' . $path;
    }

    private function currentSchemaVersion(): string
    {
        $version = new ReflectionClassConstant(ContentIndex::class, 'SCHEMA_VERSION')->getValue();
        self::assertIsInt($version);

        return (string) $version;
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
