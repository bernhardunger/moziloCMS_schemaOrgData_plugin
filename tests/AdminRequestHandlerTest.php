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

    private function personSuggestionService(): \SchemaOrgData_PersonSuggestionService {
        return new \SchemaOrgData_PersonSuggestionService();
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
            new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_OrgRelationsService(),
            $this->personSuggestionService()
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

    /***************************************************************
    *
    * Manipulierter POST: skalarer Scope-Wert statt Array. Der
    * is_array()-Guard des Scope-Loops muss greifen, sonst läuft
    * saveConfig(array $postData) in einen TypeError.
    *
    ***************************************************************/
    function testHandlePostRequestUeberspringtSkalarenScopeWertOhneTypeError(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData'] = ['category' => 'x'];
        $_POST['schemaOrgData_cat'] = 'Testkategorie';
        $_POST['schemaOrgData_page'] = '';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse($settings->keyExists($this->scopeResolver()->getScopeSettingsKey('category', 'Testkategorie')));
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

    /***************************************************************
    *
    * Seedet die erkannten Blöcke einer Geltungsebene in der
    * Scope-Meta (existing_jsonld_blocks, siehe
    * SchemaOrgData_ScopeResolver::loadScopeMeta()) - Quelle des
    * serverseitigen Imports (handleImportAction() liest NICHT aus
    * $_POST).
    *
    * @param string[] $blocks
    *
    ***************************************************************/
    private function seedScopeBlocks($settings, string $scope, array $blocks, ?string $cat = null, ?string $page = null): void {
        $this->scopeResolver()->saveScopeMeta($settings, $scope, [
            'existing_jsonld' => true,
            'existing_jsonld_content' => implode("\n\n", $blocks),
            'existing_jsonld_blocks' => $blocks,
        ], $cat, $page);
    }

    function testImportMitGueltigemJsonLdFuelltPostZurueckUndSpeichertNichts(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [$this->validLocalBusinessJsonLd()]);
        $_POST['schemaOrgData_import_action'] = 'global';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['import']);
        $this->assertArrayNotHasKey('LocalBusiness', $this->scopeResolver()->loadScopeConfig($settings, 'global'));

        $this->assertSame('LocalBusiness', $_POST['schemaOrgData']['global']['type']);
        $this->assertSame('Muster GmbH', $_POST['schemaOrgData']['global']['data']['name']);
        $this->assertSame('https://www.example.com', $_POST['schemaOrgData']['global']['data']['url']);
        $this->assertStringContainsString('hasMap', $_POST['schemaOrgData']['global']['extension']['LocalBusiness']);
    }

    function testImportOhneIndexImportiertBlockNull(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [$this->validLocalBusinessJsonLd()]);
        $_POST['schemaOrgData_import_action'] = 'global';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame('Muster GmbH', $_POST['schemaOrgData']['global']['data']['name']);
    }

    function testImportMitExplizitemIndexImportiertPassendenBlock(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [
            $this->validLocalBusinessJsonLd(),
            json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Beispiel-Website', 'url' => 'https://www.example.com']),
        ]);
        $_POST['schemaOrgData_import_action'] = 'global:1';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame('WebSite', $_POST['schemaOrgData']['global']['type']);
        $this->assertSame('Beispiel-Website', $_POST['schemaOrgData']['global']['data']['name']);
    }

    function testImportMitNichtVorhandenemIndexLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [$this->validLocalBusinessJsonLd()]);
        $_POST['schemaOrgData_import_action'] = 'global:5';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['import']);
        $this->assertSame($this->adminLang()->getLanguageValue('error_import_block_not_found'), $result['errors'][0]);
        $this->assertArrayNotHasKey('LocalBusiness', $this->scopeResolver()->loadScopeConfig($settings, 'global'));
    }

    function testImportMitUngueltigemJsonLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', ['{ungültiges json']);
        $_POST['schemaOrgData_import_action'] = 'global';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['import']);
        $this->assertSame($this->adminLang()->getLanguageValue('error_detected_block_invalid'), $result['errors'][0]);
        $this->assertArrayNotHasKey('LocalBusiness', $this->scopeResolver()->loadScopeConfig($settings, 'global'));
    }

    function testImportMitFuerScopeUnzulaessigemTypeLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';
        $this->seedScopeBlocks($settings, 'global', [json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => 'Sommerfest',
        ])]);
        $_POST['schemaOrgData_import_action'] = 'global';

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
        $this->seedScopeBlocks($settings, 'global', [$this->validLocalBusinessJsonLd()]);
        $_POST['schemaOrgData_import_action'] = 'global';
        // gleichzeitig mitgesendete (gültige) Formulardaten der aktiven Sektion -
        // dürfen bei gesetzter Import-Aktion NICHT gespeichert werden (ADR (b))
        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData('Andere Firma')];

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['import']);
        $this->assertArrayNotHasKey('LocalBusiness', $this->scopeResolver()->loadScopeConfig($settings, 'global'));
    }

    /***************************************************************
    *
    * page-Scope: schemaOrgData_cat/schemaOrgData_page werden für die
    * loadScopeMeta()-Quelle der Blöcke herangezogen, analog
    * resolveScopeIdentifiers().
    *
    ***************************************************************/
    function testImportImPageScopeNutztCatUndPageAusPost(): void {
        $settings = new \InMemorySettings();
        $articleJsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'Ein Testartikel',
        ]);
        $this->seedScopeBlocks($settings, 'page', [$articleJsonLd], 'blog', 'kontakt');
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'kontakt';
        $_POST['schemaOrgData_import_action'] = 'page';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame('Ein Testartikel', $_POST['schemaOrgData']['page']['data']['headline']);
    }

    // -----------------------------------------------------------
    // handlePostRequest() - Import-Dispatch: openingHours-Konvertierung
    // -----------------------------------------------------------

    function testImportMitKomprimiertenOpeningHoursKonvertiertZuProTagStruktur(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'url' => 'https://www.example.com',
            'openingHours' => ['Mo-Th 08:00-12:00', 'Mo-Th 13:00-17:00', 'Fr 08:00-12:00'],
        ])]);
        $_POST['schemaOrgData_import_action'] = 'global';

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

    // -----------------------------------------------------------
    // handlePostRequest() - Übernahme-Vorschlag (schemaOrgData_accept_person_suggestion)
    // -----------------------------------------------------------

    function testAcceptPersonSuggestionErstelltPersonUndSpeichertRelation(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => [
                'name' => 'Muster GmbH', 'url' => 'https://www.example.com',
                'employee' => ['@type' => 'Person', 'name' => 'Julia Weber'],
            ],
        ]);
        $_POST['schemaOrgData_accept_person_suggestion'] = 'employee';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);

        $registry = $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY);
        $this->assertArrayHasKey('julia-weber', $registry);

        $config = $settings->get('config_global');
        $this->assertSame([['person' => 'julia-weber', 'role' => 'employee']], $config['org_relations']);
        $this->assertArrayNotHasKey('employee', $config['LocalBusiness']);
    }

    /***************************************************************
    *
    * Regressionstest analog testImportAktionHatVorrangVorMitgesendetenFormulardaten():
    * ein gleichzeitig mitgesendetes schemaOrgData-Formulardatenarray der
    * aktiven Sektion darf bei gesetztem schemaOrgData_accept_person_suggestion
    * NICHT über saveConfig() verarbeitet werden.
    *
    ***************************************************************/
    function testAcceptPersonSuggestionHatVorrangVorMitgesendetenFormulardaten(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => [
                'name' => 'Muster GmbH', 'url' => 'https://www.example.com',
                'employee' => ['@type' => 'Person', 'name' => 'Julia Weber'],
            ],
        ]);
        $_POST['schemaOrgData_accept_person_suggestion'] = 'employee';
        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData('Andere Firma')];

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $config = $settings->get('config_global');
        $this->assertSame('Muster GmbH', $config['LocalBusiness']['name'],
            'Die mitgesendeten Formulardaten ("Andere Firma") dürfen nicht über saveConfig() gespeichert werden');
    }

    /***************************************************************
    *
    * Post-Import-Redisplay (siehe SchemaOrgData_PersonSuggestionService::
    * renderSuggestionNotice(), $fromImport): das Marker-Hidden-Feld
    * signalisiert, dass "employee" noch nicht in der persistierten
    * Konfiguration vorliegt, sondern nur im mitgesendeten POST - die
    * aktive Sektion wird implizit gespeichert, bevor acceptSuggestion()
    * aufgerufen wird.
    *
    ***************************************************************/
    function testAcceptPersonSuggestionMitImportMarkerSpeichertZuerstDieAktiveSektion(): void {
        $settings = new \InMemorySettings();
        $data = $this->validLocalBusinessData();
        $data['extension']['LocalBusiness'] = json_encode(['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']]);
        $_POST['schemaOrgData'] = ['global' => $data];
        $_POST['schemaOrgData_person_suggestion_from_import'] = '1';
        $_POST['schemaOrgData_accept_person_suggestion'] = 'employee';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);

        $registry = $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY);
        $this->assertArrayHasKey('julia-weber', $registry);

        $config = $settings->get('config_global');
        $this->assertSame('Muster GmbH', $config['LocalBusiness']['name']);
        $this->assertSame([['person' => 'julia-weber', 'role' => 'employee']], $config['org_relations']);
        $this->assertArrayNotHasKey('employee', $config['LocalBusiness']);
    }

    /***************************************************************
    *
    * Schlägt das implizite Speichern fehl (hier: Pflichtfeld "name"
    * fehlt), werden die Fehler durchgereicht - weder config_global
    * noch die Personen-Registry werden verändert (kein Teil-Update).
    *
    ***************************************************************/
    function testAcceptPersonSuggestionMitImportMarkerUndFehlgeschlagenemSpeichernReichtFehlerDurch(): void {
        $settings = new \InMemorySettings();
        $data = $this->validLocalBusinessData();
        $data['data']['name'] = '';
        $data['extension']['LocalBusiness'] = json_encode(['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']]);
        $_POST['schemaOrgData'] = ['global' => $data];
        $_POST['schemaOrgData_person_suggestion_from_import'] = '1';
        $_POST['schemaOrgData_accept_person_suggestion'] = 'employee';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
        $this->assertFalse($settings->keyExists('config_global'));
        $this->assertFalse($settings->keyExists(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY));
    }

    function testAcceptPersonSuggestionOhneAktivenOrganisationsTypeLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['WebSite' => ['name' => 'Muster GmbH', 'url' => 'https://www.example.com']]);
        $_POST['schemaOrgData_accept_person_suggestion'] = 'employee';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
    }

    function testAcceptPersonSuggestionMitUngueltigerPropertyLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH', 'url' => 'https://www.example.com']]);
        $_POST['schemaOrgData_accept_person_suggestion'] = 'ceo';

        $result = $this->callHandlePostRequest($settings);

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
    }

    function testImportOhneOpeningHoursBleibtUnveraendert(): void {
        $settings = new \InMemorySettings();
        $this->seedScopeBlocks($settings, 'global', [$this->validLocalBusinessJsonLd()]);
        $_POST['schemaOrgData_import_action'] = 'global';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('openingHours', $_POST['schemaOrgData']['global']['data']);
    }

    function testImportMitBereitsProTagArtigemOpeningHoursWirdNichtDoppeltKonvertiert(): void {
        $settings = new \InMemorySettings();
        // Praxisfremder Randfall (echtes JSON-LD liefert nie Pro-Tag-Objekte je Eintrag),
        // dient nur als Absicherung des isPerDayOpeningHoursValue()-Guards.
        $this->seedScopeBlocks($settings, 'global', [json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'url' => 'https://www.example.com',
            'openingHours' => [
                'Mo' => ['from' => '09:00', 'to' => '18:00'],
            ],
        ])]);
        $_POST['schemaOrgData_import_action'] = 'global';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['Mo' => ['from' => '09:00', 'to' => '18:00']],
            $_POST['schemaOrgData']['global']['data']['openingHours']
        );
    }
}
