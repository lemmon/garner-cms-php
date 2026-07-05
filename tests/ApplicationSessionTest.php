<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\FileSessionStore;
use Garner\Core\Request;
use Garner\Core\Session;
use Garner\Core\SessionStore;
use Garner\Support\IdGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationSessionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-app-session-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultStoreIsFileBackedUnderProjectStorage(): void
    {
        $app = $this->app([]);

        self::assertInstanceOf(FileSessionStore::class, $app->sessionStore());

        $app->session()->set('user_id', 1);
        $app->session()->save();

        $files = glob($this->root . '/storage/sessions/*.session');
        self::assertGreaterThan(0, count($files === false ? [] : $files));
    }

    public function testConfiguredPathIsHonored(): void
    {
        $app = $this->app(['session' => ['path' => 'var/my-sessions']]);

        $app->session()->set('user_id', 1);
        $app->session()->save();

        $files = glob($this->root . '/var/my-sessions/*.session');
        self::assertGreaterThan(0, count($files === false ? [] : $files));
    }

    public function testInstanceIsUsedAsIs(): void
    {
        $store = new class implements SessionStore {
            public bool $used = false;

            public function exists(string $id): bool
            {
                return false;
            }

            public function read(string $id): array
            {
                return [];
            }

            public function write(string $id, array $data, int $lifetime): void
            {
                $this->used = true;
            }

            public function destroy(string $id): void {}

            public function gc(): void {}
        };

        $app = $this->app(['session' => ['store' => $store]]);
        $app->session()->set('a', 1);
        $app->session()->save();

        self::assertTrue($store->used);
    }

    public function testCallableIsInvokedForTheStore(): void
    {
        $store = new class implements SessionStore {
            public function exists(string $id): bool
            {
                return false;
            }

            public function read(string $id): array
            {
                return [];
            }

            public function write(string $id, array $data, int $lifetime): void {}

            public function destroy(string $id): void {}

            public function gc(): void {}
        };

        $app = $this->app(['session' => ['store' => static fn(): SessionStore => $store]]);

        self::assertSame($store, $app->sessionStore());
    }

    public function testInvalidStoreConfigurationThrows(): void
    {
        $app = $this->app(['session' => ['store' => 'not-a-store']]);

        $this->expectException(RuntimeException::class);
        $app->sessionStore();
    }

    public function testCookieNameAndLifetimeDefaults(): void
    {
        $app = $this->app([]);

        self::assertSame('garner_session', $app->sessionCookieName());
        self::assertSame(7200, $app->sessionLifetime());
    }

    public function testCookieNameAndLifetimeAreConfigurable(): void
    {
        $app = $this->app(['session' => ['cookie' => 'sid', 'lifetime' => 60]]);

        self::assertSame('sid', $app->sessionCookieName());
        self::assertSame(60, $app->sessionLifetime());
    }

    public function testSessionIfStartedIsNullUntilSessionIsAccessed(): void
    {
        $app = $this->app([]);

        self::assertNull($app->sessionIfStarted());

        $app->session();

        self::assertNotNull($app->sessionIfStarted());
    }

    public function testSessionIdsIgnoreTheConfiguredContentIdGenerator(): void
    {
        // app.ids.generator may be deliberately predictable (it scaffolds
        // content ids); session ids are bearer tokens and must not come
        // from it.
        $app = $this->app(['ids' => ['generator' => static fn(): string => 'guessable-id']]);

        $app->session()->set('user_id', 1);
        $id = $app->session()->save();

        self::assertNotNull($id);
        self::assertNotSame('guessable-id', $id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testAnIncomingSessionCookieIsPickedUpFromTheRequest(): void
    {
        $seed = $this->app([]);
        $seed->session()->set('user_id', 99);
        $id = $seed->session()->save();
        self::assertNotNull($id);

        $request = Request::create('http://example.test/', cookies: [
            $seed->sessionCookieName() => $id,
        ]);

        $next = new Application($this->root, $this->root, [], $request);

        self::assertSame(99, $next->session()->get('user_id'));
    }

    public function testAMalformedNonScalarSessionCookieIsTreatedAsNoSession(): void
    {
        // A tampered cookie sent as `garner_session[]=x` must follow the
        // documented discard path, not blow up inside Request::cookie().
        $request = Request::create('http://example.test/', cookies: [
            'garner_session' => ['x'],
        ]);
        $app = new Application($this->root, $this->root, [], $request);

        self::assertNull($app->session()->get('anything'));
        self::assertFalse($app->session()->isDirty());
    }

    public function testWithSessionInjectsAFakeForTheDurationOfTheCallback(): void
    {
        $app = $this->app([]);
        $fake = Session::fromCookie(
            new class implements SessionStore {
                public function exists(string $id): bool
                {
                    return false;
                }

                public function read(string $id): array
                {
                    return ['injected' => true];
                }

                public function write(string $id, array $data, int $lifetime): void {}

                public function destroy(string $id): void {}

                public function gc(): void {}
            },
            new class implements IdGenerator {
                public function generate(): string
                {
                    return 'fixed';
                }
            },
            3600,
            null,
        );

        $result = $app->withSession($fake, static fn(): mixed => $app->session()->get(
            'injected',
            false,
        ));

        self::assertFalse($result);
        self::assertNull($app->sessionIfStarted());
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        return new Application($this->root, $this->root, ['app' => $appConfig]);
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
