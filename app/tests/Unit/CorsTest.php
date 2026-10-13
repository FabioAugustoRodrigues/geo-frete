<?php

namespace app\tests\Unit;

use app\http\Cors;
use PHPUnit\Framework\TestCase;

class CorsTest extends TestCase
{
    public function testWildcardAllowsAnyOrigin(): void
    {
        $headers = Cors::headers('*', 'https://anything.example');

        $this->assertContains('Access-Control-Allow-Origin: *', $headers);
        $this->assertContains('Access-Control-Allow-Headers: Authorization, Content-Type', $headers);
    }

    public function testEmptySettingBehavesAsWildcard(): void
    {
        $this->assertContains('Access-Control-Allow-Origin: *', Cors::headers('', null));
    }

    public function testListedOriginIsEchoedBack(): void
    {
        $headers = Cors::headers('https://site.com, http://localhost:5173', 'http://localhost:5173');

        $this->assertContains('Access-Control-Allow-Origin: http://localhost:5173', $headers);
        $this->assertContains('Vary: Origin', $headers);
        $this->assertContains('Access-Control-Allow-Methods: GET, POST, OPTIONS', $headers);
    }

    public function testTrailingSlashesDoNotMatter(): void
    {
        $headers = Cors::headers('https://site.com/', 'https://site.com');

        $this->assertContains('Access-Control-Allow-Origin: https://site.com', $headers);
    }

    public function testUnlistedOriginGetsNoPermission(): void
    {
        $headers = Cors::headers('https://site.com', 'https://evil.example');

        $this->assertSame(['Vary: Origin'], $headers);
    }

    public function testRequestWithoutOriginGetsNoPermission(): void
    {
        $this->assertSame(['Vary: Origin'], Cors::headers('https://site.com', null));
    }
}
