<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_ValidationResult:
*
*   - Konstruktor setzt alle Properties korrekt (inkl. Default
*     extensionData = [])
*   - Properties sind readonly (Schreibversuch wirft Error)
*
***************************************************************/
final class ValidationResultTest extends TestCase {

    function testKonstruktorSetztAllePropertiesKorrekt(): void {
        $result = new \SchemaOrgData_ValidationResult(false, ['Fehler A', 'Fehler B'], ['geo' => ['latitude' => '48.1']]);

        $this->assertFalse($result->success);
        $this->assertSame(['Fehler A', 'Fehler B'], $result->errors);
        $this->assertSame(['geo' => ['latitude' => '48.1']], $result->extensionData);
    }

    function testExtensionDataDefaultIstLeeresArray(): void {
        $result = new \SchemaOrgData_ValidationResult(true, []);

        $this->assertSame([], $result->extensionData);
    }

    function testSchreibversuchAufSuccessPropertyWirftError(): void {
        $result = new \SchemaOrgData_ValidationResult(true, []);

        $this->expectException(\Error::class);

        $result->success = false;
    }

    function testSchreibversuchAufErrorsPropertyWirftError(): void {
        $result = new \SchemaOrgData_ValidationResult(true, []);

        $this->expectException(\Error::class);

        $result->errors = ['neu'];
    }

    function testSchreibversuchAufExtensionDataPropertyWirftError(): void {
        $result = new \SchemaOrgData_ValidationResult(true, []);

        $this->expectException(\Error::class);

        $result->extensionData = ['neu' => true];
    }
}
