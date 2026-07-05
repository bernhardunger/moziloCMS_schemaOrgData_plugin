<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_Validator.
* Echte, zustandslose Language-/SchemaOrgData_SchemaRepository-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-
* Fixtures des Plugins.
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

    function testValidatePostalCodeOkMitFuehrenderNull(): void {
        $result = (new \SchemaOrgData_Validator())->validatePostalCode('01067', 'DE', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidatePostalCodeErrorZuLang(): void {
        $result = (new \SchemaOrgData_Validator())->validatePostalCode('123456', 'DE', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidatePostalCodeErrorNichtNumerisch(): void {
        $validator = new \SchemaOrgData_Validator();
        $lang = $this->adminLang();

        $this->assertSame('error', $validator->validatePostalCode('A1234', 'DE', $lang)['status']);
        $this->assertSame('error', $validator->validatePostalCode('8033A', 'DE', $lang)['status']);
    }

    function testValidatePostalCodeWirdAuchFuerUsNichtGeprueft(): void {
        // testValidatePostalCodeWirdNurFuerDePrueft() (Bestand) deckt AT ab -
        // hier zusätzlich ein zweites Nicht-DE-Land (US) mit anderer PLZ-Länge.
        $result = (new \SchemaOrgData_Validator())->validatePostalCode('123456', 'US', $this->adminLang());
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

    function testValidateTelephoneOkBeiDoppelterNullPraefix(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('0049 89 123456', 'DE', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateTelephoneNormalisierungEntferntBindestriche(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('+49 89 123-456', 'DE', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateTelephoneNormalisierungEntferntKlammern(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('+49 (89) 123 456', 'DE', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateTelephoneErrorOhnePlusOderDoppelteNull(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('49 89 123456', 'DE', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateTelephoneErrorZuKurzeNummer(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('+49 1', 'DE', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateTelephoneErrorZuLangeNummer(): void {
        // 16 Ziffern nach dem "+" -> mehr als die zulässigen 1 + 14 Ziffern
        $result = (new \SchemaOrgData_Validator())->validateTelephone('+4123456789012345', 'DE', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateTelephoneNullBeiLeeremWert(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('', 'DE', $this->adminLang());
        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }

    function testValidateTelephoneOkFuerAnderesLand(): void {
        $result = (new \SchemaOrgData_Validator())->validateTelephone('+33 1 23 45 67 89', 'FR', $this->adminLang());
        $this->assertSame('ok', $result['status']);
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

    function testValidateUrlNullBeiLeeremWert(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateUrl('', $this->adminLang());

        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }

    function testValidateUrlWarningMeldungstextBeiHttp(): void {
        $validator = new \SchemaOrgData_Validator();
        $lang = $this->adminLang();
        $expectedMessage = $lang->getLanguageValue('warning_url_http');

        $result = $validator->validateUrl('http://example.com', $lang);

        $this->assertSame('warning', $result['status']);
        $this->assertSame($expectedMessage, $result['message']);
        $this->assertStringContainsString('HTTPS empfohlen', $result['message']);
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

    function testValidateEmailNullBeiLeeremWert(): void {
        $validator = new \SchemaOrgData_Validator();
        $result = $validator->validateEmail('', $this->adminLang());

        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
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

    // (nur validateOpeningHoursTime() - parseOpeningHours()/buildOpeningHoursArray()
    // aus derselben Datei gehören zu SchemaOrgData_OpeningHoursHelper, bereits
    // über OpeningHoursHelperTest abgedeckt)
    function testValidateOpeningHoursTimeErrorBeiNurEinemGesetztenFeld(): void {
        $validator = new \SchemaOrgData_Validator();
        $lang = $this->adminLang();

        // from gesetzt, to leer -> Format-Fehler
        $this->assertSame('error', $validator->validateOpeningHoursTime('13:00', '', $lang)['status']);
        // from leer, to gesetzt -> Format-Fehler
        $this->assertSame('error', $validator->validateOpeningHoursTime('', '17:00', $lang)['status']);
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

    function testValidateGeoLatitudeErrorUnterschreitetWertebereich(): void {
        $result = (new \SchemaOrgData_Validator())->validateGeoLatitude('-91', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateGeoLongitudeErrorUnterschreitetWertebereich(): void {
        $result = (new \SchemaOrgData_Validator())->validateGeoLongitude('-181', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateGeoKoordinatenErrorBeiNichtNumerischemWert(): void {
        $validator = new \SchemaOrgData_Validator();
        $lang = $this->adminLang();

        $this->assertSame('error', $validator->validateGeoLatitude('abc', $lang)['status']);
        $this->assertSame('error', $validator->validateGeoLongitude('abc', $lang)['status']);
    }

    function testValidateGeoKoordinatenOkFuerBeideRichtungenKombiniert(): void {
        // Bestand deckt je Richtung nur einen Fall ab (Latitude: Fehler,
        // Longitude: gültig) - hier beide Richtungen gemeinsam im gültigen Bereich.
        $validator = new \SchemaOrgData_Validator();
        $lang = $this->adminLang();

        $this->assertSame('ok', $validator->validateGeoLatitude('48.137', $lang)['status']);
        $this->assertSame('ok', $validator->validateGeoLongitude('11.575', $lang)['status']);
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

    function testValidateFormDataUeberspringtIdReferencePflichtfeld(): void {
        // recipient (DonateAction) ist ui:required, ui:widget id_reference -
        // wird erst zur Build-Zeit emittiert und hat per Design keinen
        // POST-Wert (siehe renderField()). Darf keinen Pflichtfeld-Fehler
        // erzeugen, auch wenn formData den Schlüssel gar nicht enthält.
        $validator = new \SchemaOrgData_Validator();
        $donateActionSchema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'DonateAction');

        $errors = $validator->validateFormData(
            [], $donateActionSchema, [], $this->adminLang(), $this->schemaRepository()
        );

        $this->assertSame([], $errors);
    }

    // -----------------------------------------------------------
    // validateIso8601Date()
    // -----------------------------------------------------------

    function testValidateIso8601DateNullBeiLeeremWert(): void {
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('', $this->adminLang());
        $this->assertNull($result['status']);
    }

    function testValidateIso8601DateOkBeiReinemDatum(): void {
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('2026-09-15', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateIso8601DateOkBeiDatumZeitUndOffset(): void {
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('2026-09-15T19:00:00+02:00', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateIso8601DateOkBeiDatumZeitUndZ(): void {
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('2026-09-15T19:00:00Z', $this->adminLang());
        $this->assertSame('ok', $result['status']);
    }

    function testValidateIso8601DateErrorBeiUngueltigemKalendertag(): void {
        // 31. Februar existiert nicht - Regex passt, checkdate() schlägt fehl.
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('2026-02-31', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateIso8601DateErrorBeiDeutschemFormat(): void {
        // TT.MM.YYYY wird bewusst nicht unterstützt (siehe README.md).
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('15.09.2026', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    function testValidateIso8601DateErrorBeiUngueltigerUhrzeit(): void {
        $result = (new \SchemaOrgData_Validator())->validateIso8601Date('2026-09-15T25:00:00Z', $this->adminLang());
        $this->assertSame('error', $result['status']);
    }

    // -----------------------------------------------------------
    // validateFormData() - Event: endDate vor startDate
    // -----------------------------------------------------------

    private function eventSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Event');
    }

    function testValidateFormDataMeldetEndDateVorStartDate(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'endDate' => '2026-09-15T18:00:00+02:00',
        ];
        $errors = $validator->validateFormData($formData, $this->eventSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertContains($this->adminLang()->getLanguageValue('error_date_range_invalid'), $errors);
    }

    function testValidateFormDataOkBeiEndDateNachStartDate(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'endDate' => '2026-09-15T22:00:00+02:00',
        ];
        $errors = $validator->validateFormData($formData, $this->eventSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertSame([], $errors);
    }

    function testValidateFormDataOkBeiGleichemZeitpunktUnterschiedlicherOffsetNotation(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = [
            'name' => 'Sommerfest',
            // Gleicher Zeitpunkt (17:00 UTC), aber startDate mit "+02:00" und
            // endDate mit "Z" notiert - ein rein lexikalischer Vergleich hätte
            // "17:00:00Z" < "19:00:00+02:00" fälschlich als Bereichsfehler gemeldet.
            'startDate' => '2026-09-15T19:00:00+02:00',
            'endDate' => '2026-09-15T17:00:00Z',
        ];
        $errors = $validator->validateFormData($formData, $this->eventSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertSame([], $errors);
    }

    function testValidateFormDataMeldetPflichtfeldStartDate(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = ['name' => 'Sommerfest'];
        $errors = $validator->validateFormData($formData, $this->eventSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertNotEmpty($errors);
    }

    function testValidateFormDataPlaceWidgetDelegiertAnValidatePostalAddressData(): void {
        $validator = new \SchemaOrgData_Validator();
        $formData = [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'location' => ['name' => 'Stadtpark', 'address' => ['addressCountry' => 'DE']],
        ];
        // Ort ausgefüllt ohne addressLocality -> Pflichtfeld-Fehler über die
        // verschachtelte Adresse des place-Widgets.
        $formData['location']['address']['streetAddress'] = 'Am Stadtpark 1';
        $errors = $validator->validateFormData($formData, $this->eventSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertNotEmpty($errors, 'fehlender addressLocality-Pflichtwert muss über das place-Widget gemeldet werden');
    }
}
