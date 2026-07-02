<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validatePostalCode() - Prüfung der Postleitzahl.
*
* Die Prüfung greift ausschließlich für addressCountry = "DE"
* (siehe README.md, Abschnitt "Formularvalidierung"): die
* deutsche PLZ besteht aus genau 5 Ziffern. Für alle anderen
* Länder wird die Prüfung übersprungen (status === null).
*
***************************************************************/
final class PostalCodeValidatorTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validate(string $value, string $countryCode): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validatePostalCode($value, $countryCode, $this->adminLang());
    }

    function testValidGermanPostalCodeIsOk(): void {
        $result = $this->validate('80331', 'DE');

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['message']);
    }

    function testValidGermanPostalCodeWithLeadingZeroIsOk(): void {
        $result = $this->validate('01067', 'DE');

        $this->assertSame('ok', $result['status']);
    }

    function testTooShortPostalCodeIsError(): void {
        $result = $this->validate('1234', 'DE');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testTooLongPostalCodeIsError(): void {
        $result = $this->validate('123456', 'DE');

        $this->assertSame('error', $result['status']);
    }

    function testNonNumericPostalCodeIsError(): void {
        $resultLeading = $this->validate('A1234', 'DE');
        $resultTrailing = $this->validate('8033A', 'DE');

        $this->assertSame('error', $resultLeading['status']);
        $this->assertSame('error', $resultTrailing['status']);
    }

    function testValidationIsSkippedForOtherCountries(): void {
        $resultAt = $this->validate('1234', 'AT');
        $resultUs = $this->validate('123456', 'US');

        $this->assertNull($resultAt['status']);
        $this->assertNull($resultUs['status']);
    }
}
