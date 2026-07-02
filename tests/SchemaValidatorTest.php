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

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validate(array $data): array {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        return (new \SchemaOrgData_Validator())->validateAgainstSchema($data, $schema, new \SchemaOrgData_SchemaRepository());
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

        $errors = (new \SchemaOrgData_Validator())->validateFormData(
            [], $schema, $inheritable, $this->adminLang(), new \SchemaOrgData_SchemaRepository()
        );

        $this->assertSame([], $errors,
            'Leeres Pflichtfeld mit geerbtem Wert darf keinen Fehler erzeugen.');
    }

    function testEmptyRequiredFieldWithoutInheritedValueProducesError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        // name leer, kein geerbter Wert → Fehler erwartet
        $errors = (new \SchemaOrgData_Validator())->validateFormData(
            ['url' => 'https://example.com'], $schema, [], $this->adminLang(), new \SchemaOrgData_SchemaRepository()
        );

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

        $errors = (new \SchemaOrgData_Validator())->validatePostalAddressData(
            ['addressCountry' => 'DE'], $addressSchema, $inheritableAddress, $this->adminLang()
        );

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
        $errors = (new \SchemaOrgData_Validator())->validatePostalAddressData(
            ['streetAddress' => 'Musterstraße 1', 'addressCountry' => 'DE'], $addressSchema, [], $this->adminLang()
        );

        $this->assertNotEmpty($errors,
            'Teilausgefüllte Adresse ohne addressLocality und ohne geerbten Wert muss einen Fehler erzeugen.');
    }

    function testCompletelyEmptyAddressWithNameUrlProducesNoError(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        // name + url gefüllt, address nur mit Default addressCountry=DE (kein
        // Adressfeld manuell ausgefüllt) → validateFormData() darf keinen Fehler liefern.
        $errors = (new \SchemaOrgData_Validator())->validateFormData(
            [
                'name' => 'Muster GmbH',
                'url'  => 'https://example.com',
                'address' => ['addressCountry' => 'DE'],
            ],
            $schema, [], $this->adminLang(), new \SchemaOrgData_SchemaRepository()
        );

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
        $errors = (new \SchemaOrgData_Validator())->validatePostalAddressData(
            ['streetAddress' => 'Musterstraße 1', 'addressCountry' => 'DE'], $addressSchema, [], $this->adminLang()
        );

        $this->assertNotEmpty($errors,
            'Teilausgefüllte Adresse ohne addressLocality muss einen Fehler erzeugen.');
    }
}
