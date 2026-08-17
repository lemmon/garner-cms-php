<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\IdGenerateCommand;
use Garner\Core\Application;
use Garner\Support\IdGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IdGenerateCommandTest extends TestCase
{
    public function testGeneratesASingleIdByDefault(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $lines = array_values(array_filter(explode("\n", $tester->getDisplay())));
        self::assertCount(1, $lines);
        self::assertMatchesRegularExpression('/^[a-z0-9]{24,}$/', $lines[0]);
    }

    public function testCountEmitsThatManyDistinctIds(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $lines = array_values(array_filter(explode("\n", $tester->getDisplay())));
        self::assertCount(5, $lines);
        self::assertCount(5, array_unique($lines));
    }

    public function testJsonOutputsAnArray(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => '3',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertCount(3, $decoded);
    }

    public function testRejectsAZeroCount(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => '0',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    public function testRejectsANonNumericCount(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => 'many',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    public function testRejectsACountAboveTheMaximum(): void
    {
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => '10001',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no greater than 10000', $tester->getDisplay());
    }

    public function testRejectsACountThatOverflowsToPhpIntMaxOnCast(): void
    {
        // An all-digit string past PHP_INT_MAX still passes ctype_digit()
        // and (int) casts it down to PHP_INT_MAX rather than erroring — the
        // max-count check must catch this, or a 20-digit typo turns into an
        // effectively unbounded generation loop.
        $tester = $this->runCommand(new Application(sys_get_temp_dir(), sys_get_temp_dir()), [
            '--count' => '9223372036854775808',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no greater than 10000', $tester->getDisplay());
    }

    public function testUsesTheProjectsConfiguredGenerator(): void
    {
        $generator = new class implements IdGenerator {
            public function generate(): string
            {
                return 'fixed-id';
            }
        };

        $app = new Application(sys_get_temp_dir(), sys_get_temp_dir(), [
            'app' => ['ids' => ['generator' => $generator]],
        ]);

        $tester = $this->runCommand($app, []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('fixed-id', trim($tester->getDisplay()));
    }

    public function testJsonModeFailsRatherThanEmitInvalidJsonForANonUtf8Id(): void
    {
        // A custom generator (app.ids.generator accepts a class or callable)
        // could return anything; json_encode() rejects invalid UTF-8 and
        // returns false rather than throwing, which the command must not
        // mistake for an empty-but-successful result.
        $generator = new class implements IdGenerator {
            public function generate(): string
            {
                return "\xB1\x31";
            }
        };

        $app = new Application(sys_get_temp_dir(), sys_get_temp_dir(), [
            'app' => ['ids' => ['generator' => $generator]],
        ]);

        $tester = $this->runCommand($app, ['--json' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not be encoded as JSON', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(Application $app, array $input): CommandTester
    {
        $tester = new CommandTester(new IdGenerateCommand($app));
        $tester->execute($input);

        return $tester;
    }
}
