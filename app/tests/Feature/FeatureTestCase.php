<?php

namespace app\tests\Feature;

use app\core\controller\Request;
use app\core\controller\Router;
use app\tests\Integration\DatabaseTestCase;

/**
 * Base for end-to-end API tests. Requests go straight through the Router,
 * without a real HTTP server; external providers are replaced by fakes
 * (see app/config/container.php, active when APP_ENV=testing).
 */
abstract class FeatureTestCase extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__ . '/../../routes/api.php';
    }

    /**
     * @return array{status: int, body: mixed}
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = []): array
    {
        $request = new Request($data, $method, $uri, $headers);

        // Outside a real HTTP server, header()/http_response_code() warn with
        // "headers already sent" once PHPUnit has printed anything. That is
        // harmless here, so it is silenced only at this dispatch point,
        // without touching the framework core.
        // Controllers print their response, while RequestHandler *returns* the
        // error response; echoing the return value mirrors Application::run().
        ob_start();
        echo @Router::dispatch($request);
        $body = ob_get_clean();

        return [
            'status' => http_response_code(),
            'body' => json_decode($body, true),
        ];
    }
}
