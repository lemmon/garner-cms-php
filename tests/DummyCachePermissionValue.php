<?php

declare(strict_types=1);

namespace Garner\Tests;

final class DummyCachePermissionValue
{
    public static string $cachePath = '';

    public static int|false|null $permissionsDuringUnserialize = null;

    /**
     * @param array<array-key, mixed> $_data
     */
    public function __unserialize(array $_data): void
    {
        clearstatcache(false, self::$cachePath);
        self::$permissionsDuringUnserialize = fileperms(self::$cachePath);
    }

    public static function observedPermissions(): int|false|null
    {
        return self::$permissionsDuringUnserialize;
    }
}
