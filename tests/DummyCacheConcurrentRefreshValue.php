<?php

declare(strict_types=1);

namespace Garner\Tests;

use PDO;
use RuntimeException;

final class DummyCacheConcurrentRefreshValue
{
    public static string $cachePath = '';

    /**
     * Refresh the row through another connection while Cache is decoding the
     * value, then make the originally fetched payload count as corrupt.
     *
     * @param array<array-key, mixed> $_data
     */
    public function __unserialize(array $_data): void
    {
        $pdo = new PDO('sqlite:' . self::$cachePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 0,
        ]);
        $pdo->exec('BEGIN IMMEDIATE');
        $statement = $pdo->prepare(
            'UPDATE cache SET value = :value, expires = NULL WHERE key = :key',
        );
        $statement->execute([
            ':key' => 'refreshing',
            ':value' => serialize('fresh'),
        ]);
        $pdo->exec('COMMIT');

        throw new RuntimeException('Treat the originally fetched payload as corrupt');
    }
}
