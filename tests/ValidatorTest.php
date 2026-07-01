<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_Validator
* (Refactoring-Schritt 9, siehe doc/adr_komponenten_refactoring.md).
* Echte, zustandslose Language-/SchemaOrgData_SchemaRepository-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-
* Fixtures des Plugins. Die Facade-Delegator-Verträge sind bereits
* durch die bestehenden Tests (FormRendererTest, SchemaValidatorTest,
* Validation/*) abgedeckt - hier werden nur die Komponenten-Methoden
* selbst ohne Fassaden-Overhead geprüft.
*
***************************************************************/
final class ValidatorTest extends TestCase {

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function localBusinessSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
    }

    // -----------------------------------------------------------
    // validateAgainstSchema()
    // -----------------------------------------------------------

    function testValidateAgainstSchemaMeldetFehlendePflichtfelder(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateAgainstSchema([], $this->localBusinessSchema(), $this->schemaRepository());

        $this->assertContains('name', $result['errors']);
        $this->assertContains('url', $result['errors']);
    }

    function testValidateAgainstSchemaMeldetUnbekanntePropertyAlsWarnung(): void {
        $validator = new \SchemaOrgData_Validator();
        $data = ['name' => 'Muster GmbH', 'url' => 'https://example.com', 'unknownProp' => 'x'];
        $result = $validator->validateAgainstSchema($data, $this->localBusinessSchema(), $this->schemaRepository());

        $this->assertSame([], $result['errors']);
        $this->assertContains('unknownProp', $result['warnings']);
    }

    function testValidateAgainstSchemaOhneSchemaLiefertLeereListen(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateAgainstSchema(['foo' => 'bar'], null, $this->schemaRepository());

        $this->assertSame(['errors' => [], 'warnings' => []], $result);
    }

    // -----------------------------------------------------------
    // validatePostalCode()
    // -----------------------------------------------------------

    function testValidatePostalCodeOkFuerFuenfstelligeDeutschePlz(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validatePostalCode('12345', 'DE', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    function testValidatePostalCodeErrorBeiUngueltigemFormat(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validatePostalCode('AB123', 'DE', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    function testValidatePostalCodeWirdNurFuerDePrueft(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validatePostalCode('AB123', 'AT', $this->adminLang());

        $this->assertNull($result['status']);
    }

    // -----------------------------------------------------------
    // validateTelephone()
    // -----------------------------------------------------------

    function testValidateTelephoneOkBeiE164Format(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateTelephone('+49 89 12345678', 'DE', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    function testValidateTelephoneErrorBeiUngueltigemFormat(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateTelephone('keine-nummer', 'DE', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    // -----------------------------------------------------------
    // validateUrl()
    // -----------------------------------------------------------

    function testValidateUrlOkBeiHttps(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateUrl('https://example.com', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    function testValidateUrlWarningBeiHttp(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateUrl('http://example.com', $this->adminLang());

        $this->assertSame('warning', $result['status']);
    }

    function testValidateUrlErrorBeiUngueltigerUrl(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateUrl('keine-url', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    // -----------------------------------------------------------
    // validateEmail()
    // -----------------------------------------------------------

    function testValidateEmailOkBeiGueltigerAdresse(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateEmail('info@example.com', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    function testValidateEmailErrorBeiUngueltigerAdresse(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateEmail('keine-email', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    // -----------------------------------------------------------
    // validateOpeningHoursTime()
    // -----------------------------------------------------------

    function testValidateOpeningHoursTimeOkBeiVonVorBis(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateOpeningHoursTime('09:00', '18:00', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    function testValidateOpeningHoursTimeErrorBeiVonNachBis(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateOpeningHoursTime('18:00', '09:00', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    function testValidateOpeningHoursTimeNullBeiBeidenLeer(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateOpeningHoursTime('', '', $this->adminLang());

        $this->assertNull($result['status']);
    }

    // -----------------------------------------------------------
    // validateGeoCoordinate() / validateGeoLatitude() / validateGeoLongitude()
    // -----------------------------------------------------------

    function testValidateGeoLatitudeErrorAusserhalbWertebereich(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateGeoLatitude('90.1', $this->adminLang());

        $this->assertSame('error', $result['status']);
    }

    function testValidateGeoLongitudeOkInnerhalbWertebereich(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateGeoLongitude('-179.9', $this->adminLang());

        $this->assertSame('ok', $result['status']);
    }

    // -----------------------------------------------------------
    // validateExtensionGeo()
    // -----------------------------------------------------------

    function testValidateExtensionGeoMeldetFehlerhafteLatitude(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validateExtensionGeo(['geo' => ['latitude' => '999']], $this->adminLang());

        $this->assertNotEmpty($errors);
    }

    function testValidateExtensionGeoOhneGeoIstLeer(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validateExtensionGeo(['foo' => 'bar'], $this->adminLang());

        $this->assertSame([], $errors);
    }

    // -----------------------------------------------------------
    // isAddressProvided() / validatePostalAddressData()
    // -----------------------------------------------------------

    private function postalAddressFieldSchema(): array {
        $schema = $this->localBusinessSchema();
        return $this->schemaRepository()->resolveSchemaRef($schema['properties']['address'], $schema);
    }

    function testIsAddressProvidedFalseWennNurDefaultGefuellt(): void {
        $validator = new \SchemaOrgData_Validator();
        $fieldSchema = $this->postalAddressFieldSchema();
        $result = $validator->isAddressProvided(['addressCountry' => 'DE'], $fieldSchema['properties']);

        $this->assertFalse($result);
    }

    function testIsAddressProvidedTrueBeiAusgefuelltemFeld(): void {
        $validator = new \SchemaOrgData_Validator();
        $fieldSchema = $this->postalAddressFieldSchema();
        $result = $validator->isAddressProvided(
            ['addressCountry' => 'DE', 'addressLocality' => 'Musterstadt'], $fieldSchema['properties']
        );

        $this->assertTrue($result);
    }

    function testValidatePostalAddressDataLeerWennNichtAusgefuellt(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validatePostalAddressData(
            ['addressCountry' => 'DE'], $this->postalAddressFieldSchema(), [], $this->adminLang()
        );

        $this->assertSame([], $errors);
    }

    function testValidatePostalAddressDataMeldetFehlendenOrt(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validatePostalAddressData(
            ['addressCountry' => 'DE', 'streetAddress' => 'Musterstraße 1'],
            $this->postalAddressFieldSchema(), [], $this->adminLang()
        );

        $this->assertNotEmpty($errors, 'addressLocality ist required und fehlt hier');
    }

    function testValidatePostalAddressDataPflichtfeldErfuelltDurchVererbung(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validatePostalAddressData(
            ['addressCountry' => 'DE', 'streetAddress' => 'Musterstraße 1'],
            $this->postalAddressFieldSchema(),
            ['addressLocality' => 'Geerbte Stadt'],
            $this->adminLang()
        );

        $this->assertSame([], $errors, 'geerbter Wert deckt das Pflichtfeld addressLocality ab');
    }

    // -----------------------------------------------------------
    // hasFaqEntry()
    // -----------------------------------------------------------

    function testHasFaqEntryTrueBeiFrageUndAntwort(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->hasFaqEntry([
            ['name' => 'Frage?', 'acceptedAnswer' => ['text' => 'Antwort.']],
        ]);

        $this->assertTrue($result);
    }

    function testHasFaqEntryFalseWennAntwortFehlt(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->hasFaqEntry([
            ['name' => 'Frage?', 'acceptedAnswer' => ['text' => '']],
        ]);

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------
    // validateFormData()
    // -----------------------------------------------------------

    function testValidateFormDataMeldetFehlendePflichtfelder(): void {
        $validator = new \SchemaOrgData_Validator();
        $errors = $validator->validateFormData(
            [], $this->localBusinessSchema(), [], $this->adminLang(), $this->schemaRepository()
        );

        $this->assertNotEmpty($errors);
    }

    function testValidateFormDataOkBeiVollstaendigenPflichtfeldern(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = ['name' => 'Muster GmbH', 'url' => 'https://example.com'];
        $errors = $validator->validateFormData(
            $formData, $this->localBusinessSchema(), [], $this->adminLang(), $this->schemaRepository()
        );

        $this->assertSame([], $errors);
    }

    function testValidateFormDataPflichtfeldErfuelltDurchVererbung(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = ['name' => 'Muster GmbH'];
        $inheritable = ['data' => ['url' => 'https://example.com']];
        $errors = $validator->validateFormData(
            $formData, $this->localBusinessSchema(), $inheritable, $this->adminLang(), $this->schemaRepository()
        );

        $this->assertSame([], $errors, 'geerbter Wert deckt das Pflichtfeld url ab');
    }
}
