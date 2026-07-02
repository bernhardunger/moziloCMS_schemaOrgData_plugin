<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateEmail() - Prüfung der E-Mail-Adresse via
* FILTER_VALIDATE_EMAIL. Ein leeres Feld ist optional und wird
* nicht als Fehler gewertet.
*
***************************************************************/
final class EmailValidatorTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validate(string $value): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validateEmail($value, $this->adminLang());
    }

    function testValidEmailIsOk(): void {
        $result = $this->validate('info@example.com');

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['message']);
    }

    function testEmailWithoutAtSignIsError(): void {
        $result = $this->validate('keine-email');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testEmptyValueIsOptionalAndNotValidated(): void {
        $result = $this->validate('');

        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }
}
