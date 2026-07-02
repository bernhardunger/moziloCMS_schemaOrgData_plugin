<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateGeoLatitude() / validateGeoLongitude()
* (geo.latitude / geo.longitude im Erweiterungsfeld): Werte
* müssen numerisch sein und im gültigen Wertebereich liegen
* (latitude: -90..90, longitude: -180..180).
*
***************************************************************/
final class GeoCoordinatesValidatorTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function latitude(string $value): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validateGeoLatitude($value, $this->adminLang());
    }

    private function longitude(string $value): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validateGeoLongitude($value, $this->adminLang());
    }

    function testValidCoordinatesAreOk(): void {
        $this->assertSame('ok', $this->latitude('48.137')['status']);
        $this->assertSame('ok', $this->longitude('11.575')['status']);
    }

    function testLatitudeAboveRangeIsError(): void {
        $result = $this->latitude('91');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testLatitudeBelowRangeIsError(): void {
        $result = $this->latitude('-91');

        $this->assertSame('error', $result['status']);
    }

    function testLongitudeAboveRangeIsError(): void {
        $result = $this->longitude('181');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testLongitudeBelowRangeIsError(): void {
        $result = $this->longitude('-181');

        $this->assertSame('error', $result['status']);
    }

    function testNonNumericValueIsError(): void {
        $this->assertSame('error', $this->latitude('abc')['status']);
        $this->assertSame('error', $this->longitude('abc')['status']);
    }
}
