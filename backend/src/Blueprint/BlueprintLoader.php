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
        $this->assertBlueprintName($name);

        $path = $this->blueprintPath($name);

        if (!is_file($path)) {
            throw new BlueprintException(sprintf('Blueprint "%s" was not found', $name));
        }

        try {
            $blueprint = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new BlueprintException(
                sprintf('Blueprint "%s" could not be parsed: %s', $name, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($blueprint)) {
            throw new BlueprintException(sprintf(
                'Blueprint "%s" must parse to a top-level mapping',
                $name,
            ));
        }

        $this->validateBlueprint($name, $blueprint);

        return $blueprint;
    }

    private function assertBlueprintName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new BlueprintException(sprintf('Invalid blueprint name "%s"', $name));
        }
    }

    private function blueprintPath(string $name): string
    {
        return $this->blueprintsPath . '/' . $name . '.yml';
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
