<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\TreeValidator;
use Garner\Content\ValidationIssue;
use PHPUnit\Framework\TestCase;

final class TreeValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-validate-' . bin2hex(random_bytes(6));
        $this->writeEntry('', []);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAssetSidecarLayoutDoesNotReportContentCollision(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('about/photo.jpg', 'JPEGDATA');
        $this->writeFile('about/photo.jpg.json', '{"alt":"A cat"}');
        $this->writeFile('about/photo.jpg.md', 'A caption');

        // The loader ignores the sidecar, so validation must not flag a phantom
        // collision on "photo.jpg" for a layout that renders cleanly.
        self::assertSame([], $this->collisions());
    }

    public function testGenuineContentCollisionIsStillReported(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('about/data.json', '{"k":1}');
        $this->writeFile('about/data.yaml', 'k: 2');

        // Two real content files keyed "data" still collide.
        self::assertNotEmpty($this->collisions());
    }

    public function testEndpointSharesTheGlobalIdNamespace(): void
    {
        // A page id that collides with an endpoint's directory name is a duplicate,
        // reported here just as ContentIndex rejects it at build time.
        $this->writeEntry('duplicate', ['id' => 'feed.xml']);
        $this->writeFile('feed.xml/+controller.php', "<?php\nreturn static fn() => null;\n");

        $messages = implode("\n", array_map(
            static fn(ValidationIssue $issue): string => $issue->message,
            new TreeValidator($this->root)->validate(),
        ));

        self::assertStringContainsString('Duplicate page id "feed.xml"', $messages);
    }

    /**
     * @return list<string>
     */
    private function collisions(): array
    {
        $messages = array_map(
            static fn(ValidationIssue $issue): string => $issue->message,
            new TreeValidator($this->root)->validate(),
        );

        return array_values(array_filter($messages, static fn(string $message): bool => str_contains(
            $message,
            'collision',
        )));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeEntry(string $route, array $meta): void
    {
        $directory = $route === '' ? '' : $route . '/';
        $json = json_encode($meta, JSON_PRETTY_PRINT);
        $this->writeFile($directory . '+page.json', $json !== false ? $json : '{}');
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
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
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
