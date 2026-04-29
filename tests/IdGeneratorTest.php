<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Support\CallbackIdGenerator;
use Garner\Support\IdGenerator;
use Garner\Support\UuidV4IdGenerator;
use PHPUnit\Framework\TestCase;

final class IdGeneratorTest extends TestCase
{
    public function testUuidV4GeneratorGeneratesRfc4122UuidV4Value(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (new UuidV4IdGenerator())->generate(),
        );
    }

    public function testUuidV4GeneratorGeneratesDistinctValues(): void
    {
        $generator = new UuidV4IdGenerator();

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    public function testCallbackGeneratorNormalizesConfiguredReturnValue(): void
    {
        $generator = new CallbackIdGenerator(static fn(): string => ' custom-id ');

        self::assertSame('custom-id', $generator->generate());
    }

    public function testApplicationDefaultsToUuidV4Generator(): void
    {
        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: sys_get_temp_dir(),
            config: ['app' => []],
        );

        self::assertInstanceOf(UuidV4IdGenerator::class, $app->idGenerator());
    }

    public function testApplicationAcceptsConfiguredIdGeneratorInstance(): void
    {
        $generator = new class implements IdGenerator {
            public function generate(): string
            {
                return 'configured-id';
            }
        };

        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: sys_get_temp_dir(),
            config: [
                'app' => [
                    'ids' => [
                        'generator' => $generator,
                    ],
                ],
            ],
        );

        self::assertSame($generator, $app->idGenerator());
    }

    public function testApplicationAcceptsConfiguredIdGeneratorClass(): void
    {
        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: sys_get_temp_dir(),
            config: [
                'app' => [
                    'ids' => [
                        'generator' => UuidV4IdGenerator::class,
                    ],
                ],
            ],
        );

        self::assertInstanceOf(UuidV4IdGenerator::class, $app->idGenerator());
    }

    public function testApplicationAcceptsConfiguredIdGeneratorCallback(): void
    {
        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: sys_get_temp_dir(),
            config: [
                'app' => [
                    'ids' => [
                        'generator' => static fn(): string => 'callback-id',
                    ],
                ],
            ],
        );

        self::assertSame('callback-id', $app->idGenerator()->generate());
    }
}
