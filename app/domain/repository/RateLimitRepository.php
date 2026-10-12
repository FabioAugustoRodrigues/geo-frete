<?php

namespace app\domain\repository;

use app\database\Connection;
use PDO;

class RateLimitRepository extends RepositoryAbstract
{
    /**
     * Counts one more request for the client in the given one-minute window
     * and returns the window's total so far.
     */
    public function increment(string $clientId, int $windowMinute): int
    {
        $pdo = Connection::getConnection();
        $hash = $this->hash($clientId);

        $upsert = $pdo->prepare(
            "INSERT INTO rate_limit (client_hash, window_minute, total)
             VALUES (:hash, :window, 1)
             ON DUPLICATE KEY UPDATE total = total + 1"
        );
        $upsert->execute([':hash' => $hash, ':window' => $windowMinute]);

        $select = $pdo->prepare(
            "SELECT total FROM rate_limit WHERE client_hash = :hash AND window_minute = :window"
        );
        $select->execute([':hash' => $hash, ':window' => $windowMinute]);

        return (int) $select->fetchColumn();
    }

    public function deleteWindowsBefore(int $windowMinute): int
    {
        $pdo = Connection::getConnection();

        $statement = $pdo->prepare("DELETE FROM rate_limit WHERE window_minute < :window");
        $statement->bindValue(':window', $windowMinute, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * Client ids contain API keys: only their hash is stored.
     */
    private function hash(string $clientId): string
    {
        return hash('sha256', $clientId);
    }
}
