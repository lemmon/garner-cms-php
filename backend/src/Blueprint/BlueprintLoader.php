<?php

declare(strict_types=1);

namespace Garner\Blueprint;

use Lemmon\Validator\FieldValidator;
use Lemmon\Validator\ValidationException;
use Lemmon\Validator\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class BlueprintLoader
{
    public function __construct(
        private readonly string $blueprintsPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $name): array
    {
        $this->assertBlueprintReference($name);

        $blueprint = $this->resolveValue($this->parseBlueprintFile($name), [$name]);

        if (!is_array($blueprint) || array_is_list($blueprint)) {
            throw new BlueprintException(sprintf(
                'Blueprint "%s" must parse to a top-level mapping',
                $name,
            ));
        }

        $this->validateBlueprint($name, $blueprint);

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
        $this->assertBlueprintReference($name);

        return $this->load('pages/' . $name);
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
            return $resolved;
        }

        $reference = trim($extends);
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

        return $this->mergeMappings($base, $resolved);
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

    /**
     * @param array<string, mixed> $blueprint
     */
    private function validateBlueprint(string $name, array $blueprint): void
    {
        $errors = $this->validationMessages('', $blueprint, $this->rootValidator());
        $tabs = $blueprint['tabs'] ?? null;

        if (is_array($tabs) && array_is_list($tabs)) {
            foreach ($tabs as $index => $tab) {
                $tabPath = 'tabs.' . (string) $index;
                $errors = [
                    ...$errors,
                    ...$this->validationMessages($tabPath, $tab, $this->tabValidator()),
                ];

                $nodes = is_array($tab) ? $tab['nodes'] ?? null : null;
                if (!is_array($nodes) || !array_is_list($nodes)) {
                    continue;
                }

                foreach ($nodes as $index => $node) {
                    $errors = [
                        ...$errors,
                        ...$this->nodeValidationMessages(
                            $tabPath . '.nodes.' . (string) $index,
                            $node,
                        ),
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw BlueprintException::validationFailed($name, $errors);
        }
    }

    /**
     * @return list<string>
     */
    private function nodeValidationMessages(string $path, mixed $node): array
    {
        $errors = $this->validationMessages($path, $node, $this->nodeBaseValidator());

        if (!is_array($node)) {
            return $errors;
        }

        $type = $node['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return $errors;
        }

        return match ($type) {
            'text' => [
                ...$errors,
                ...$this->validationMessages($path, $node, $this->textNodeValidator()),
            ],
            'textarea' => [
                ...$errors,
                ...$this->validationMessages($path, $node, $this->textareaNodeValidator()),
            ],
            'page_list' => [
                ...$errors,
                ...$this->validationMessages($path, $node, $this->pageListValidator()),
            ],
            'file_list' => [
                ...$errors,
                ...$this->validationMessages($path, $node, $this->fileListValidator()),
            ],
            default => [
                ...$errors,
                sprintf('%s.type: Unsupported node type "%s"', $path, $type),
            ],
        };
    }

    private function rootValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'description' => Validator::isString(),
            'title' => Validator::isString()->required()->notEmpty(),
            'tabs' => Validator::isArray()->required(),
        ]);
    }

    private function tabValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
            'nodes' => Validator::isArray()->required(),
        ]);
    }

    private function nodeBaseValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'type' => Validator::isString()->required()->notEmpty(),
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
        ]);
    }

    private function pageListValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'type' => Validator::isString()->const('page_list')->required(),
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
            'source' => Validator::isString()->required()->notEmpty(),
            'query' => Validator::isString(),
            'create' => Validator::isAssociative([
                'enabled' => Validator::isBool()->required(),
            ]),
            'empty' => Validator::isString(),
            'help' => Validator::isString(),
        ]);
    }

    private function textNodeValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'type' => Validator::isString()->const('text')->required(),
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
            'help' => Validator::isString(),
            'placeholder' => Validator::isString(),
        ]);
    }

    private function textareaNodeValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'type' => Validator::isString()->const('textarea')->required(),
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
            'help' => Validator::isString(),
            'placeholder' => Validator::isString(),
            'rows' => Validator::isInt(),
        ]);
    }

    private function fileListValidator(): FieldValidator
    {
        return Validator::isAssociative([
            'type' => Validator::isString()->const('file_list')->required(),
            'name' => Validator::isString()->required()->notEmpty(),
            'label' => Validator::isString()->required()->notEmpty(),
            'source' => Validator::isString()->required()->notEmpty(),
            'query' => Validator::isString(),
            'upload' => Validator::isAssociative([
                'enabled' => Validator::isBool()->required(),
            ]),
            'empty' => Validator::isString(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function validationMessages(
        string $path,
        mixed $value,
        FieldValidator $validator,
    ): array {
        [$valid, , $errors] = $validator->tryValidate($value);

        if ($valid) {
            return [];
        }

        return array_values(array_map(static function (array $error) use ($path): string {
            $errorPath = $error['path'];
            $fullPath = $path;

            if ($errorPath !== '' && $errorPath !== '_root') {
                $fullPath = $fullPath === '' ? $errorPath : $fullPath . '.' . $errorPath;
            }

            return sprintf('%s: %s', $fullPath === '' ? '_root' : $fullPath, $error['message']);
        }, ValidationException::flattenErrors($errors)));
    }
}
