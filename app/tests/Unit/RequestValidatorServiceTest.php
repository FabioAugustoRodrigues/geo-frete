<?php

namespace app\tests\Unit;

use app\domain\exception\ValidationException;
use app\domain\service\RequestValidatorService;
use PHPUnit\Framework\TestCase;

class RequestValidatorServiceTest extends TestCase
{
    private RequestValidatorService $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidatorService();
    }

    public function testValidRequestBuildsBothAddresses(): void
    {
        [$origin, $destination] = $this->validator->validateDistanceRequest([
            'origin' => $this->address(['state' => 'sp', 'postal_code' => '13480-001']),
            'destination' => $this->address(['lat' => '-22.56', 'lng' => -47.40]),
        ]);

        $this->assertSame('SP', $origin->state);
        $this->assertSame('13480001', $origin->postalCode);
        $this->assertNull($origin->coordinate);
        $this->assertSame(-22.56, $destination->coordinate->lat);
        $this->assertSame(-47.40, $destination->coordinate->lng);
    }

    public function testReportsEveryErrorAtOnce(): void
    {
        $errors = $this->errorsFor([
            'origin' => ['street' => '', 'state' => 'XX', 'postal_code' => '123'],
        ]);

        $this->assertContains("The field 'origin.street' is required.", $errors);
        $this->assertContains("The field 'origin.number' is required.", $errors);
        $this->assertContains("The field 'origin.state' must be a valid Brazilian state code (e.g. SP).", $errors);
        $this->assertContains("The field 'origin.postal_code' must have 8 digits.", $errors);
        $this->assertContains("The field 'destination' is required.", $errors);
    }

    public function testLatAndLngMustComeTogether(): void
    {
        $errors = $this->errorsFor([
            'origin' => $this->address(['lat' => -22.5]),
            'destination' => $this->address(),
        ]);

        $this->assertSame(["The fields 'origin.lat' and 'origin.lng' must be sent together."], $errors);
    }

    public function testCoordinatesOutsideBrazilAreRejected(): void
    {
        $errors = $this->errorsFor([
            'origin' => $this->address(['lat' => 40.7, 'lng' => -74.0]),
            'destination' => $this->address(),
        ]);

        $this->assertSame(["The coordinates of 'origin' are outside Brazil."], $errors);
    }

    public function testNonScalarFieldsAreRejectedInsteadOfCrashing(): void
    {
        $errors = $this->errorsFor([
            'origin' => $this->address(['street' => ['nested']]),
            'destination' => $this->address(),
        ]);

        $this->assertSame(["The field 'origin.street' is required."], $errors);
    }

    public function testFieldsLongerThanTheLimitAreRejected(): void
    {
        $errors = $this->errorsFor([
            'origin' => $this->address(['street' => str_repeat('a', 151)]),
            'destination' => $this->address(),
        ]);

        $this->assertSame(["The field 'origin.street' must have at most 150 characters."], $errors);
    }

    private function address(array $override = []): array
    {
        return array_merge([
            'street' => 'Rua Senador Vergueiro',
            'number' => '500',
            'neighborhood' => 'Centro',
            'city' => 'Limeira',
            'state' => 'SP',
        ], $override);
    }

    /**
     * @return string[]
     */
    private function errorsFor(array $data): array
    {
        try {
            $this->validator->validateDistanceRequest($data);
        } catch (ValidationException $e) {
            return $e->getErrors();
        }

        $this->fail('Expected a ValidationException.');
    }
}
