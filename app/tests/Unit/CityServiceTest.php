<?php

namespace app\tests\Unit;

use app\domain\repository\CnefeRepository;
use app\domain\service\CityService;
use PHPUnit\Framework\TestCase;

class CityServiceTest extends TestCase
{
    private string $previousTimeZone;

    protected function setUp(): void
    {
        // The API runs with this default (index.php): the bug only shows with it.
        $this->previousTimeZone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimeZone);
    }

    /**
     * Regression: imported_at is stored in UTC, but was parsed in the PHP
     * default time zone, so the API reported times three hours in the future.
     */
    public function testImportedAtIsReportedInUtc(): void
    {
        $service = new CityService($this->repositoryReturning('2026-10-13 20:18:29'));

        $city = $service->listImported()[0];

        $this->assertSame('2026-10-13T20:18:29+00:00', $city['imported_at']);
    }

    public function testMapsRepositoryRowsToTheApiShape(): void
    {
        $service = new CityService($this->repositoryReturning('2026-10-13 20:18:29'));

        $this->assertSame(
            [
                'name' => 'Limeira',
                'state' => 'SP',
                'ibge_code' => 3526902,
                'streets' => 3013,
                'house_numbers' => 92588,
                'bounds' => ['south' => -22.7, 'west' => -47.5, 'north' => -22.4, 'east' => -47.2],
                'imported_at' => '2026-10-13T20:18:29+00:00',
            ],
            $service->listImported()[0]
        );
    }

    private function repositoryReturning(string $importedAt): CnefeRepository
    {
        return new class ($importedAt) extends CnefeRepository {
            public function __construct(private string $importedAt)
            {
            }

            public function listCities(): array
            {
                return [[
                    'city_code' => 3526902,
                    'state' => 'SP',
                    'name' => 'Limeira',
                    'street_count' => 3013,
                    'address_count' => 92588,
                    'imported_at' => $this->importedAt,
                    'bounds' => ['south' => -22.7, 'west' => -47.5, 'north' => -22.4, 'east' => -47.2],
                ]];
            }
        };
    }
}
