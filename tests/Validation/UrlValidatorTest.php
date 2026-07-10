<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateUrl() - dreistufiges Feedback (✅/⚠️/❌):
*
*   - "https://..."  -> ok (grün)
*   - "http://..."   -> warning (gelb), HTTPS wird empfohlen
*   - ungültige URL  -> error (rot)
*   - leeres Feld    -> null (Feld ist optional)
*
***************************************************************/
final class UrlValidatorTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validate(string $value): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validateUrl($value, $this->adminLang());
    }

    function testHttpsUrlIsOk(): void {
        $result = $this->validate('https://example.com');

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['message']);
    }

    function testInvalidUrlIsError(): void {
        $result = $this->validate('keine-url');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testHttpUrlIsWarning(): void {
        $result = $this->validate('http://example.com');

        $this->assertSame('warning', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testUnbekanntesSchemaMitGueltigerUriSyntaxIstError(): void {
        // FILTER_VALIDATE_URL prüft nur allgemeine URI-Syntax und würde
        // ein Tippfehler-Schema wie "htto://" fälschlich als gültig
        // durchlassen - siehe SchemaOrgData_Validator::validateUrl().
        $result = $this->validate('htto://www.dddd.de');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testAehnlichesAberFalschesSchemaIstError(): void {
        $result = $this->validate('htxxxs://www.example.com/pfad');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testEmptyValueIsOptionalAndNotValidated(): void {
        $result = $this->validate('');

        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }
}
