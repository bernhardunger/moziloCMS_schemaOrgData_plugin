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

    private function validate(string $value): array {
        $plugin = new \schemaOrgData();

        return callPluginMethod($plugin, 'validateUrl', [$value]);
    }

    function testHttpsUrlIsOk(): void {
        $result = $this->validate('https://example.com');

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['message']);
    }

    function testHttpUrlIsWarning(): void {
        $plugin = new \schemaOrgData();
        $expectedMessage = callPluginMethod($plugin, 'loadAdminLanguage')->getLanguageValue('warning_url_http');

        $result = $this->validate('http://example.com');

        $this->assertSame('warning', $result['status']);
        $this->assertSame($expectedMessage, $result['message']);
        $this->assertStringContainsString('HTTPS empfohlen', $result['message']);
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
