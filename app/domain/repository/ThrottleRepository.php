<?php

namespace app\domain\repository;

use app\database\Connection;
use PDO;
use Throwable;

class ThrottleRepository extends RepositoryAbstract
{
    /** Nominatim's usage policy allows at most one request per second. */
    private const MIN_INTERVAL_MS = 1000;

    /**
     * Blocks until the next call to the provider is allowed. The provider's
     * row stays locked (FOR UPDATE) during the wait, so concurrent PHP
     * processes are serialized correctly, not just requests within one process.
     */
    public function waitForTurn(string $provider): void
    {
        $pdo = Connection::getConnection();
        $pdo->beginTransaction();

        try {
            $select = $pdo->prepare(
                "SELECT last_call_ms FROM provider_throttle WHERE provider = :provider FOR UPDATE"
            );
            $select->execute([':provider' => $provider]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                // INSERT IGNORE: two processes can both miss the row; the
                // loser of that race simply proceeds without waiting once.
                $insert = $pdo->prepare(
                    "INSERT IGNORE INTO provider_throttle (provider, last_call_ms) VALUES (:provider, :now)"
                );
                $insert->execute([':provider' => $provider, ':now' => $this->nowMs()]);
                $pdo->commit();
                return;
            }

            $elapsedMs = $this->nowMs() - (int) $row['last_call_ms'];
            if ($elapsedMs < self::MIN_INTERVAL_MS) {
                usleep((self::MIN_INTERVAL_MS - $elapsedMs) * 1000);
            }

            $update = $pdo->prepare(
                "UPDATE provider_throttle SET last_call_ms = :now WHERE provider = :provider"
            );
            $update->execute([':now' => $this->nowMs(), ':provider' => $provider]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
