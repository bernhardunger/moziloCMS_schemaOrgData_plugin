<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateAgainstSchema() - serverseitige Prüfung von
* Pflichtfeldern sowie bekannten/unbekannten Properties anhand
* eines JSON-Schemas aus schemas/.
*
***************************************************************/
final class SchemaValidatorTest extends TestCase {

    private function validate(array $data): array {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        return callPluginMethod($plugin, 'validateAgainstSchema', [$data, $schema]);
    }

    function testMissingRequiredFieldIsReported(): void {
        $result = $this->validate(['name' => 'Steuerkanzlei Hader']);

        $this->assertContains('url', $result['errors']);
    }

    function testCompleteRequiredFieldsProduceNoErrors(): void {
        $result = $this->validate([
            'name' => 'Steuerkanzlei Hader',
            'url' => 'https://steuerkanzlei-hader.de',
        ]);

        $this->assertSame([], $result['errors']);
    }

    function testKnownPropertiesProduceNoWarnings(): void {
        $result = $this->validate([
            'name' => 'Steuerkanzlei Hader',
            'url' => 'https://steuerkanzlei-hader.de',
            'telephone' => '+49 89 12345678',
        ]);

        $this->assertSame([], $result['warnings']);
    }

    function testUnknownPropertyProducesWarning(): void {
        $result = $this->validate([
            'name' => 'Steuerkanzlei Hader',
            'url' => 'https://steuerkanzlei-hader.de',
            'unbekanntesFeld' => 'wert',
        ]);

        $this->assertContains('unbekanntesFeld', $result['warnings']);
    }
}
