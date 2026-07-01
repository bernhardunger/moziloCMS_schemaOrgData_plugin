<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateAgainstSchema() - serverseitige Prüfung von
* Pflichtfeldern sowie bekannten/unbekannten Properties anhand
* eines JSON-Schemas aus schemas/.
*
* Enthält außerdem Tests für validateFormData() und
* validatePostalAddressData() mit geerbten Werten ($inheritable),
* um sicherzustellen, dass ein leeres Pflichtfeld keinen Fehler
* produziert, wenn der Wert von einer übergeordneten Ebene
* geerbt wird.
*
***************************************************************/
final class SchemaValidatorTest extends TestCase {

    private function validate(array $data): array {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        return callPluginMethod($plugin, 'validateAgainstSchema', [$data, $schema]);
    }

    function testMissingRequiredFieldIsReported(): void {
        $result = $this->validate(['name' => 'Muster GmbH']);

        $this->assertContains('url', $result['errors']);
    }

    function testCompleteRequiredFieldsProduceNoErrors(): void {
        $result = $this->validate([
            'name' => 'Muster GmbH',
            'url' => 'https://example.com',
        ]);

        $this->assertSame([], $result['errors']);
    }

    function testKnownPropertiesProduceNoWarnings(): void {
        $result = $this->validate([
            'name' => 'Muster GmbH',
            'url' => 'https://example.com',
            'telephone' => '+49 89 12345678',
        ]);

        $this->assertSame([], $result['warnings']);
    }

    function testUnknownPropertyProducesWarning(): void {
        $result = $this->validate([
            'name' => 'Muster GmbH',
            'url' => 'https://example.com',
            'unbekanntesFeld' => 'wert',
        ]);

        $this->assertContains('unbekanntesFeld', $result['warnings']);
    }

    // --- Tests für validateFormData() mit $inheritable ---

    function testEmptyRequiredFieldWithInheritedValueProducesNoError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        // name, url und address (addressLocality, addressCountry) sind Pflichtfelder;
        // alle leer, aber durch geerbte Werte abgedeckt → kein Fehler erwartet.
        // address-Pflichtfelder (addressLocality, addressCountry) werden über
        // inheritable['data']['address'] abgedeckt, analog zur feldweisen
        // Vererbung in resolveInheritableFields().
        $inheritable = [
            'data' => [
                'name' => 'Muster GmbH Global',
                'url'  => 'https://example.com',
                'address' => [
                    'addressLocality' => 'Musterstadt',
                    'addressCountry'  => 'DE',
                ],
            ],
            'originLabel' => [
                'name'    => 'Global',
                'url'     => 'Global',
                'address' => 'Global',
            ],
        ];

        $errors = callPluginMethod($plugin, 'validateFormData', [[], $schema, $inheritable]);

        $this->assertSame([], $errors,
            'Leeres Pflichtfeld mit geerbtem Wert darf keinen Fehler erzeugen.');
    }

    function testEmptyRequiredFieldWithoutInheritedValueProducesError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        // name leer, kein geerbter Wert → Fehler erwartet
        $errors = callPluginMethod($plugin, 'validateFormData', [
            ['url' => 'https://example.com'],
            $schema,
            [],
        ]);

        $this->assertNotEmpty($errors,
            'Leeres Pflichtfeld ohne geerbten Wert muss einen Fehler erzeugen.');
    }

    function testEmptyRequiredAddressSubFieldWithInheritedValueProducesNoError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');
        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef(
            $schema['properties']['address'],
            $schema
        );

        // addressLocality ist als ui:required markiert; leer, aber geerbt → kein Fehler
        $inheritableAddress = ['addressLocality' => 'Musterstadt', 'addressCountry' => 'DE'];

        $errors = callPluginMethod($plugin, 'validatePostalAddressData', [
            ['addressCountry' => 'DE'],
            $addressSchema,
            $inheritableAddress,
        ]);

        $this->assertSame([], $errors,
            'Leeres Adress-Pflichtfeld mit geerbtem Wert darf keinen Fehler erzeugen.');
    }

    function testEmptyRequiredAddressSubFieldWithoutInheritedValueProducesError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');
        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef(
            $schema['properties']['address'],
            $schema
        );

        // streetAddress ausgefüllt (Adresse gilt als "provided"), addressLocality
        // leer, kein geerbter Wert → Fehler für das required Ort-Feld erwartet.
        $errors = callPluginMethod($plugin, 'validatePostalAddressData', [
            ['streetAddress' => 'Musterstraße 1', 'addressCountry' => 'DE'],
            $addressSchema,
            [],
        ]);

        $this->assertNotEmpty($errors,
            'Teilausgefüllte Adresse ohne addressLocality und ohne geerbten Wert muss einen Fehler erzeugen.');
    }

    function testCompletelyEmptyAddressWithNameUrlProducesNoError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        // name + url gefüllt, address nur mit Default addressCountry=DE (kein
        // Adressfeld manuell ausgefüllt) → validateFormData() darf keinen Fehler liefern.
        $errors = callPluginMethod($plugin, 'validateFormData', [
            [
                'name' => 'Muster GmbH',
                'url'  => 'https://example.com',
                'address' => ['addressCountry' => 'DE'],
            ],
            $schema,
            [],
        ]);

        $this->assertSame([], $errors,
            'Komplett leere Adresse (nur Default addressCountry=DE) darf keinen Fehler erzeugen.');
    }

    function testAddressPartialFillMissingLocalityProducesError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');
        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef(
            $schema['properties']['address'],
            $schema
        );

        // streetAddress gesetzt → Adresse gilt als "provided";
        // addressLocality fehlt, kein geerbter Wert → Fehler erwartet (Regressionsschutz).
        $errors = callPluginMethod($plugin, 'validatePostalAddressData', [
            ['streetAddress' => 'Musterstraße 1', 'addressCountry' => 'DE'],
            $addressSchema,
            [],
        ]);

        $this->assertNotEmpty($errors,
            'Teilausgefüllte Adresse ohne addressLocality muss einen Fehler erzeugen.');
    }
}
