<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\ContentIndex;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for docs/index-freshness.md: an index built under an older
 * Garner schema (missing columns/meta the current code expects) must self-heal via
 * a schema_version mismatch, not just a content-fingerprint mismatch. Without this,
 * a schema-only upgrade with unchanged content would serve a stale-schema index and
 * crash with "no such column" in both scan and locked modes.
 */
final class IndexFreshnessTest extends TestCase
{
    private const MTIME = 1_751_328_000;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-index-freshness-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/routes/about', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLockedModeHealsSchemaMismatchEvenWhenIndexFileExists(): void
    {
        $index = $this->buildLegacyIndex('locked');

        $children = $index->children('/');

        self::assertCount(1, $children);
        self::assertSame($this->root . '/routes/about', $children[0]['dir']);
    }

    public function testScanModeHealsSchemaMismatchDespiteMatchingContentFingerprint(): void
    {
        $index = $this->buildLegacyIndex('scan');

        $children = $index->children('/');

        self::assertCount(1, $children);
        self::assertSame($this->root . '/routes/about', $children[0]['dir']);
    }

    /**
     * Writes content, then hand-crafts a SQLite index in the pre-endpoint,
     * pre-schema_version shape (the schema Garner used before 2026-07-01), with a
     * content fingerprint that matches the content written above. A content-only
     * freshness check would therefore see this index as fresh; only the
     * schema_version check should force a rebuild.
     */
    private function buildLegacyIndex(string $mode): ContentIndex
    {
        $this->writeFile($this->root . '/routes/+page.json', '{"id": "home", "title": "Home"}');
        $this->writeFile(
            $this->root . '/routes/about/+page.json',
            '{"id": "about", "title": "About"}',
        );
        touch($this->root . '/routes/+page.json', self::MTIME);
        touch($this->root . '/routes/about/+page.json', self::MTIME);

        $sqlitePath = $this->root . '/runtime/index.sqlite';
        mkdir(dirname($sqlitePath), 0o777, true);

        $fingerprint = $this->legacyFingerprint([
            $this->root . '/routes' => self::MTIME,
            $this->root . '/routes/about' => self::MTIME,
        ]);

        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE pages ('
            . 'path TEXT PRIMARY KEY, dir TEXT NOT NULL, id TEXT NOT NULL, template TEXT NULL,'
            . ' title TEXT NULL, created TEXT NULL, depth INTEGER NOT NULL, parent_path TEXT NULL,'
            . ' draft INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0)',
        );
        $pdo->exec('CREATE UNIQUE INDEX pages_id ON pages (id)');
        $pdo->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $insert = $pdo->prepare(
            'INSERT INTO pages (path, dir, id, template, title, created, depth, parent_path, draft, sort)'
            . ' VALUES (:path, :dir, :id, :template, :title, :created, :depth, :parent_path, :draft, :sort)',
        );
        $insert->execute([
            ':path' => '/',
            ':dir' => $this->root . '/routes',
            ':id' => 'home',
            ':template' => null,
            ':title' => 'Home',
            ':created' => null,
            ':depth' => 0,
            ':parent_path' => null,
            ':draft' => 0,
            ':sort' => 0,
        ]);
        $insert->execute([
            ':path' => '/about',
            ':dir' => $this->root . '/routes/about',
            ':id' => 'about',
            ':template' => null,
            ':title' => 'About',
            ':created' => null,
            ':depth' => 1,
            ':parent_path' => '/',
            ':draft' => 0,
            ':sort' => 0,
        ]);

        $meta = $pdo->prepare('INSERT INTO meta (key, value) VALUES (:key, :value)');
        $meta->execute([':key' => 'fingerprint', ':value' => $fingerprint]);
        $meta->execute([':key' => 'built_at', ':value' => gmdate('c')]);

        return new ContentIndex($this->root . '/routes', $sqlitePath, $mode);
    }

    /**
     * @param array<string, int> $dirMtimes
     */
    private function legacyFingerprint(array $dirMtimes): string
    {
        $parts = [];

        foreach ($dirMtimes as $dir => $mtime) {
            $parts[] = $dir . ':' . $mtime;
        }

        sort($parts);

        return sha1(implode('|', $parts));
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
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
