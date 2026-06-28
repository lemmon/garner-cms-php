<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\File;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-media-' . bin2hex(random_bytes(6));
        $this->writeEntry('', ['template' => 'home', 'title' => 'Home']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPageExposesAnOwnedFile(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        $file = $about->file('photo.jpg');
        self::assertInstanceOf(File::class, $file);
        self::assertSame('photo.jpg', $file->filename());
        self::assertSame('photo', $file->name());
        self::assertSame('jpg', $file->extension());
        self::assertSame('image/jpeg', $file->mimeType());
        self::assertTrue($file->isImage());
        self::assertSame(8, $file->size());
    }

    public function testFileReturnsNullForMissingOrReservedNames(): void
    {
        $this->writeEntry('about', ['title' => 'About']);

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        self::assertNull($about->file('nope.png'));
        self::assertNull($about->file('+page.json'));
        self::assertNull($about->file('.hidden'));
        self::assertNull($about->file('../secret.txt'));
    }

    public function testFilesListsAssetsButNotContentOrSidecars(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile('routes/about/brochure.pdf', '%PDF-1.4');
        $this->writeFile('routes/about/main.md', '# Body');
        $this->writeFile('routes/about/data.json', '{"k":1}');
        $this->writeFile('routes/about/photo.jpg.json', '{"alt":"A cat"}');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // Only the real assets, sorted by filename — not the content files, not the sidecar.
        self::assertSame(['brochure.pdf', 'photo.jpg'], $about->files()->keys()->all());
    }

    public function testSidecarAttachesToFileAndIsNotLoadedAsContent(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile('routes/about/photo.jpg.json', '{"alt":"A cat","credit":"Jane"}');
        $this->writeFile('routes/about/data.json', '{"k":1}');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        $file = $about->file('photo.jpg');
        self::assertNotNull($file);
        self::assertSame('A cat', $file->get('alt'));
        self::assertSame(['alt' => 'A cat', 'credit' => 'Jane'], $file->meta());

        // The sidecar is not exposed as a content value, but real data files still are.
        self::assertArrayNotHasKey('photo.jpg', $about->content());
        self::assertArrayHasKey('data', $about->content());
    }

    public function testFileWithoutSidecarHasEmptyMeta(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');

        $file = $this->app()->pages()->find('/about')?->file('photo.jpg');
        self::assertNotNull($file);
        self::assertSame([], $file->meta());
        self::assertSame('fallback', $file->get('alt', 'fallback'));
    }

    public function testUrlPublishesFileIntoPublicMedia(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');

        $file = $this->app()->pages()->find('/about')?->file('photo.jpg');
        self::assertNotNull($file);

        $url = $file->url();
        self::assertMatchesRegularExpression('#^/media/[0-9a-f]+/photo\.jpg$#', $url);

        // The published file is served straight from public/ and resolves to the source bytes.
        $published = $this->root . '/public' . $url;
        self::assertFileExists($published);
        self::assertSame('JPEGDATA', file_get_contents($published));
    }

    public function testUrlIsContentHashedAndStable(): void
    {
        $this->writeEntry('a', ['title' => 'A']);
        $this->writeEntry('b', ['title' => 'B']);
        $this->writeFile('routes/a/img.png', 'SAME-BYTES');
        $this->writeFile('routes/b/img.png', 'DIFFERENT-BYTES');

        $pages = $this->app()->pages();
        $first = $pages->find('/a')?->file('img.png');
        $second = $pages->find('/b')?->file('img.png');
        self::assertNotNull($first);
        self::assertNotNull($second);

        // Same file is stable across calls; different contents get a different hash.
        self::assertSame($first->url(), $first->url());
        self::assertNotSame($first->url(), $second->url());
    }

    public function testPublishedFileIsAnImmutableSnapshotOfItsBytes(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'ORIGINAL');

        $first = $this->app()->pages()->find('/about')?->file('photo.jpg');
        self::assertNotNull($first);
        $firstUrl = $first->url();
        $firstPublished = $this->root . '/public' . $firstUrl;

        // Editing the source in place must not change what an already-published hash
        // URL serves — it is a copied snapshot, not a live link to the route file.
        $this->writeFile('routes/about/photo.jpg', 'EDITED-AND-LONGER');

        $second = $this->app()->pages()->find('/about')?->file('photo.jpg');
        self::assertNotNull($second);
        $secondUrl = $second->url();

        self::assertNotSame($firstUrl, $secondUrl);
        self::assertSame('ORIGINAL', file_get_contents($firstPublished));
        self::assertSame(
            'EDITED-AND-LONGER',
            file_get_contents($this->root . '/public' . $secondUrl),
        );
    }

    public function testSymlinkedAssetIsRejected(): void
    {
        $this->writeEntry('about', ['title' => 'About']);

        // A symlink that escapes the page directory must never become publishable:
        // is_file() follows it, so without the guard its target would be copied out.
        $outside = $this->root . '/secret.bin';
        file_put_contents($outside, 'TOP SECRET');
        symlink($outside, $this->root . '/routes/about/leak.jpg');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        self::assertNull($about->file('leak.jpg'));
        self::assertArrayNotHasKey('leak.jpg', $about->files()->all());
    }

    public function testProseSiblingOfAssetStaysContentNotSidecar(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile('routes/about/photo.jpg.json', '{"alt":"A cat"}');
        $this->writeFile('routes/about/photo.jpg.md', 'A caption');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // The JSON sidecar attaches to the file (and is not content)...
        self::assertSame('A cat', $about->file('photo.jpg')?->get('alt'));
        // ...but the Markdown sibling is not swallowed: File::meta() can't read it,
        // so it must remain an ordinary content value rather than vanish.
        self::assertArrayHasKey('photo.jpg', $about->content());
        self::assertSame('A caption', $about->content()['photo.jpg']);
    }

    public function testFileAccessorExposesAssetsOnly(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile('routes/about/main.md', '# Body');
        $this->writeFile('routes/about/data.json', '{"k":1}');
        $this->writeFile('routes/about/photo.jpg.json', '{"alt":"A cat"}');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // The singular accessor agrees with files(): assets yes, content/sidecars no.
        self::assertNotNull($about->file('photo.jpg'));
        self::assertNull($about->file('main.md'));
        self::assertNull($about->file('data.json'));
        self::assertNull($about->file('photo.jpg.json'));
    }

    public function testStructuredSiblingOfContentFileStaysContent(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/main.md', '# Body');
        $this->writeFile('routes/about/main.md.json', '{"note":"meta"}');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // main.md is content, not an asset, so main.md.json is not its sidecar — it
        // must keep its own content value rather than be swallowed and vanish.
        self::assertSame('# Body', $about->content()['main']);
        self::assertArrayHasKey('main.md', $about->content());
        self::assertSame(['note' => 'meta'], $about->content()['main.md']);
    }

    public function testUrlEncodesFilenameWhilePublishingItsRawName(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/my photo.jpg', 'JPEGDATA');

        $file = $this->app()->pages()->find('/about')?->file('my photo.jpg');
        self::assertNotNull($file);

        $url = $file->url();
        // The URL is percent-encoded...
        self::assertStringContainsString('my%20photo.jpg', $url);
        // ...while the file on disk keeps its real name, so decoding the URL (as a
        // web server or the dev router does) resolves back to it.
        self::assertFileExists($this->root . '/public' . rawurldecode($url));
    }

    public function testUppercaseSidecarExtensionIsRecognized(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        // An uppercase extension must still be found as a sidecar (it is skipped from
        // content), or it would vanish on case-sensitive filesystems.
        $this->writeFile('routes/about/photo.jpg.JSON', '{"alt":"A cat"}');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        self::assertSame('A cat', $about->file('photo.jpg')?->get('alt'));
        self::assertArrayNotHasKey('photo.jpg', $about->content());
    }

    public function testExecutableExtensionsAreNotPublishableAssets(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile('routes/about/shell.php', '<?php echo "pwned";');
        $this->writeFile('routes/about/evil.phtml', 'x');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // Scripts must never be reachable as assets — publishing them would drop
        // runnable code into the public web root.
        self::assertNull($about->file('shell.php'));
        self::assertNull($about->file('evil.phtml'));
        self::assertArrayNotHasKey('shell.php', $about->files()->all());
        self::assertArrayNotHasKey('evil.phtml', $about->files()->all());

        // Genuine assets are unaffected.
        self::assertNotNull($about->file('photo.jpg'));
    }

    public function testUrlHashMatchesThePublishedBytes(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');

        $file = $this->app()->pages()->find('/about')?->file('photo.jpg');
        self::assertNotNull($file);

        $url = $file->url();

        if (preg_match('#/media/([0-9a-f]+)/#', $url, $matches) !== 1) {
            self::fail('media URL did not contain a hash segment: ' . $url);
        }

        // The hash in the URL must be the hash of the file that was actually
        // published — the immutability contract these URLs document.
        $published = $this->root . '/public' . rawurldecode($url);
        self::assertSame($matches[1], hash_file('xxh128', $published));
    }

    public function testBackslashNamesAreExcludedFromBothAccessors(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        // A backslash is a valid filename character on Unix; file() rejects such
        // names, so files() must too, or the two accessors disagree.
        $this->writeFile('routes/about/foo\\bar.jpg', 'X');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        self::assertNull($about->file('foo\\bar.jpg'));
        self::assertArrayNotHasKey('foo\\bar.jpg', $about->files()->all());
        self::assertArrayHasKey('photo.jpg', $about->files()->all());
    }

    public function testDoubleExtensionExecutableNamesAreRejected(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        // A broad PHP handler executes this on the embedded ".php" despite the ".jpg"
        // final suffix, so it must never be publishable.
        $this->writeFile('routes/about/avatar.php.jpg', '<?php echo "pwned";');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        self::assertNull($about->file('avatar.php.jpg'));
        self::assertArrayNotHasKey('avatar.php.jpg', $about->files()->all());

        // A legitimate name with dots is unaffected.
        $this->writeFile('routes/about/my.cool.photo.jpg', 'X');
        self::assertNotNull($this->app()->pages()->find('/about')?->file('my.cool.photo.jpg'));
    }

    public function testFileMatchesExactCaseOnly(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/logo.svg', '<svg></svg>');

        $about = $this->app()->pages()->find('/about');
        self::assertNotNull($about);

        // The exact name resolves; a wrong-case name does not, even on a
        // case-insensitive filesystem (macOS/Windows) — matching Linux behavior.
        self::assertNotNull($about->file('logo.svg'));
        self::assertNull($about->file('Logo.svg'));
        self::assertArrayHasKey('logo.svg', $about->files()->all());
    }

    public function testImagesFilterKeepsOnlyImages(): void
    {
        $this->writeEntry('gallery', ['title' => 'Gallery']);
        $this->writeFile('routes/gallery/one.jpg', 'A');
        $this->writeFile('routes/gallery/two.png', 'B');
        $this->writeFile('routes/gallery/notes.pdf', 'C');

        $gallery = $this->app()->pages()->find('/gallery');
        self::assertNotNull($gallery);

        self::assertSame(['one.jpg', 'two.png'], $gallery->files()->images()->keys()->all());
    }

    public function testFileUrlRendersInTemplate(): void
    {
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeFile('routes/about/photo.jpg', 'JPEGDATA');
        $this->writeFile(
            'routes/about/+template.twig',
            '<img src="{{ page.file(\'photo.jpg\').url() }}" alt="{{ page.file(\'photo.jpg\').get(\'alt\') }}">',
        );
        $this->writeFile('routes/about/photo.jpg.json', '{"alt":"A cat"}');

        $body = $this->app()->publicSite()->respond('/about')->body();

        self::assertStringContainsString('src="/media/', $body);
        self::assertStringContainsString('alt="A cat"', $body);
    }

    private function app(): Application
    {
        $this->writeTemplates();

        return new Application($this->root, $this->root, [
            'app' => ['debug' => true, 'name' => 'Test Site'],
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeEntry(string $route, array $meta): void
    {
        $directory = $route === '' ? 'routes' : 'routes/' . $route;
        $json = json_encode($meta, JSON_PRETTY_PRINT);
        $this->writeFile($directory . '/+page.json', $json !== false ? $json : '{}');
    }

    private function writeTemplates(): void
    {
        $page = "<h1>{{ page.title }}</h1>\n{{ content.main|markdown }}";
        $this->writeFile('app/templates/home.twig', $page);
        $this->writeFile('app/templates/default.twig', $page);
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
