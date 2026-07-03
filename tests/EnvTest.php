<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Dotenv loading through the boot/app.php factory — the seam shared by the web
 * and CLI entrypoints, so both get identical env behavior.
 */
final class EnvTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $env;

    /**
     * @var array<string, mixed>
     */
    private array $server;

    private string $root;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        $this->server = $_SERVER;
        $this->root = sys_get_temp_dir() . '/garner-env-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;
        $this->removeDirectory($this->root);
    }

    public function testDotenvPopulatesConfigThroughTheBootFactory(): void
    {
        unset($_ENV['APP_URL'], $_ENV['APP_ENV']);
        file_put_contents($this->root . '/.env', "APP_URL=https://env.example.com\n");

        $app = $this->bootApp();

        self::assertSame('https://env.example.com', $app->config('app.url'));
        self::assertSame('https://env.example.com', $app->siteUrl());
    }

    public function testRealEnvironmentVariablesWinOverDotenvValues(): void
    {
        $_ENV['APP_URL'] = 'https://real.example.com';
        file_put_contents($this->root . '/.env', "APP_URL=https://file.example.com\n");

        $app = $this->bootApp();

        self::assertSame('https://real.example.com', $app->config('app.url'));
    }

    public function testLocalOverrideBeatsTheCommittedDefaults(): void
    {
        unset($_ENV['APP_URL'], $_ENV['APP_ENV']);
        file_put_contents($this->root . '/.env', "APP_URL=https://committed.example.com\n");
        file_put_contents($this->root . '/.env.local', "APP_URL=https://local.example.com\n");

        $app = $this->bootApp();

        self::assertSame('https://local.example.com', $app->config('app.url'));
    }

    public function testMissingDotenvIsANoOp(): void
    {
        unset($_ENV['APP_URL'], $_ENV['APP_ENV'], $_SERVER['APP_URL']);

        $app = $this->bootApp();

        self::assertNull($app->config('app.url'));
    }

    public function testServerOnlyEnvironmentIsHonoredWithoutADotenvFile(): void
    {
        // Stock php.ini ships variables_order=GPCS: real env vars land in $_SERVER
        // only, $_ENV stays empty — and with no .env file, Dotenv never runs.
        unset($_ENV['APP_URL']);
        $_SERVER['APP_URL'] = 'https://server.example.com';

        $app = $this->bootApp();

        self::assertSame('https://server.example.com', $app->config('app.url'));
    }

    public function testServerEnvironmentWinsWhenDotenvFileOmitsTheVariable(): void
    {
        // Dotenv only mirrors $_SERVER values into $_ENV for names present in the
        // file, so a var set only in the real environment must still be honored.
        unset($_ENV['APP_URL']);
        $_SERVER['APP_URL'] = 'https://server.example.com';
        file_put_contents($this->root . '/.env', "APP_DEBUG=1\n");

        $app = $this->bootApp();

        self::assertSame('https://server.example.com', $app->config('app.url'));
    }

    public function testProcessEnvironmentBeatsDotenvEvenWhenOnlyVisibleToGetenv(): void
    {
        // Dotenv skips getenv() when checking for existing real vars, so it writes
        // the file value into $_ENV — which must not shadow the process value.
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        putenv('APP_URL=https://process.example.com');
        file_put_contents($this->root . '/.env', "APP_URL=https://file.example.com\n");

        try {
            $app = $this->bootApp();

            self::assertSame('https://process.example.com', $app->config('app.url'));
        } finally {
            putenv('APP_URL');
        }
    }

    public function testHttpPrefixedNamesAreNeverTreatedAsEnvironment(): void
    {
        // $_SERVER HTTP_* entries are request headers under attacker control.
        $_SERVER['HTTP_X_APP_URL'] = 'https://evil.example.com';

        self::assertNull(\Garner\Support\Env::get('HTTP_X_APP_URL'));
    }

    private function bootApp(): Application
    {
        /** @var callable(string, string): Application $factory */
        $factory = require dirname(__DIR__) . '/boot/app.php';

        return $factory($this->root, dirname(__DIR__));
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
