<?php

namespace app\database;

use \PDO;
use PDOException;
use app\exception\ApplicationException;

abstract class Connection
{

    private static $connection;

    public static function getConnection()
    {
        if (self::$connection == null) {
            try {
                $dsn = sprintf("%s:host=%s;port=%d;dbname=%s;charset=utf8mb4", DB_DRIVER, DB_HOST, DB_PORT, DB_NAME);
                self::$connection = new PDO($dsn, DB_USER, DB_PASS);

                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Every DATETIME written with NOW() is UTC, whatever the MySQL
                // server's own time zone: PHP reads them back as UTC.
                self::$connection->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                error_log("[DATABASE CONNECTION ERROR] " . $e->getMessage());
                throw new ApplicationException("Unable to connect to the database.");
            }
        }

        return self::$connection;
    }

    public static function disconnect()
    {
        self::$connection = null;
    }
}
