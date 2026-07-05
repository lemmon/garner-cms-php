<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\StoreGetCommand;
use Garner\Cli\StoreListCommand;
use Garner\Cli\StoreRemoveCommand;
use Garner\Cli\StoreSetCommand;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StoreCommandsTest extends TestCase
{
    private string $root;

    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-store-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->app = new Application($this->root, $this->root, []);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSetThenGetRoundTripsThroughTheCli(): void
    {
        $set = $this->runCommand(new StoreSetCommand($this->app), [
            'key' => 'settings:theme',
            'value' => '{"name":"dark"}',
        ]);

        self::assertSame(Command::SUCCESS, $set->getStatusCode());

        $get = $this->runCommand(new StoreGetCommand($this->app), ['key' => 'settings:theme']);

        self::assertSame(Command::SUCCESS, $get->getStatusCode());
        self::assertStringContainsString('"name": "dark"', $get->getDisplay());
    }

    public function testSetRejectsInvalidJson(): void
    {
        $tester = $this->runCommand(new StoreSetCommand($this->app), [
            'key' => 'bad',
            'value' => '{not json',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFalse($this->app->store()->has('bad'));
    }

    public function testSetAcceptsAJsonNullValue(): void
    {
        $tester = $this->runCommand(new StoreSetCommand($this->app), [
            'key' => 'nothing',
            'value' => 'null',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->app->store()->has('nothing'));
    }

    public function testGetFailsForAMissingKey(): void
    {
        $tester = $this->runCommand(new StoreGetCommand($this->app), ['key' => 'missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No value stored under "missing"', $tester->getDisplay());
    }

    public function testListFiltersByPrefix(): void
    {
        $this->app->store()->set('email:a', ['email' => 'a@example.test']);
        $this->app->store()->set('email:b', ['email' => 'b@example.test']);
        $this->app->store()->set('settings:theme', 'dark');

        $tester = $this->runCommand(new StoreListCommand($this->app), ['prefix' => 'email:']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('email:a', $tester->getDisplay());
        self::assertStringContainsString('email:b', $tester->getDisplay());
        self::assertStringNotContainsString('settings:theme', $tester->getDisplay());
        self::assertStringContainsString('2 item(s)', $tester->getDisplay());
    }

    public function testListAsJsonOutputsAnObjectKeyedByFullKey(): void
    {
        $this->app->store()->set('email:a', ['email' => 'a@example.test']);

        $tester = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame(['email:a' => ['email' => 'a@example.test']], $decoded);
    }

    public function testListAsJsonKeepsNumericKeysAsObjectKeys(): void
    {
        // PHP coerces the store key "0" to an integer array key; the JSON
        // output must still be an object keyed by full key, not a
        // keyless array.
        $this->app->store()->set('0', 'zero');
        $this->app->store()->set('1', 'one');

        $tester = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('"0": "zero"', $tester->getDisplay());
        self::assertStringContainsString('"1": "one"', $tester->getDisplay());
    }

    public function testListAsJsonKeepsListValuesAsArrays(): void
    {
        // The object cast must apply to the top level only: a stored
        // list value stays a JSON array.
        $this->app->store()->set('tags', ['a', 'b']);

        $tester = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame(['tags' => ['a', 'b']], $decoded);
    }

    public function testWholeNumberFloatsSurviveTheCliRoundTrip(): void
    {
        $this->app->store()->set('ratio', 2.0);

        $get = $this->runCommand(new StoreGetCommand($this->app), ['key' => 'ratio']);
        self::assertSame('2.0', trim($get->getDisplay()));

        $list = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);
        self::assertStringContainsString('"ratio": 2.0', $list->getDisplay());

        // The full circle: feeding store:get's output back into store:set
        // must keep the value a float.
        $this->runCommand(new StoreSetCommand($this->app), [
            'key' => 'ratio2',
            'value' => trim($get->getDisplay()),
        ]);
        self::assertSame(2.0, $this->app->store()->get('ratio2'));
    }

    public function testConsoleMarkupShapedValuesSurviveTheJsonOutputs(): void
    {
        // Non-decorated console output strips formatter tags, so without
        // raw output a stored "<error>x</error>" would print as "x" —
        // corrupted JSON in the documented piping round trip.
        $this->app->store()->set('snippet', '<error>x</error>');

        $get = $this->runCommand(new StoreGetCommand($this->app), ['key' => 'snippet']);
        self::assertSame('<error>x</error>', json_decode(trim($get->getDisplay())));

        $list = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);
        $decoded = json_decode($list->getDisplay(), true);
        self::assertSame(['snippet' => '<error>x</error>'], $decoded);
    }

    public function testConsoleMarkupShapedValuesDisplayLiterallyInTheHumanList(): void
    {
        $this->app->store()->set('<info>k</info>', '<error>x</error>');

        $tester = $this->runCommand(new StoreListCommand($this->app), []);

        self::assertStringContainsString('<info>k</info>', $tester->getDisplay());
        self::assertStringContainsString('<error>x</error>', $tester->getDisplay());
    }

    public function testListOnAnEmptyStoreSucceeds(): void
    {
        $tester = $this->runCommand(new StoreListCommand($this->app), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('The store is empty.', $tester->getDisplay());

        $json = $this->runCommand(new StoreListCommand($this->app), ['--json' => true]);

        self::assertSame('{}', trim($json->getDisplay()));
    }

    public function testRemoveDeletesTheKey(): void
    {
        $this->app->store()->set('key', 'value');

        $tester = $this->runCommand(new StoreRemoveCommand($this->app), ['key' => 'key']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Removed "key"', $tester->getDisplay());
        self::assertFalse($this->app->store()->has('key'));
    }

    public function testRemoveOnAMissingKeySucceedsAndSaysSo(): void
    {
        $tester = $this->runCommand(new StoreRemoveCommand($this->app), ['key' => 'missing']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing stored under "missing"', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(Command $command, array $input): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
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
