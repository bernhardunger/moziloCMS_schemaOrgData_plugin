<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminController:
* feldweise Vererbungsanzeige (resolveInheritableFields()) sowie
* Orchestrierung/Persistenz - renderScopeSection(), sanitizePostData(),
* sanitizeAddressData(), saveConfig(), renderAdminPage(). Die reinen
* Anzeige-Bausteine sind seit Fahrplan-Schritt 4 in
* SchemaOrgData_AdminPageRenderer ausgelagert (siehe
* tests/AdminPageRendererTest.php), die POST-Verarbeitung
* (handlePostRequest()) seit Fahrplan-Schritt 5 in
* SchemaOrgData_AdminRequestHandler (siehe
* tests/AdminRequestHandlerTest.php). Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_FormRenderer-/SchemaOrgData_Validator-/
* SchemaOrgData_OpeningHoursHelper-/SchemaOrgData_DataSplitHelper-/
* SchemaOrgData_UrlHelper-/SchemaOrgData_IdReferenceService-/
* SchemaOrgData_CollisionDetector-/SchemaOrgData_AdminPageRenderer-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures
* des Plugins, $settings ist ein isolierter InMemorySettings-Stub.
*
***************************************************************/
final class AdminControllerTest extends TestCase {

    protected function setUp(): void {
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
    }

    /***************************************************************
    *
    * FakeCatPageWithPages ist in tests/Fixtures/FakeCatPageWithPages.php
    * deklariert (nicht PSR-4-autoloadbar unter eigenem Dateinamen). Im
    * normalen Suite-Lauf ist die Klasse durch das Laden von
    * PersistenceTest.php bereits verfügbar; in
    * #[RunInSeparateProcess]-isolierten Prozessen (siehe
    * testFailedCategorySaveWithSpecialCharsRetainsPostValuesInActiveSection(),
    * testTemplateJsonLdIsPersistedOnlyForGlobalScope()) greift dort nur
    * der Composer-Autoloader, der die Klasse mangels PSR-4-Konformität
    * nicht findet - deshalb hier bei Bedarf explizit nachladen.
    *
    ***************************************************************/
    private function ensureFakeCatPageWithPagesLoaded(): void {
        if (!class_exists(FakeCatPageWithPages::class)) {
            require_once __DIR__ . '/Fixtures/FakeCatPageWithPages.php';
        }
    }

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function weekdayLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/cms_language_deDE.txt');
    }

    private function scopeResolver(): \SchemaOrgData_ScopeResolver {
        return new \SchemaOrgData_ScopeResolver();
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function formRenderer(): \SchemaOrgData_FormRenderer {
        return new \SchemaOrgData_FormRenderer();
    }

    private function validator(): \SchemaOrgData_Validator {
        return new \SchemaOrgData_Validator();
    }

    private function openingHoursHelper(): \SchemaOrgData_OpeningHoursHelper {
        return new \SchemaOrgData_OpeningHoursHelper();
    }

    private function dataSplitHelper(): \SchemaOrgData_DataSplitHelper {
        return new \SchemaOrgData_DataSplitHelper();
    }

    private function urlHelper(): \SchemaOrgData_UrlHelper {
        return new \SchemaOrgData_UrlHelper();
    }

    private function idReferenceService(): \SchemaOrgData_IdReferenceService {
        return new \SchemaOrgData_IdReferenceService();
    }

    private function collisionDetector(): \SchemaOrgData_CollisionDetector {
        return new \SchemaOrgData_CollisionDetector();
    }

    private function controller(): \SchemaOrgData_AdminController {
        return new \SchemaOrgData_AdminController();
    }

    private function adminPageRenderer(): \SchemaOrgData_AdminPageRenderer {
        return new \SchemaOrgData_AdminPageRenderer();
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness"
    * (analog PersistenceTest::validLocalBusinessData()).
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

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "FAQPage"
    * (analog PersistenceTest::validFaqPageData()).
    *
    ***************************************************************/
    private function validFaqPageData(): array {
        return [
            'type' => 'FAQPage',
            'data' => [
                'mainEntity' => [
                    ['name' => 'Wie erreiche ich euch?', 'acceptedAnswer' => ['text' => 'Per Telefon oder E-Mail.']],
                ],
            ],
            'extension' => ['FAQPage' => ''],
        ];
    }

    // -----------------------------------------------------------
    // resolveInheritableFields()
    // -----------------------------------------------------------

    function testResolveInheritableFieldsGlobalLiefertLeeresErgebnis(): void {
        $settings = new \InMemorySettings();

        $result = $this->controller()->resolveInheritableFields(
            'global', null, null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings, $this->adminPageRenderer()
        );

        $this->assertSame([], $result['data']);
        $this->assertSame([], $result['originLabel']);
    }

    function testResolveInheritableFieldsCategoryErbtVonGlobal(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH', 'email' => 'info@example.com']]);

        $result = $this->controller()->resolveInheritableFields(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings, $this->adminPageRenderer()
        );

        $this->assertSame('Global GmbH', $result['data']['name']);
        $this->assertSame('info@example.com', $result['data']['email']);
        $this->assertArrayHasKey('name', $result['originLabel']);
    }

    function testResolveInheritableFieldsPageBevorzugtKategorieVorGlobal(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH']]);
        $settings->set('config_cat_ueber-uns', ['LocalBusiness' => ['name' => 'Kategorie GmbH']]);

        $result = $this->controller()->resolveInheritableFields(
            'page', 'ueber-uns', 'kontakt', 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings, $this->adminPageRenderer()
        );

        $this->assertSame('Kategorie GmbH', $result['data']['name']);
    }

    /***************************************************************
    *
    * renderScopeSection() ruft renderExcludedCatsField() laut
    * lib/SchemaOrgData_AdminController.php ausschließlich innerhalb
    * von "if($scope === 'global')" auf - deshalb hier direkt über
    * renderScopeSection() mit scope='category' geprüft.
    *
    ***************************************************************/
    function testRenderScopeSectionOhneDebugOutputFeldFuerNichtGlobalenScopeDirekt(): void {
        $html = $this->callRenderScopeSection('category', 'ueber-uns', null, true, 'category', false, new \InMemorySettings());

        $this->assertStringNotContainsString('debug_output', $html);
    }

    // -----------------------------------------------------------
    // sanitizeAddressData()
    // -----------------------------------------------------------

    private function localBusinessAddressSchema(): array {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        return $this->schemaRepository()->resolveSchemaRef($schema['properties']['address'], $schema);
    }

    function testSanitizeAddressDataLeeresArrayOhneAusgefuellteFelder(): void {
        $result = $this->controller()->sanitizeAddressData(
            ['addressCountry' => 'DE'], $this->localBusinessAddressSchema(), $this->validator()
        );

        $this->assertSame([], $result);
    }

    function testSanitizeAddressDataUebernimmtUndBereinigtAusgefuellteFelder(): void {
        $result = $this->controller()->sanitizeAddressData(
            [
                'streetAddress' => '  <b>Musterstraße 1</b>  ',
                'addressLocality' => 'Musterstadt',
                'addressCountry' => 'DE',
            ],
            $this->localBusinessAddressSchema(),
            $this->validator()
        );

        $this->assertSame('Musterstraße 1', $result['streetAddress']);
        $this->assertSame('Musterstadt', $result['addressLocality']);
        $this->assertSame('DE', $result['addressCountry']);
    }

    // -----------------------------------------------------------
    // sanitizePostData()
    // -----------------------------------------------------------

    function testSanitizePostDataTrimmtUndEntferntHtmlTags(): void {
        $schema = ['properties' => ['name' => ['type' => 'string']]];

        $result = $this->controller()->sanitizePostData(
            ['name' => '  <b>Muster GmbH</b>  '], $schema,
            $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('Muster GmbH', $result['name']);
    }

    function testSanitizePostDataNormalisiertTelefonnummer(): void {
        $schema = ['properties' => ['telephone' => ['type' => 'string']]];

        $result = $this->controller()->sanitizePostData(
            ['telephone' => '0170 1234567'], $schema,
            $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('01701234567', $result['telephone']);
    }

    function testSanitizePostDataDelegiertPostalAddressWidgetAnSanitizeAddressData(): void {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        $formData = ['address' => [
            'streetAddress' => 'Musterstraße 12',
            'addressLocality' => 'Musterstadt',
            'addressCountry' => 'DE',
        ]];

        $result = $this->controller()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('Musterstadt', $result['address']['addressLocality']);
    }

    // -----------------------------------------------------------
    // renderScopeSection()
    // -----------------------------------------------------------

    private function callRenderScopeSection(
        string $scope, ?string $cat, ?string $page, bool $active, ?string $idPrefix, bool $saveFailed, $settings
    ): string {
        return $this->controller()->renderScopeSection(
            $scope, $cat, $page, $active, $idPrefix, $saveFailed,
            $this->adminLang(), $this->scopeResolver(), $settings, $this->schemaRepository(),
            $this->pluginSelfDir(), $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            'deDE', $this->pluginSelfDir(), $this->weekdayLang(), $this->idReferenceService(),
            $this->openingHoursHelper(), $this->validator(), $this->adminPageRenderer()
        );
    }

    function testRenderScopeSectionEnthaeltScopeUndTypeAuswahl(): void {
        $html = $this->callRenderScopeSection('global', null, null, true, 'global', false, new \InMemorySettings());

        $this->assertStringContainsString('data-scope="global"', $html);
        $this->assertStringContainsString('schemaOrgData-type-selector-row', $html);
    }

    function testRenderScopeSectionRendertGespeichertenType(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);

        $html = $this->callRenderScopeSection('global', null, null, true, 'global', false, $settings);

        $this->assertStringContainsString('data-schema-type="LocalBusiness"', $html);
        $this->assertStringContainsString('Muster GmbH', $html);
    }

    function testRenderScopeSectionInaktivDeaktiviertFormularelemente(): void {
        $html = $this->callRenderScopeSection('global', null, null, false, 'global', false, new \InMemorySettings());

        $this->assertStringContainsString('style="display:none"', $html);
        $this->assertStringContainsString('disabled="disabled"', $html);
    }

    /***************************************************************
    *
    * Regressionstest für 0.2.2-beta: schlägt das Speichern fehl
    * (z. B. wegen ungültiger url), müssen die vom Nutzer
    * eingegebenen POST-Werte erhalten bleiben - auch wenn bereits
    * eine andere, gespeicherte Konfiguration existiert.
    *
    ***************************************************************/
    function testFailedSaveRetainsPostedScalarValuesInActiveSection(): void {
        $settings = new \InMemorySettings();

        $oldData = $this->validLocalBusinessData('Alte Firma');
        $this->callSaveConfig('global', $oldData, $settings);

        $newData = $this->validLocalBusinessData('Neue Firma');
        $newData['data']['url'] = 'nicht-eine-url';
        $newData['data']['address']['addressLocality'] = 'Neustadt';
        $_POST['schemaOrgData'] = ['global' => $newData];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = $this->callRenderScopeSection('global', null, null, true, 'global', true, $settings);

        $this->assertStringContainsString('Neue Firma', $html);
        $this->assertStringContainsString('Neustadt', $html);
        $this->assertStringContainsString('nicht-eine-url', $html);
        $this->assertStringNotContainsString('Alte Firma', $html);
        $this->assertStringNotContainsString('Altstadt', $html);
    }

    /***************************************************************
    *
    * Ein ungültiges Zeitformat (z. B. "8:00" statt "08:00") in einem
    * Öffnungszeiten-Feld darf beim Re-Display nach fehlgeschlagenem
    * Save nicht zu leeren Von/Bis-Feldern führen. buildOpeningHoursArray()/
    * parseOpeningHours() würden den Eintrag sonst verlustbehaftet
    * verwerfen (siehe renderScopeSection / renderOpeningHoursWidget).
    *
    ***************************************************************/
    function testFailedSaveRetainsInvalidOpeningHoursTime(): void {
        $settings = new \InMemorySettings();

        $postData = $this->validLocalBusinessData();
        $postData['data']['url'] = 'nicht-eine-url';
        $postData['data']['openingHours']['Mo'] = ['from' => '8:00', 'to' => '18:00'];
        $_POST['schemaOrgData'] = ['global' => $postData];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = $this->callRenderScopeSection('global', null, null, true, 'global', true, $settings);

        $this->assertStringContainsString('value="8:00"', $html);
        $this->assertStringContainsString('value="18:00"', $html);
    }

    /***************************************************************
    *
    * Regressionstest für 0.4.12-beta: schlägt das Speichern fehl
    * (z. B. wegen ungültiger url), müssen from2/to2 des zweiten
    * Öffnungszeiten-Zeitraums erhalten bleiben — analog zu
    * testFailedSaveRetainsInvalidOpeningHoursTime().
    *
    ***************************************************************/
    function testFailedSaveRetainsSecondOpeningHoursRange(): void {
        $settings = new \InMemorySettings();

        $postData = $this->validLocalBusinessData();
        $postData['data']['url'] = 'nicht-eine-url';
        $postData['data']['openingHours']['Mo'] = [
            'from'  => '09:00',
            'to'    => '12:00',
            'from2' => '13:00',
            'to2'   => '17:00',
        ];
        $_POST['schemaOrgData'] = ['global' => $postData];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = $this->callRenderScopeSection('global', null, null, true, 'global', true, $settings);

        $this->assertStringContainsString('value="13:00"', $html);
        $this->assertStringContainsString('value="17:00"', $html);
    }

    // -----------------------------------------------------------
    // saveConfig()
    // -----------------------------------------------------------

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->controller()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->adminPageRenderer()
        );
    }

    function testSaveConfigSpeichertGueltigeDatenUnterScopeKey(): void {
        $settings = new \InMemorySettings();

        $result = $this->callSaveConfig('global', $this->validLocalBusinessData(), $settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Muster GmbH', $settings->get('config_global')['LocalBusiness']['name']);
    }

    /***************************************************************
    *
    * Round-Trip saveConfig() -> loadScopeConfig() mit realer
    * Datenkonvertierung (Öffnungszeiten-Widget-Struktur ->
    * schema.org-Notation). validLocalBusinessData() füllt nur Montag
    * aus (09:00-18:00, übrige Wochentage leer) statt Mo-Fr
    * durchgehend — die openingHours-Erwartung ist entsprechend auf
    * ['Mo 09:00-18:00'] angepasst.
    *
    ***************************************************************/
    function testRoundTripViaLoadScopeConfig(): void {
        $settings = new \InMemorySettings();
        $this->callSaveConfig('global', $this->validLocalBusinessData(), $settings);

        $loaded = $this->scopeResolver()->loadScopeConfig($settings, 'global');

        $this->assertSame('Muster GmbH', $loaded['LocalBusiness']['name']);
        $this->assertSame('https://www.example.com', $loaded['LocalBusiness']['url']);
        $this->assertSame('Musterstadt', $loaded['LocalBusiness']['address']['addressLocality']);
        $this->assertSame('DE', $loaded['LocalBusiness']['address']['addressCountry']);
        $this->assertSame(['Mo 09:00-18:00'], $loaded['LocalBusiness']['openingHours']);
    }

    function testSaveConfigLehntUngueltigesErweiterungsJsonAb(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['extension']['LocalBusiness'] = '{ungueltig';

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    function testSaveConfigLehntFehlendenPflichtwertAb(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['data']['name'] = '';

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    /***************************************************************
    *
    * Eigenständiger, von config_global getrennter settings-Key gemäß
    * getScopeSettingsKey()-Konvention.
    *
    ***************************************************************/
    function testCategoryConfigIsSavedUnderOwnSettingsKey(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'ueber-uns';

        $result = $this->callSaveConfig('category', $this->validLocalBusinessData('Filiale Nord'), $settings);
        $this->assertTrue($result['success']);

        $this->assertTrue($settings->keyExists('config_cat_ueber-uns'));
        $this->assertFalse($settings->keyExists('config_global'));

        $config = $settings->get('config_cat_ueber-uns');
        $this->assertSame('Filiale Nord', $config['LocalBusiness']['name']);
    }

    function testPageConfigIsSavedUnderOwnSettingsKey(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'ueber-uns';
        $_POST['schemaOrgData_page'] = 'team';

        $result = $this->callSaveConfig('page', $this->validFaqPageData(), $settings);
        $this->assertTrue($result['success']);

        $this->assertTrue($settings->keyExists('config_page_ueber-uns_team'));

        $config = $settings->get('config_page_ueber-uns_team');
        $this->assertSame('Wie erreiche ich euch?', $config['FAQPage']['mainEntity'][0]['name']);
    }

    function testExistingConfigIsOverwrittenOnResave(): void {
        $settings = new \InMemorySettings();

        $this->callSaveConfig('global', $this->validLocalBusinessData('Erste Version'), $settings);
        $result = $this->callSaveConfig('global', $this->validLocalBusinessData('Zweite Version'), $settings);

        $this->assertTrue($result['success']);

        $config = $settings->get('config_global');
        $this->assertSame('Zweite Version', $config['LocalBusiness']['name']);
    }

    /***************************************************************
    *
    * Fix 0.4.3-beta: Eine komplett leere Adresse (nur Default-Wert
    * addressCountry=DE aus der Select-Box, alle anderen Felder leer)
    * darf das Speichern nicht blockieren — die Adresse als Ganzes ist
    * optional. isAddressProvided() liefert false → validatePostalAddressData()
    * überspringt alle Pflichtfeld-Prüfungen → saveConfig() erfolgreich.
    * Zuvor (0.3.6-beta) erzwang der Pflichtfeld-Check für addressLocality
    * einen Fehler, obwohl die Adresse gar nicht ausgefüllt wurde.
    *
    ***************************************************************/
    function testGlobalSaveWithCompletelyEmptyAddressSucceeds(): void {
        $settings = new \InMemorySettings();

        $postData = $this->validLocalBusinessData();
        $postData['data']['address'] = [
            'streetAddress' => '',
            'postalCode' => '',
            'addressLocality' => '',
            'addressRegion' => '',
            'addressCountry' => 'DE',
        ];

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertTrue($result['success'],
            'Speichern mit komplett leerer Adresse (nur Default DE) muss erfolgreich sein.');
        $this->assertSame([], $result['errors']);
    }

    function testExcludedCatsIsSavedAndLoadedAsArray(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['excluded_cats'] = ['impressum', 'datenschutz'];

        $result = $this->callSaveConfig('global', $postData, $settings);
        $this->assertTrue($result['success']);

        $config = $settings->get('config_global');
        $this->assertSame(['impressum', 'datenschutz'], explode(',', $config['excluded_cats']));
    }

    function testJsonldModeIsSavedAndLoaded(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_jsonld_mode_global'] = 'override';

        $result = $this->callSaveConfig('global', $this->validLocalBusinessData(), $settings);
        $this->assertTrue($result['success']);

        $meta = $this->scopeResolver()->loadScopeMeta($settings, 'global');
        $this->assertSame('override', $meta['jsonld_mode']);
    }

    // -----------------------------------------------------------
    // renderAdminPage()
    // -----------------------------------------------------------

    private function adminRequestHandler(): \SchemaOrgData_AdminRequestHandler {
        return new \SchemaOrgData_AdminRequestHandler();
    }

    private function callRenderAdminPage($settings): string {
        return $this->controller()->renderAdminPage(
            $settings, $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            'deDE', $this->pluginSelfDir(), $this->weekdayLang(), $this->idReferenceService(),
            $this->validator(), $this->openingHoursHelper(), $this->collisionDetector(), $this->adminPageRenderer(),
            $this->adminRequestHandler()
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageEnthaeltFormularUndScriptEinbindung(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        $this->assertStringContainsString('<form method="POST"', $html);
        $this->assertStringContainsString('js/validator.js', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageZeigtSpeicherErgebnisNachPost(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData()];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        $this->assertStringContainsString('schemaOrgData-notice--success', $html);
    }

    /***************************************************************
    *
    * Regressionstest 0.3.7-beta: renderAdminPage() ermittelt
    * $selectedCat aus sanitizeScopeIdentifier($_POST['schemaOrgData_cat'])
    * und verglich diesen Wert bislang direkt mit dem UNSANIERTEN
    * Kategorie-Bezeichner aus get_CatArray(). Enthält dieser
    * Zeichen, die sanitizeScopeIdentifier() entfernt (z. B. Umlaute),
    * blieb die gerade bearbeitete Kategorie-Sektion inaktiv
    * (display:none, disabled) und renderScopeSection() füllte das
    * Formular aus $config statt aus den POST-Daten. Bei einer noch
    * nie gespeicherten Kategorie (erster Speicherversuch, der wegen
    * der seit 0.3.6-beta bedingungslosen addressLocality-Prüfung
    * fehlschlägt) ist $config leer - alle Feldwerte erschienen
    * dadurch als geleert.
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testFailedCategorySaveWithSpecialCharsRetainsPostValuesInActiveSection(): void {
        define('ADMIN_DIR_NAME', 'admin');
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('EXT_PAGE', '.txt.php');
        define('EXT_HIDDEN', '.hid.php');

        $this->ensureFakeCatPageWithPagesLoaded();

        global $CatPage;
        $rawCat = 'Über-uns';
        $CatPage = new FakeCatPageWithPages([$rawCat]);

        $postData = $this->validLocalBusinessData('Filiale Nord');
        $postData['data']['address']['streetAddress'] = 'Musterstrasse 5';
        $postData['data']['address']['addressLocality'] = '';

        $_POST['schemaOrgData'] = ['category' => $postData];
        $_POST['schemaOrgData_cat'] = $rawCat;
        $_POST['schemaOrgData_page'] = '';

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        unset($CatPage);

        $this->assertStringContainsString('Filiale Nord', $html);
        $this->assertStringContainsString('Musterstrasse 5', $html);
    }

    /***************************************************************
    *
    * Regressionstest 0.4.8-beta: Ein im Layout-Template gefundener
    * JSON-LD-Block ist layoutweit und damit kein seiten-/kategorie-
    * spezifisches Signal. renderAdminPage() darf das existing_jsonld-
    * Flag deshalb nur für 'global' setzen, nicht für die gerade
    * aktive Kategorie (siehe README.md).
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testTemplateJsonLdIsPersistedOnlyForGlobalScope(): void {
        define('ADMIN_DIR_NAME', 'admin');
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('EXT_PAGE', '.txt.php');
        define('EXT_HIDDEN', '.hid.php');

        $layout = 'schemaOrgData_test_' . uniqid();
        $layoutDir = \BASE_DIR . \LAYOUT_DIR_NAME . '/' . $layout;
        mkdir($layoutDir, 0777, true);
        file_put_contents(
            $layoutDir . '/template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);
        $GLOBALS['TEMPLATE_FILE'] = '';

        $this->ensureFakeCatPageWithPagesLoaded();

        global $CatPage;
        $cat = 'unterseite';
        $CatPage = new FakeCatPageWithPages([$cat]);

        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = $cat;
        $_POST['schemaOrgData_page'] = '';

        $this->callRenderAdminPage($settings);

        unset($CatPage);
        unlink($layoutDir . '/template.html');
        rmdir($layoutDir);

        $globalMeta = $this->scopeResolver()->loadScopeMeta($settings, 'global');
        $categoryMeta = $this->scopeResolver()->loadScopeMeta($settings, 'category', $cat);

        $this->assertTrue($globalMeta['existing_jsonld']);
        $this->assertFalse($categoryMeta['existing_jsonld']);
    }
}
