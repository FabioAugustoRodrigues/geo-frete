<?php

namespace app\http\middleware;

use app\core\controller\Request;
use app\domain\repository\RateLimitRepository;
use app\exception\http\ApplicationHttpException;
use app\http\ApiKeys;

/**
 * Must run after ApiKeyMiddleware, so that a key reaching this point is valid.
 */
class RateLimitMiddleware extends Middleware
{
    /** Windows older than this many minutes are useless and can be purged. */
    private const WINDOWS_KEPT = 5;

    public function __construct(private RateLimitRepository $rateLimitRepository)
    {
    }

    public function before(Request $request)
    {
        $windowMinute = intdiv(time(), 60);

        $total = $this->rateLimitRepository->increment($this->identifyClient($request), $windowMinute);

        // Opportunistic cleanup, so no dedicated cron job is needed.
        if (random_int(1, 500) === 1) {
            $this->rateLimitRepository->deleteWindowsBefore($windowMinute - self::WINDOWS_KEPT);
        }

        if ($total > RATE_LIMIT_PER_MINUTE) {
            throw new ApplicationHttpException("Rate limit exceeded. Try again in a moment.", 429);
        }
    }

    private function identifyClient(Request $request): string
    {
        // Only trust the key when keys are enforced: with authentication off,
        // any made-up Bearer value would otherwise get a fresh quota.
        if (ApiKeys::isEnabled()) {
            $key = ApiKeys::fromRequest($request);
            if ($key !== null) {
                return 'key:' . $key;
            }
        }

        return 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
