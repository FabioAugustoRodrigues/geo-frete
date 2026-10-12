<?php

namespace app\tests\Integration;

use app\domain\geo\AddressNormalizer;
use app\domain\model\StreetMatch;
use app\domain\repository\CnefeRepository;
use app\domain\service\StreetMatcherService;
use app\tests\Fixture\CnefeFixture;

class StreetMatcherServiceTest extends DatabaseTestCase
{
    private StreetMatcherService $matcher;

    protected function setUp(): void
    {
        CnefeFixture::clear();
        CnefeFixture::load();
        $this->matcher = new StreetMatcherService(new CnefeRepository(), new AddressNormalizer());
    }

    protected function tearDown(): void
    {
        CnefeFixture::clear();
    }

    public function testKnownNameIsExact(): void
    {
        $match = $this->match('Av. Senador Vergueiro');

        $this->assertSame(StreetMatch::EXACT, $match->status, 'a single street with that name is exact even with another type');
        $this->assertSame('Rua Senador Vergueiro', $match->name);
    }

    public function testTypoIsCorrected(): void
    {
        $match = $this->match('R. Senador Vergeiro');

        $this->assertSame(StreetMatch::CORRECTED, $match->status);
        $this->assertSame('Rua Senador Vergueiro', $match->name);
    }

    public function testSwappedWordsAreCorrected(): void
    {
        $this->assertSame(StreetMatch::CORRECTED, $this->match('Vergueiro Senador')->status);
    }

    public function testSameNameWithoutTypeIsAmbiguous(): void
    {
        $match = $this->match('Antonio Conselheiro');

        $this->assertSame(StreetMatch::AMBIGUOUS, $match->status);
        $this->assertEqualsCanonicalizing(
            ['Rua Antonio Conselheiro', 'Avenida Antonio Conselheiro'],
            array_column($match->suggestions, 'name')
        );
    }

    public function testSameNameWithTypeIsExact(): void
    {
        $match = $this->match('Avenida Antônio Conselheiro');

        $this->assertSame(StreetMatch::EXACT, $match->status);
        $this->assertSame('Avenida Antonio Conselheiro', $match->name);
    }

    /**
     * Regression: "Av. Campinas" (not in CNEFE) was "corrected" to Rua Barao
     * de Campinas, a different street, because only typed words were scored.
     */
    public function testPartOfAnotherStreetsNameIsNotCorrected(): void
    {
        $this->assertSame(StreetMatch::UNKNOWN, $this->match('Av. Campinas')->status);
        $this->assertNotSame(StreetMatch::CORRECTED, $this->match('Campinas')->status);
    }

    public function testUnrelatedNameIsUnknown(): void
    {
        $this->assertSame(StreetMatch::UNKNOWN, $this->match('Rua das Flores')->status);
    }

    public function testCityWithoutBase(): void
    {
        $match = $this->matcher->match('Rua Senador Vergueiro', 'Campinas', 'SP');

        $this->assertSame(StreetMatch::NO_BASE, $match->status);
    }

    public function testSuggestFindsPartiallyTypedNames(): void
    {
        $suggestions = $this->matcher->suggest('senador verg', CnefeFixture::CITY, CnefeFixture::STATE, 5);

        $this->assertSame('Rua Senador Vergueiro', $suggestions[0]['name']);
    }

    public function testSuggestIsNullWithoutBase(): void
    {
        $this->assertNull($this->matcher->suggest('senador', 'Campinas', 'SP', 5));
    }

    private function match(string $street): StreetMatch
    {
        return $this->matcher->match($street, CnefeFixture::CITY, CnefeFixture::STATE);
    }
}
