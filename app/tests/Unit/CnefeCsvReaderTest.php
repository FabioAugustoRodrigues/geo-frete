<?php

namespace app\tests\Unit;

use app\domain\geo\AddressNormalizer;
use app\domain\geo\CnefeCsvReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CnefeCsvReaderTest extends TestCase
{
    /** Real CNEFE header, trimmed of columns the reader does not use. */
    private const HEADER = 'COD_UNICO_ENDERECO;CEP;DSC_LOCALIDADE;NOM_TIPO_SEGLOGR;NOM_TITULO_SEGLOGR;NOM_SEGLOGR;NUM_ENDERECO;DSC_MODIFICADOR;LATITUDE;LONGITUDE;NV_GEO_COORD';

    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'cnefe');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMergesApartmentsOfTheSameNumber(): void
    {
        $streets = $this->read([
            '1;13480005;CENTRO;RUA;SENADOR;VERGUEIRO;485;;-22.5600;-47.4000;1',
            '2;13480005;CENTRO;RUA;SENADOR;VERGUEIRO;485;;-22.5602;-47.4002;2',
            '3;13480005;CENTRO;RUA;SENADOR;VERGUEIRO;485;;-22.5604;-47.4004;2',
            '4;13480001;CENTRO;RUA;SENADOR;VERGUEIRO;500;;-22.5610;-47.4010;3',
        ]);

        $this->assertCount(1, $streets);
        $street = $streets[0];

        $this->assertSame('rua', $street['street_type']);
        $this->assertSame('Rua Senador Vergueiro', $street['name']);
        $this->assertSame('senador vergueiro', $street['name_normalized']);
        $this->assertSame('Centro', $street['neighborhood']);
        $this->assertSame([485, 500], array_keys($street['addresses']));

        // Level 1 beats level 2: the two adjusted apartment points are ignored.
        $this->assertSame(['lat' => -22.56, 'lng' => -47.4, 'geo_level' => 1, 'postal_code' => '13480005'], $street['addresses'][485]);
    }

    public function testAveragesPointsOfTheSameBestLevel(): void
    {
        $streets = $this->read([
            '1;13480005;CENTRO;RUA;;JOSE;10;;-22.5600;-47.4000;2',
            '2;13480005;CENTRO;RUA;;JOSE;10;;-22.5604;-47.4004;2',
        ]);

        $this->assertSame(-22.5602, $streets[0]['addresses'][10]['lat']);
        $this->assertSame(-47.4002, $streets[0]['addresses'][10]['lng']);
    }

    public function testSkipsCoarseLevelsAndMissingNumbersButKeepsTheStreet(): void
    {
        $streets = $this->read([
            '1;13480005;JARDIM;RUA;;FLORES;0;SN;-22.5600;-47.4000;1',
            '2;13480005;JARDIM;RUA;;FLORES;20;;-22.5600;-47.4000;4',
        ]);

        $this->assertSame('Rua Flores', $streets[0]['name']);
        $this->assertSame([], $streets[0]['addresses']);
    }

    public function testSameNameWithDifferentTypesAreDifferentStreets(): void
    {
        $streets = $this->read([
            '1;13480005;CENTRO;RUA;;ANTONIO CONSELHEIRO;10;;-22.58;-47.42;1',
            '2;13480005;CENTRO;AVENIDA;;ANTONIO CONSELHEIRO;10;;-22.59;-47.43;1',
        ]);

        $this->assertEqualsCanonicalizing(['Rua Antonio Conselheiro', 'Avenida Antonio Conselheiro'], array_column($streets, 'name'));
    }

    public function testIgnoresUnnamedStreets(): void
    {
        $streets = $this->read(['1;13480005;CENTRO;RUA;;SEM DENOMINACAO;10;;-22.58;-47.42;1']);

        $this->assertSame([], $streets);
    }

    public function testRejectsFilesThatAreNotCnefe(): void
    {
        file_put_contents($this->path, "a;b;c\n1;2;3\n");

        $this->expectException(RuntimeException::class);

        (new CnefeCsvReader(new AddressNormalizer()))->read($this->path);
    }

    private function read(array $rows): array
    {
        file_put_contents($this->path, self::HEADER . "\n" . implode("\n", $rows) . "\n");

        return (new CnefeCsvReader(new AddressNormalizer()))->read($this->path);
    }
}
