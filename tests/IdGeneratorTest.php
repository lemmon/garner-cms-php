<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Support\IdGenerator;
use Garner\Support\IdGeneratorType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdGeneratorTest extends TestCase
{
    public function testDefaultIsCuid2(): void
    {
        $id = $this->app([])->idGenerator()->generate();

        self::assertMatchesRegularExpression('/^[a-z][0-9a-z]{23}$/', $id);
    }

    public function testEnumCaseSelectsBuiltIn(): void
    {
        $id = $this->app(['ids' => [
            'generator' => IdGeneratorType::Ulid,
        ]])->idGenerator()->generate();

        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $id);
    }

    public function testStringNameSelectsBuiltIn(): void
    {
        $id = $this->app(['ids' => ['generator' => 'uuid_v4']])->idGenerator()->generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testCallableIsWrappedAsGenerator(): void
    {
        $app = $this->app(['ids' => ['generator' => static fn(): string => 'custom-id']]);

        self::assertSame('custom-id', $app->idGenerator()->generate());
    }

    public function testInstanceIsUsedAsIs(): void
    {
        $generator = new class implements IdGenerator {
            public function generate(): string
            {
                return 'fixed-id';
            }
        };

        $app = $this->app(['ids' => ['generator' => $generator]]);

        self::assertSame('fixed-id', $app->idGenerator()->generate());
    }

    public function testInvalidGeneratorThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->app(['ids' => ['generator' => 'not-a-generator']])->idGenerator()->generate();
    }

    /**
     * @param array<string, mixed> $app
     */
    private function app(array $app): Application
    {
        return new Application(sys_get_temp_dir(), sys_get_temp_dir(), ['app' => $app]);
    }
}
