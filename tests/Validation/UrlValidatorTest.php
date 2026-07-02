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

    function testEmptyValueIsOptionalAndNotValidated(): void {
        $result = $this->validate('');

        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }
}
