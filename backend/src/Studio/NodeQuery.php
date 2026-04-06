<?php

declare(strict_types=1);

namespace Garner\Studio;

use InvalidArgumentException;
use RuntimeException;

final class NodeQuery
{
    public function __construct(
        private readonly PageListQuery $pageListQuery,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function query(array $payload): array
    {
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';

        return match ($type) {
            'page_list' => $this->pageListQuery->query($payload),
            'file_list' => throw new RuntimeException('File list queries are not implemented yet'),
            default => throw new InvalidArgumentException('Unsupported node query type'),
        };
    }
}
