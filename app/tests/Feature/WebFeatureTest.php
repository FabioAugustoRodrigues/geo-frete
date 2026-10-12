<?php

namespace app\tests\Feature;

use app\core\controller\Request;
use app\core\controller\Router;
use PHPUnit\Framework\TestCase;

class WebFeatureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../routes/web.php';
    }

    public function testDocumentationPageNeedsNoApiKey(): void
    {
        ob_start();
        echo @Router::dispatch(new Request([], 'GET', '/', []));
        $html = ob_get_clean();

        $this->assertStringContainsString('<title>geo-frete', $html);
        $this->assertStringContainsString('/api/v1/distance', $html);
    }
}
