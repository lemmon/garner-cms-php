<?php

declare(strict_types=1);

namespace Garner\Blueprint;

use Lemmon\Validator\FieldValidator;
use Lemmon\Validator\ValidationException;
use Lemmon\Validator\Validator;

final class BlueprintValidator
{
    /**
     * @param array<string, mixed> $blueprint
     */
    public function validate(string $name, array $blueprint): void
    {
        $errors = $this->collectMessages('', $blueprint, $this->rootValidator());
        $tabs = $blueprint['tabs'] ?? null;

        if (is_array($tabs) && array_is_list($tabs)) {
            foreach ($tabs as $index => $tab) {
                $tabPath = 'tabs.' . (string) $index;
                $errors = [
                    ...$errors,
                    ...$this->collectMessages($tabPath, $tab, $this->tabValidator()),
                ];

                $nodes = is_array($tab) ? $tab['nodes'] ?? null : null;
                if (!is_array($nodes) || !array_is_list($nodes)) {
                    continue;
                }

                foreach ($nodes as $index => $node) {
                    $errors = [
                        ...$errors,
                        ...$this->nodeMessages($tabPath . '.nodes.' . (string) $index, $node),
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
    private function nodeMessages(string $path, mixed $node): array
    {
        $errors = $this->collectMessages($path, $node, $this->nodeBaseValidator());

        if (!is_array($node)) {
            return $errors;
        }

        $type = $node['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return $errors;
        }

        $typeValidator = $this->nodeTypeValidator($type);

        if ($typeValidator === null) {
            return [
                ...$errors,
                sprintf('%s.type: Unsupported node type "%s"', $path, $type),
            ];
        }

        return [...$errors, ...$this->collectMessages($path, $node, $typeValidator)];
    }

    private function nodeTypeValidator(string $type): ?FieldValidator
    {
        return match ($type) {
            'text' => $this->textNodeValidator(),
            'textarea' => $this->textareaNodeValidator(),
            'page_list' => $this->pageListValidator(),
            'file_list' => $this->fileListValidator(),
            default => null,
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
    private function collectMessages(string $path, mixed $value, FieldValidator $validator): array
    {
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
