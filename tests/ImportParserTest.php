<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für importJsonLd() - Zerlegung eines importierten
* JSON-LD-Blocks in bekannte Formularfelder und Erweiterungsfeld.
*
***************************************************************/
final class ImportParserTest extends TestCase {

    private function import(string $json): array {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        return callPluginMethod($plugin, 'importJsonLd', [$json, $schema]);
    }

    function testKnownPropertiesEndUpInFormData(): void {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Steuerkanzlei Hader',
            'url' => 'https://steuerkanzlei-hader.de',
        ]);

        $result = $this->import($json);

        $this->assertTrue($result['success']);
        $this->assertSame('LocalBusiness', $result['type']);
        $this->assertSame('Steuerkanzlei Hader', $result['formData']['name']);
        $this->assertSame('https://steuerkanzlei-hader.de', $result['formData']['url']);
        $this->assertArrayNotHasKey('@context', $result['formData']);
        $this->assertArrayNotHasKey('@type', $result['formData']);
    }

    function testUnknownPropertiesEndUpInExtensionData(): void {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Steuerkanzlei Hader',
            'url' => 'https://steuerkanzlei-hader.de',
            'hasMap' => 'https://maps.example.com/steuerkanzlei-hader',
        ]);

        $result = $this->import($json);

        $this->assertSame(
            ['hasMap' => 'https://maps.example.com/steuerkanzlei-hader'],
            $result['extensionData']
        );
        $this->assertArrayNotHasKey('hasMap', $result['formData']);
    }

    function testInvalidJsonIsReportedAsError(): void {
        // importJsonLd() wirft keine Exception, sondern meldet ungültiges
        // JSON über success=false und error - hier wird auf das Ergebnis
        // statt auf eine Exception geprüft.
        $result = $this->import('{ungültiges json');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertNull($result['type']);
        $this->assertSame([], $result['formData']);
        $this->assertSame([], $result['extensionData']);
    }

    function testEmptyBlockIsHandledCorrectly(): void {
        $result = $this->import('{}');

        $this->assertTrue($result['success']);
        $this->assertNull($result['type']);
        $this->assertSame([], $result['formData']);
        $this->assertSame([], $result['extensionData']);
    }
}
