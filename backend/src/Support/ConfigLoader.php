<?php

declare(strict_types=1);

namespace Garner\Support;

final class ConfigLoader
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $configPath): array
    {
        $config = [];
        $files = glob($configPath . '/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $loaded = require $file;

            if (is_array($loaded)) {
                $config[$key] = $loaded;
            }
        }

        return $config;
    }
}
