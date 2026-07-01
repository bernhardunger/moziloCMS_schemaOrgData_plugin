<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_LanguageService::resolvePluginLanguage():
*
*   - 'deDE' / 'de'  -> deDE
*   - 'enEN' / 'en'  -> enEN
*   - unbekannter Code -> DEFAULT_LANGUAGE (deDE)
*   - null             -> DEFAULT_LANGUAGE (deDE)
*
***************************************************************/
final class LanguageServiceTest extends TestCase {

    private function service(): \SchemaOrgData_LanguageService {
        return new \SchemaOrgData_LanguageService(
            ['de' => 'deDE', 'en' => 'enEN'],
            'deDE'
        );
    }

    function testVollstaendigerDedeCodeWirdErkannt(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('deDE'));
    }

    function testZweiZeichenDePrefixWirdErkannt(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('de'));
    }

    function testVollstaendigerEnEnCodeWirdErkannt(): void {
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('enEN'));
    }

    function testZweiZeichenEnPrefixWirdErkannt(): void {
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('en'));
    }

    function testUnbekannterCodeFaelltAufDefault(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('fr'));
    }

    function testNullFaelltAufDefault(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage(null));
    }

    function testGrossSchreibungWirdIgnoriert(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('DE'));
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('EN'));
    }
}
