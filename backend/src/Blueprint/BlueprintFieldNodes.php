<?php

declare(strict_types=1);

namespace Garner\Blueprint;

use Lemmon\Validator\FieldValidator;
use Lemmon\Validator\Validator;

final class BlueprintFieldNodes
{
    /**
     * @param array<string, mixed> $blueprint
     * @return array<string, FieldValidator>
     */
    public static function validationSchema(array $blueprint): array
    {
        $schema = [];

        foreach (self::editableNodes($blueprint) as $node) {
            $name = $node['name'] ?? null;
            $type = $node['type'] ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            if (!is_string($type)) {
                continue;
            }

            if (!self::isEditableType($type)) {
                continue;
            }

            $schema[$name] = Validator::isString();
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return list<array<string, mixed>>
     */
    public static function editableNodes(array $blueprint): array
    {
        $tabs = $blueprint['tabs'] ?? null;

        if (!is_array($tabs) || !array_is_list($tabs)) {
            return [];
        }

        $nodes = [];

        foreach ($tabs as $tab) {
            $tabNodes = is_array($tab) ? $tab['nodes'] ?? null : null;

            if (!is_array($tabNodes) || !array_is_list($tabNodes)) {
                continue;
            }

            foreach ($tabNodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $type = $node['type'] ?? null;

                if (!is_string($type) || !self::isEditableType($type)) {
                    continue;
                }

                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public static function isEditableType(string $type): bool
    {
        return in_array($type, ['text', 'textarea'], true);
    }
}
