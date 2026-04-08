<?php

declare(strict_types=1);

use Garner\Blueprint\BlueprintException;
use Garner\Blueprint\BlueprintLoader;
use PHPUnit\Framework\TestCase;

final class BlueprintLoaderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-blueprints-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/site/blueprints', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testLoaderParsesAndReturnsSiteBlueprint(): void
    {
        $this->writeFile('site/blueprints/site.yml', <<<'YAML'
            title: Site
            description: Site-level editorial overview.

            tabs:
                - name: pages
                  label: Pages
                  nodes:
                      - type: page_list
                        name: pages
                        label: Pages
                        source: site
                        create:
                            enabled: true

                - extends: tabs/files
            YAML);

        $this->writeFile('site/blueprints/tabs/files.yml', <<<'YAML'
            name: files
            label: Files
            nodes:
                - type: file_list
                  name: files
                  label: Files
                  source: site
                  upload:
                      enabled: true
            YAML);

        $blueprint = $this->makeLoader()->loadSite();

        self::assertSame('Site', $blueprint['title']);
        self::assertSame('Site-level editorial overview.', $blueprint['description']);
        self::assertSame('pages', $blueprint['tabs'][0]['name']);
        self::assertSame('Pages', $blueprint['tabs'][0]['label']);
        self::assertSame('page_list', $blueprint['tabs'][0]['nodes'][0]['type']);
        self::assertTrue($blueprint['tabs'][0]['nodes'][0]['create']['enabled']);
        self::assertSame('file_list', $blueprint['tabs'][1]['nodes'][0]['type']);
    }

    public function testLoaderParsesSimpleFieldNodes(): void
    {
        $this->writeFile('site/blueprints/pages/page.yml', <<<'YAML'
            title: Page

            tabs:
                - name: content
                  label: Content
                  nodes:
                      - type: text
                        name: summary
                        label: Summary

                      - type: textarea
                        name: text
                        label: Text
                        rows: 10
            YAML);

        $blueprint = $this->makeLoader()->loadPage('page');

        self::assertSame('Page', $blueprint['title']);
        self::assertSame('text', $blueprint['tabs'][0]['nodes'][0]['type']);
        self::assertSame('textarea', $blueprint['tabs'][0]['nodes'][1]['type']);
        self::assertSame(10, $blueprint['tabs'][0]['nodes'][1]['rows']);
    }

    public function testLoaderRejectsInvalidBlueprintStructure(): void
    {
        $this->writeFile('site/blueprints/site.yml', <<<'YAML'
            title: Site

            tabs:
                - name: pages
                  label: Pages
                  nodes:
                      - type: page_list
                        name: pages
                        label: Pages
            YAML);

        $this->expectException(BlueprintException::class);
        $this->expectExceptionMessage('tabs.0.nodes.0.source');

        $this->makeLoader()->loadSite();
    }

    private function makeLoader(): BlueprintLoader
    {
        return new BlueprintLoader($this->projectRoot . '/site/blueprints');
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->projectRoot . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
