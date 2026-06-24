<?php

declare(strict_types=1);

namespace Garner\Content;

final class ValidationIssue
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
    ) {}

    /**
     * @return array{path: string, message: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'message' => $this->message];
    }
}
