<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminRequestHandler:
* POST-Verarbeitung des Admin-Formulars (handlePostRequest()). Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_Validator-/SchemaOrgData_OpeningHoursHelper-/
* SchemaOrgData_AdminPageRenderer-/SchemaOrgData_ConfigSaveService-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures
* des Plugins, $settings ist ein isolierter InMemorySettings-Stub.
*
***************************************************************/
final class AdminRequestHandlerTest extends TestCase {

    protected function setUp(): void {
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
    }

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function scopeResolver(): \SchemaOrgData_ScopeResolver {
        return new \SchemaOrgData_ScopeResolver();
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function validator(): \SchemaOrgData_Validator {
        return new \SchemaOrgData_Validator();
    }

    private function openingHoursHelper(): \SchemaOrgData_OpeningHoursHelper {
        return new \SchemaOrgData_OpeningHoursHelper();
    }

    private function adminPageRenderer(): \SchemaOrgData_AdminPageRenderer {
        return new \SchemaOrgData_AdminPageRenderer();
    }

    private function configSaveService(): \SchemaOrgData_ConfigSaveService {
        return new \SchemaOrgData_ConfigSaveService();
    }

    private function adminRequestHandler(): \SchemaOrgData_AdminRequestHandler {
        return new \SchemaOrgData_AdminRequestHandler();
    }

    private function importService(): \SchemaOrgData_ImportService {
        return new \SchemaOrgData_ImportService();
    }

    private function dataSplitHelper(): \SchemaOrgData_DataSplitHelper {
        return new \SchemaOrgData_DataSplitHelper();
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness"
    * (analog AdminControllerTest::validLocalBusinessData()).
    *
    ***************************************************************/
    private function validLocalBusinessData(string $name = 'Muster GmbH'): array {
        return [
            'type' => 'LocalBusiness',
            'data' => [
                'name' => $name,
                'url' => 'https://www.example.com',
                'address' => [
                    'streetAddress' => 'Musterstraße 12',
                    'postalCode' => '12345',
                    'addressLocality' => 'Musterstadt',
                    'addressRegion' => '',
                    'addressCountry' => 'DE',
                ],
                'openingHours' => [
                    'Mo' => ['from' => '09:00', 'to' => '18:00'],
                    'Tu' => ['from' => '', 'to' => ''],
                    'We' => ['from' => '', 'to' => ''],
                    'Th' => ['from' => '', 'to' => ''],
                    'Fr' => ['from' => '', 'to' => ''],
                    'Sa' => ['from' => '', 'to' => ''],
                    'Su' => ['from' => '', 'to' => ''],
                ],
            ],
            'extension' => ['LocalBusiness' => ''],
        ];
    }

    // -----------------------------------------------------------
    // handlePostRequest()
    // -----------------------------------------------------------

    private function callHandlePostRequest($settings): ?array {
        return $this->adminRequestHandler()->handlePostRequest(
            $settings, $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(), $this->adminPageRenderer(),
            $this->configSaveService(), $this->importService(), $this->dataSplitHelper(),
            new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_OrgRelationsService()
        );
    }

    function testHandlePostRequestOhnePostDatenLiefertNull(): void {
        $_POST = [];

        $this->assertNull($this->callHandlePostRequest(new \InMemorySettings()));
    }

    function testHandlePostRequestSpeichertGlobalenScope(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData()];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($settings->keyExists('config_global'));
    }

    function testHandlePostRequestLoeschtBeiDeleteFlag(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);
        $_POST['schemaOrgData'] = ['global' => []];
        $_POST['schemaOrgData_delete_global'] = '1';
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    // -----------------------------------------------------------
    // handlePostRequest() - Import-Dispatch
    // -----------------------------------------------------------

    private function validLocalBusinessJsonLd(): string {
        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'url' => 'https://www.example.com',
            'hasMap' => 'https://maps.example.org',
        ]);
    }

    function testImportMitGueltigemJsonLdFuelltPostZurueckUndSpeichertNichts(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = $this->validLocalBusinessJsonLd();

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['import']);
        $this->assertFalse($settings->keyExists('config_global'));

        $this->assertSame('LocalBusiness', $_POST['schemaOrgData']['global']['type']);
        $this->assertSame('Muster GmbH', $_POST['schemaOrgData']['global']['data']['name']);
        $this->assertSame('https://www.example.com', $_POST['schemaOrgData']['global']['data']['url']);
        $this->assertStringContainsString('hasMap', $_POST['schemaOrgData']['global']['extension']['LocalBusiness']);

        // Rohe Textarea-Eingabe wird bei Erfolg gelöscht (ADR, Entscheidung (g))
        $this->assertArrayNotHasKey('schemaOrgData_import_global', $_POST);
    }

    function testImportMitUngueltigemJsonLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = '{ungültiges json';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['import']);
        $this->assertSame($this->adminLang()->getLanguageValue('error_json_invalid'), $result['errors'][0]);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    function testImportMitFuerScopeUnzulaessigemTypeLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => 'Sommerfest',
        ]);

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['import']);
        $this->assertSame(
            $this->adminLang()->getLanguageValue('error_invalid_schema_type', 'Event'),
            $result['errors'][0]
        );
    }

    function testImportAktionHatVorrangVorMitgesendetenFormulardaten(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = $this->validLocalBusinessJsonLd();
        // gleichzeitig mitgesendete (gültige) Formulardaten der aktiven Sektion -
        // dürfen bei gesetzter Import-Aktion NICHT gespeichert werden (ADR (b))
        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData('Andere Firma')];

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['import']);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    // -----------------------------------------------------------
    // handlePostRequest() - Import-Dispatch: openingHours-Konvertierung
    // -----------------------------------------------------------

    function testImportMitKomprimiertenOpeningHoursKonvertiertZuProTagStruktur(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'url' => 'https://www.example.com',
            'openingHours' => ['Mo-Th 08:00-12:00', 'Mo-Th 13:00-17:00', 'Fr 08:00-12:00'],
        ]);

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);

        $openingHours = $_POST['schemaOrgData']['global']['data']['openingHours'];

        foreach(['Mo', 'Tu', 'We', 'Th'] as $day) {
            $this->assertSame('08:00', $openingHours[$day]['from']);
            $this->assertSame('12:00', $openingHours[$day]['to']);
            $this->assertSame('13:00', $openingHours[$day]['from2']);
            $this->assertSame('17:00', $openingHours[$day]['to2']);
        }

        $this->assertSame('08:00', $openingHours['Fr']['from']);
        $this->assertSame('12:00', $openingHours['Fr']['to']);
        $this->assertSame('', $openingHours['Fr']['from2']);
        $this->assertSame('', $openingHours['Fr']['to2']);

        foreach(['Sa', 'Su'] as $day) {
            $this->assertSame('', $openingHours[$day]['from']);
            $this->assertSame('', $openingHours[$day]['to']);
        }
    }

    function testImportOhneOpeningHoursBleibtUnveraendert(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = $this->validLocalBusinessJsonLd();

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('openingHours', $_POST['schemaOrgData']['global']['data']);
    }

    function testImportMitBereitsProTagArtigemOpeningHoursWirdNichtDoppeltKonvertiert(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        // Praxisfremder Randfall (echtes JSON-LD liefert nie Pro-Tag-Objekte je Eintrag),
        // dient nur als Absicherung des isPerDayOpeningHoursValue()-Guards.
        $_POST['schemaOrgData_import_global'] = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'url' => 'https://www.example.com',
            'openingHours' => [
                'Mo' => ['from' => '09:00', 'to' => '18:00'],
            ],
        ]);

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['Mo' => ['from' => '09:00', 'to' => '18:00']],
            $_POST['schemaOrgData']['global']['data']['openingHours']
        );
    }
}
