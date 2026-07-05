<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Render\RenderedResponse;
use PHPUnit\Framework\TestCase;

/**
 * Application::attachSessionCookie() is the single seam session state
 * reaches the response through — both emitters delegate to it
 * (Router::emit() for regular responses, ErrorHandler::emit() for error
 * pages, each of which calls exit() and is therefore proven by delegation
 * rather than invoked here).
 */
final class ApplicationSessionCookieTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root =
            sys_get_temp_dir() . '/garner-session-cookie-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAnUntouchedSessionAttachesNoCookie(): void
    {
        $app = $this->app([]);

        $response = $app->attachSessionCookie(RenderedResponse::html('hi'));

        self::assertSame([], $response->cookies());
    }

    public function testAModifiedSessionAttachesACookieCarryingItsId(): void
    {
        $app = $this->app([]);

        $app->session()->set('user_id', 1);
        $response = $app->attachSessionCookie(RenderedResponse::html('hi'));

        self::assertCount(1, $response->cookies());
        self::assertStringContainsString('garner_session=', $response->cookies()[0]);
    }

    public function testADestroyedSessionExpiresTheCookieInstead(): void
    {
        $app = $this->app([]);

        $app->session()->set('user_id', 1);
        $app->session()->destroy();
        $response = $app->attachSessionCookie(RenderedResponse::html('hi'));

        self::assertCount(1, $response->cookies());
        self::assertStringContainsString('Max-Age=0', $response->cookies()[0]);
    }

    public function testCookieNameIsConfigurable(): void
    {
        $app = $this->app(['session' => ['cookie' => 'sid']]);

        $app->session()->set('user_id', 1);
        $response = $app->attachSessionCookie(RenderedResponse::html('hi'));

        self::assertStringContainsString('sid=', $response->cookies()[0]);
    }

    public function testAttachingPersistsTheSessionToTheStore(): void
    {
        $app = $this->app([]);

        $app->session()->flash('notice', 'Saved!');
        $app->attachSessionCookie(RenderedResponse::html('hi'));

        $files = glob($this->root . '/storage/sessions/*.session');
        self::assertGreaterThan(0, count($files === false ? [] : $files));
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        return new Application(
            $this->root,
            $this->root,
            ['app' => $appConfig],
            Request::create('http://example.test/'),
        );
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
