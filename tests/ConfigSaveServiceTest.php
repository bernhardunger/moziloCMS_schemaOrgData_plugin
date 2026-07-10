<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_ConfigSaveService:
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
    * Minimale, gültige Formulardaten für einen Type der
    * LocalBusiness-Familie ohne Pflicht-Adresse (AccountingService,
    * MedicalBusiness, ...).
    *
    ***************************************************************/
    private function validFamilyTypeData(string $type, string $name = 'Muster GmbH'): array {
        return [
            'type' => $type,
            'data' => [
                'name' => $name,
                'url' => 'https://www.example.com',
            ],
            'extension' => [$type => ''],
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

    function testSanitizePostDataNormalisiertDeutschesDatumsformatAufIso(): void {
        $schema = ['properties' => ['startDate' => ['type' => 'string', 'format' => 'date-time']]];

        $result = $this->configSaveService()->sanitizePostData(
            ['startDate' => '15.09.2026'], $schema,
            $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('2026-09-15', $result['startDate']);
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

    function testSanitizePostDataDelegiertPlaceWidgetAnSanitizeAddressData(): void {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Event');
        $formData = ['location' => [
            'name' => '  <b>Stadtpark</b>  ',
            'address' => [
                'addressLocality' => 'Musterstadt',
                'addressCountry' => 'DE',
            ],
        ]];

        $result = $this->configSaveService()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame('Stadtpark', $result['location']['name']);
        $this->assertSame('Musterstadt', $result['location']['address']['addressLocality']);
    }

    function testSanitizePostDataPlaceWidgetOhneAngabenLiefertKeinLocationProperty(): void {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Event');
        $formData = ['location' => ['name' => '', 'address' => ['addressCountry' => 'DE']]];

        $result = $this->configSaveService()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertArrayNotHasKey('location', $result);
    }

    function testSanitizePostDataSpeichertGeoAlsFloatBeiVollstaendigemPaar(): void {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        $formData = ['geo' => ['latitude' => '48.12567', 'longitude' => '11.64278']];

        $result = $this->configSaveService()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertSame(48.12567, $result['geo']['latitude']);
        $this->assertSame(11.64278, $result['geo']['longitude']);
    }

    function testSanitizePostDataGeoOhneAngabenLiefertKeinGeoProperty(): void {
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        $formData = ['geo' => ['latitude' => '', 'longitude' => '']];

        $result = $this->configSaveService()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );

        $this->assertArrayNotHasKey('geo', $result);
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

    function testSaveConfigSpeichertVollstaendigesGeoPaar(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['data']['geo'] = ['latitude' => '48.12567', 'longitude' => '11.64278'];

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertTrue($result['success']);
        $this->assertSame(48.12567, $settings->get('config_global')['LocalBusiness']['geo']['latitude']);
        $this->assertSame(11.64278, $settings->get('config_global')['LocalBusiness']['geo']['longitude']);
    }

    function testSaveConfigLehntUnvollstaendigesGeoPaarAb(): void {
        $settings = new \InMemorySettings();
        $postData = $this->validLocalBusinessData();
        $postData['data']['geo'] = ['latitude' => '48.12567', 'longitude' => ''];

        $result = $this->callSaveConfig('global', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertContains($this->adminLang()->getLanguageValue('error_geo_incomplete'), $result['errors']);
        $this->assertFalse($settings->keyExists('config_global'));
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
    * Article.datePublished erhielt format: date-time (Befund aus dem
    * manuellen AccountingService-Use-Case, siehe README.md) - der
    * generische date-time-Mechanismus (normalizeEventDateInput() in
    * sanitizePostData(), validateEventDateInput() in validateFormData())
    * greift dadurch ohne Article-spezifischen Code.
    *
    ***************************************************************/
    function testSaveConfigRoundTripArticleDatePublishedDeutschesFormatSpeichertIso(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'blog';

        $postData = [
            'type' => 'Article',
            'data' => ['headline' => 'Testartikel', 'datePublished' => '15.03.2026'],
            'extension' => ['Article' => ''],
        ];

        $result = $this->callSaveConfig('category', $postData, $settings);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));
        $this->assertSame('2026-03-15', $settings->get('config_cat_blog')['Article']['datePublished']);
    }

    function testSaveConfigLehntUngueltigesArticleDatePublishedAb(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'blog';

        $postData = [
            'type' => 'Article',
            'data' => ['headline' => 'Testartikel', 'datePublished' => 'nicht-valide'],
            'extension' => ['Article' => ''],
        ];

        $result = $this->callSaveConfig('category', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($settings->keyExists('config_cat_blog'));
    }

    /***************************************************************
    *
    * JobPosting.employmentType wurde im Nachzieh-Schritt auf volle
    * schema.org-URIs umgestellt (siehe README.md) - die neu ergänzte
    * generische Enum-Prüfung in validateFormData() muss einen noch
    * gespeicherten alten rohen Wert ("FULL_TIME") beim Speichern ablehnen,
    * statt ihn stillschweigend zu übernehmen.
    *
    ***************************************************************/
    function testSaveConfigSpeichertGueltigeVolleUriBeiEmploymentType(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'jobs';
        $_POST['schemaOrgData_page'] = 'entwickler';

        $postData = [
            'type' => 'JobPosting',
            'data' => [
                'title' => 'Entwickler',
                'description' => 'Stellenbeschreibung',
                'datePosted' => '15.03.2026',
                'employmentType' => 'https://schema.org/FULL_TIME',
                'hiringOrganization' => ['_mode' => 'literal', 'name' => 'Muster GmbH'],
                'jobLocation' => ['address' => ['addressLocality' => 'Musterstadt', 'addressCountry' => 'DE']],
            ],
            'extension' => ['JobPosting' => ''],
        ];

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));
        $this->assertSame('https://schema.org/FULL_TIME', $settings->get('config_page_jobs_entwickler')['JobPosting']['employmentType']);
    }

    /***************************************************************
    *
    * Regressionstest RC-Bug (2026-07-09): JobPosting.jobLocation ist
    * als gesamtes place-Widget "ui:required" ("Arbeitsort *") - ein
    * komplett leeres jobLocation (kein name, keine Adresse außer dem
    * Land-Default) musste bislang trotzdem erfolgreich speichern.
    *
    ***************************************************************/
    function testSaveConfigLehntKomplettLeeresJobLocationAb(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'jobs';
        $_POST['schemaOrgData_page'] = 'entwickler';

        $postData = [
            'type' => 'JobPosting',
            'data' => [
                'title' => 'Entwickler',
                'description' => 'Stellenbeschreibung',
                'datePosted' => '15.03.2026',
                'hiringOrganization' => ['_mode' => 'literal', 'name' => 'Muster GmbH'],
                'jobLocation' => ['address' => ['addressCountry' => 'DE']],
            ],
            'extension' => ['JobPosting' => ''],
        ];

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($settings->keyExists('config_page_jobs_entwickler'));
    }

    function testSaveConfigLehntAltenRohenEnumWertBeiEmploymentTypeAb(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'jobs';
        $_POST['schemaOrgData_page'] = 'entwickler';

        $postData = [
            'type' => 'JobPosting',
            'data' => ['title' => 'Entwickler', 'description' => 'Stellenbeschreibung', 'employmentType' => 'FULL_TIME'],
            'extension' => ['JobPosting' => ''],
        ];

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($settings->keyExists('config_page_jobs_entwickler'));
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
    // saveConfig() - LocalBusiness-Familie
    // -----------------------------------------------------------

    function testSaveConfigLehntAbweichendenFamilienTypeBeiKategorieAb(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['AccountingService' => ['name' => 'Kanzlei Muster', 'url' => 'https://www.example.com']]);
        $_POST['schemaOrgData_cat'] = 'ueber-uns';

        $result = $this->callSaveConfig('category', $this->validFamilyTypeData('MedicalBusiness'), $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($settings->keyExists('config_cat_ueber-uns'));
    }

    function testSaveConfigErlaubtIdentischenFamilienTypeBeiKategorie(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['AccountingService' => ['name' => 'Kanzlei Muster', 'url' => 'https://www.example.com']]);
        $_POST['schemaOrgData_cat'] = 'ueber-uns';

        $result = $this->callSaveConfig('category', $this->validFamilyTypeData('AccountingService', 'Filiale Nord'), $settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Filiale Nord', $settings->get('config_cat_ueber-uns')['AccountingService']['name']);
    }

    function testSaveConfigErlaubtContentTypeBeiKategorieUnabhaengigVonGlobalerFamilie(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['AccountingService' => ['name' => 'Kanzlei Muster', 'url' => 'https://www.example.com']]);
        $_POST['schemaOrgData_cat'] = 'ueber-uns';

        $result = $this->callSaveConfig('category', [
            'type' => 'Article',
            'data' => ['headline' => 'Neuigkeiten', 'articleBody' => 'Text', 'datePublished' => '07.07.2026'],
            'extension' => ['Article' => ''],
        ], $settings);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));
    }

    function testSaveConfigGlobalIgnoriertFamilienCheckUnabhaengigVomGewaehltenType(): void {
        $settings = new \InMemorySettings();

        $result = $this->callSaveConfig('global', $this->validFamilyTypeData('MedicalBusiness'), $settings);

        $this->assertTrue($result['success']);
        $this->assertSame('Muster GmbH', $settings->get('config_global')['MedicalBusiness']['name']);
    }
}
