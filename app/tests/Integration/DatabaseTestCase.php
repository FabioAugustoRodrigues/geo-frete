<?php

namespace app\tests\Integration;

use app\database\Connection;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base for tests that need a real MySQL. Applies database/schema.sql before
 * the class runs, so the test database never drifts from the schema, and
 * skips the tests when the database is unreachable instead of failing them.
 */
abstract class DatabaseTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        try {
            $pdo = Connection::getConnection();
        } catch (Throwable $e) {
            self::markTestSkipped('Test database unavailable: ' . $e->getMessage());
        }

        $pdo->exec(file_get_contents(__DIR__ . '/../../../database/schema.sql'));
    }
}
