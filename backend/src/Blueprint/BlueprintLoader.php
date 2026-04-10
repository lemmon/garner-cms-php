<?php

declare(strict_types=1);

namespace Garner\Blueprint;

use Garner\Support\Identifier;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class BlueprintLoader
{
    private readonly BlueprintValidator $validator;

    public function __construct(
        private readonly string $blueprintsPath,
    ) {
        $this->validator = new BlueprintValidator();
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $name): array
    {
        $name = $this->normalizeBlueprintReference($name);
        $this->assertBlueprintReference($name);

        $blueprint = $this->resolveValue($this->parseBlueprintFile($name), [$name]);

        if (!is_array($blueprint) || array_is_list($blueprint)) {
            throw new BlueprintException(sprintf(
                'Blueprint "%s" must parse to a top-level mapping',
                $name,
            ));
        }

        $this->validator->validate($name, $blueprint);

        return $blueprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSite(): array
    {
        return $this->load('site');
    }

    /**
     * @return array<string, mixed>
     */
    public function loadPage(string $name): array
    {
        return $this->load('pages/' . Identifier::kebab($name));
    }

    private function assertBlueprintReference(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $name) !== 1) {
            throw new BlueprintException(sprintf('Invalid blueprint reference "%s"', $name));
        }
    }

    private function blueprintPath(string $name): string
    {
        return $this->blueprintsPath . '/' . $name . '.yml';
    }

    private function parseBlueprintFile(string $name): mixed
    {
        $path = $this->blueprintPath($name);

        if (!is_file($path)) {
            throw new BlueprintException(sprintf('Blueprint "%s" was not found', $name));
        }

        try {
            return Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new BlueprintException(
                sprintf('Blueprint "%s" could not be parsed: %s', $name, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * @param list<string> $stack
     */
    private function resolveValue(mixed $value, array $stack): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->resolveValue($item, $stack), $value);
        }

        $resolved = [];

        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveValue($item, $stack);
        }

        $extends = $resolved['extends'] ?? null;

        if (!is_string($extends) || trim($extends) === '') {
            if (is_string($resolved['type'] ?? null)) {
                $resolved['type'] = Identifier::snake($resolved['type']);
            }

            return $resolved;
        }

        $reference = $this->normalizeBlueprintReference(trim($extends));
        $this->assertBlueprintReference($reference);

        if (in_array($reference, $stack, true)) {
            throw new BlueprintException(sprintf(
                'Blueprint "%s" creates a cyclic extends chain: %s',
                $stack[0],
                implode(' -> ', [...$stack, $reference]),
            ));
        }

        $base = $this->resolveValue($this->parseBlueprintFile($reference), [...$stack, $reference]);

        if (!is_array($base) || array_is_list($base)) {
            throw new BlueprintException(sprintf(
                'Blueprint fragment "%s" must resolve to a mapping',
                $reference,
            ));
        }

        unset($resolved['extends']);

        $resolved = $this->mergeMappings($base, $resolved);

        if (is_string($resolved['type'] ?? null)) {
            $resolved['type'] = Identifier::snake($resolved['type']);
        }

        return $resolved;
    }

    private function normalizeBlueprintReference(string $name): string
    {
        return Identifier::kebabPath($name);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeMappings(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $key => $value) {
            $current = $merged[$key] ?? null;

            if (
                is_array($current)
                && !array_is_list($current)
                && is_array($value)
                && !array_is_list($value)
            ) {
                $merged[$key] = $this->mergeMappings($current, $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }
}
