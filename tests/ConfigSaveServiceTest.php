<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_ConfigSaveService
* (Fahrplan-Schritt 6, siehe doc/adr_ziel_architektur.md):
* feldweise Vererbungsanzeige (resolveInheritableFields()),
* POST-Sanitizing (sanitizePostData(), sanitizeAddressData())
* sowie Validieren/Speichern (saveConfig()) - vorher auf
* SchemaOrgData_AdminController (siehe tests/AdminControllerTest.php).
* Echte, zustandslose Language-/SchemaOrgData_ScopeResolver-/
* SchemaOrgData_SchemaRepository-/SchemaOrgData_Validator-/
* SchemaOrgData_OpeningHoursHelper-/SchemaOrgData_AdminPageRenderer-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures
* des Plugins, $settings ist ein isolierter InMemorySettings-Stub.
*
***************************************************************/
final class ConfigSaveServiceTest extends TestCase {

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

        $result = $this->configSaveService()->resolveInheritableFields(
            'global', null, null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings, $this->adminPageRenderer()
        );

        $this->assertSame([], $result['data']);
        $this->assertSame([], $result['originLabel']);
    }

    function testResolveInheritableFieldsCategoryErbtVonGlobal(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH', 'email' => 'info@example.com']]);

        $result = $this->configSaveService()->resolveInheritableFields(
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

        $result = $this->configSaveService()->resolveInheritableFields(
            'page', 'ueber-uns', 'kontakt', 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings, $this->adminPageRenderer()
        );

        $this->assertSame('Kategorie GmbH', $result['data']['name']);
    }

    // -----------------------------------------------------------
    // sanitizeAddressData()
    // -----------------------------------------------------------

    private function localBusinessAddressSchema(): array {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        return $this->schemaRepository()->resolveSchemaRef($schema['properties']['address'], $schema);
    }

    function testSanitizeAddressDataLeeresArrayOhneAusgefuellteFelder(): void {
        $result = $this->configSaveService()->sanitizeAddressData(
            ['addressCountry' => 'DE'], $this->localBusinessAddressSchema(), $this->validator()
        );

        $this->assertSame([], $result);
    }

    function testSanitizeAddressDataUebernimmtUndBereinigtAusgefuellteFelder(): void {
        $result = $this->configSaveService()->sanitizeAddressData(
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

        $result = $this->configSaveService()->sanitizePostData(
            ['name' => '  <b>Muster GmbH</b>  '], $schema,
            $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('Muster GmbH', $result['name']);
    }

    function testSanitizePostDataNormalisiertTelefonnummer(): void {
        $schema = ['properties' => ['telephone' => ['type' => 'string']]];

        $result = $this->configSaveService()->sanitizePostData(
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

        $result = $this->configSaveService()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('Musterstadt', $result['address']['addressLocality']);
    }

    // -----------------------------------------------------------
    // validateExtensionField()
    // -----------------------------------------------------------

    function testValidateExtensionFieldLeererRohwertLiefertErfolgOhneDaten(): void {
        $result = $this->configSaveService()->validateExtensionField('', $this->adminLang(), $this->validator());

        $this->assertTrue($result->success);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $result->extensionData);
    }

    function testValidateExtensionFieldGueltigesJsonLiefertDekodierteDaten(): void {
        $result = $this->configSaveService()->validateExtensionField('{"foo":"bar"}', $this->adminLang(), $this->validator());

        $this->assertTrue($result->success);
        $this->assertSame([], $result->errors);
        $this->assertSame(['foo' => 'bar'], $result->extensionData);
    }

    function testValidateExtensionFieldUngueltigesJsonLiefertFehler(): void {
        $result = $this->configSaveService()->validateExtensionField('{ungueltig', $this->adminLang(), $this->validator());

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
        $this->assertSame([], $result->extensionData);
    }

    /***************************************************************
    *
    * validateExtensionGeo() (SchemaOrgData_Validator) prüft geo.latitude
    * gegen den Wertebereich -90..90 (validateGeoLatitude()) - ein Wert
    * außerhalb davon löst einen Fehler aus, obwohl das JSON selbst
    * syntaktisch gültig ist.
    *
    ***************************************************************/
    function testValidateExtensionFieldGeoFehlerLiefertFehler(): void {
        $result = $this->configSaveService()->validateExtensionField('{"geo":{"latitude":"200"}}', $this->adminLang(), $this->validator());

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    // -----------------------------------------------------------
    // saveConfig()
    // -----------------------------------------------------------

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->configSaveService()->saveConfig(
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

    function testSaveConfigMergtGueltigesErweiterungsJson(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['extension']['LocalBusiness'] = '{"foo":"bar"}';

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertTrue($result['success']);
        $this->assertSame('bar', $settings->get('config_global')['LocalBusiness']['foo']);
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
}
