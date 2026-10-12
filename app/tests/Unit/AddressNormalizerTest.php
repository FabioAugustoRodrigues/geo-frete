<?php

namespace app\tests\Unit;

use app\domain\geo\AddressNormalizer;
use PHPUnit\Framework\TestCase;

class AddressNormalizerTest extends TestCase
{
    private AddressNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AddressNormalizer();
    }

    public function testNormalizeTextRemovesAccentsCaseAndPunctuation(): void
    {
        $this->assertSame('sao joao d el rei', $this->normalizer->normalizeText("  São João d'El-Rei "));
    }

    public function testAbbreviatedAndFullStreetNamesNormalizeTheSame(): void
    {
        $abbreviated = $this->normalizer->normalizeStreet('Av. Dr. João Pessoa');
        $full = $this->normalizer->normalizeStreet('Avenida Doutor João Pessoa');

        $this->assertSame('doutor joao pessoa', $abbreviated);
        $this->assertSame($full, $abbreviated);
    }

    public function testStreetTypeAndPrepositionsAreDropped(): void
    {
        $this->assertSame('barao jaguara', $this->normalizer->normalizeStreet('Rua Barão de Jaguara'));
    }

    public function testNossaSenhoraAbbreviationsAreExpanded(): void
    {
        $this->assertSame('nossa senhora fatima', $this->normalizer->normalizeStreet('R. N. Sra. de Fátima'));
    }

    public function testStreetTypeIsDetectedFromAbbreviations(): void
    {
        $this->assertSame('avenida', $this->normalizer->streetType('Av. Brasil'));
        $this->assertSame('rua', $this->normalizer->streetType('R Tiradentes'));
        $this->assertNull($this->normalizer->streetType('Senador Vergueiro'));
    }

    public function testHouseNumberKeepsOnlyTheLeadingDigits(): void
    {
        $this->assertSame(500, $this->normalizer->houseNumber('500'));
        $this->assertSame(500, $this->normalizer->houseNumber(' 500A '));
        $this->assertSame(12, $this->normalizer->houseNumber('12 fundos'));
        $this->assertNull($this->normalizer->houseNumber('S/N'));
        $this->assertNull($this->normalizer->houseNumber('0'));
    }

    public function testCacheKeyIgnoresFormattingDifferences(): void
    {
        $this->assertSame(
            $this->normalizer->cacheKey('sp', 'Limeira', 'R. Senador Vergueiro', '500'),
            $this->normalizer->cacheKey('SP', ' LIMEIRA ', 'Rua Senador Vergueiro', '500')
        );
    }
}
