<?php

declare(strict_types=1);

use Garner\Content\SiteRepository;
use Garner\Studio\SiteUpdate;
use PHPUnit\Framework\TestCase;

final class StudioSiteUpdateTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-site-update-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testSiteUpdateChangesTitleAndPreservesPointers(): void
    {
        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $siteRepository->save([
            'title' => 'Before',
            'home_page_id' => 'home-page',
            'error_page_id' => 'error-page',
        ]);

        $result = (new SiteUpdate(siteRepository: $siteRepository))->update("  After \n Name  ");

        $stored = $siteRepository->read();

        self::assertTrue($result['ok']);
        self::assertSame('After Name', $stored['title']);
        self::assertSame('home-page', $stored['home_page_id']);
        self::assertSame('error-page', $stored['error_page_id']);
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
