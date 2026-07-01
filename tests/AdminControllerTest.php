<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminController:
* Refactoring-Schritt 12a, "Ebene A" (reine Anzeige-Bausteine)
* sowie Schritt 12b, "Ebene B" (Orchestrierung/Persistenz -
* renderScopeSection(), sanitizePostData(), sanitizeAddressData(),
* saveConfig(), handlePostRequest(), renderAdminPage()), siehe
* doc/adr_komponenten_refactoring.md. Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_FormRenderer-/SchemaOrgData_Validator-/
* SchemaOrgData_OpeningHoursHelper-/SchemaOrgData_DataSplitHelper-/
* SchemaOrgData_UrlHelper-/SchemaOrgData_IdReferenceService-/
* SchemaOrgData_CollisionDetector-Instanzen, $pluginSelfDir zeigt auf
* die realen Schema-/Sprach-Fixtures des Plugins, $settings ist ein
* isolierter InMemorySettings-Stub. Die Facade-Delegator-Verträge sind
* bereits durch bestehende Tests (PersistenceTest, JsonLdOutputTest
* o. ä.) indirekt abgedeckt - hier wird nur die Komponente selbst ohne
* Fassaden-Overhead geprüft.
*
***************************************************************/
final class AdminControllerTest extends TestCase {

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

    // -----------------------------------------------------------
    // getAdminCss()
    // -----------------------------------------------------------

    function testGetAdminCssEnthaeltAdminSelektor(): void {
        $css = $this->controller()->getAdminCss();

        $this->assertStringContainsString('.schemaOrgData-admin', $css);
        $this->assertStringContainsString('.schemaOrgData-required', $css);
    }

    // -----------------------------------------------------------
    // renderInfoBlock()
    // -----------------------------------------------------------

    function testRenderInfoBlockGlobalEnthaeltTemplateHinweis(): void {
        $html = $this->controller()->renderInfoBlock('global', $this->adminLang());

        $this->assertStringContainsString('schemaOrgData-info', $html);
    }

    function testRenderInfoBlockUngueltigerScopeLiefertLeerenString(): void {
        $this->assertSame('', $this->controller()->renderInfoBlock('nicht-existent', $this->adminLang()));
    }

    // -----------------------------------------------------------
    // buildScopeLabel()
    // -----------------------------------------------------------

    function testBuildScopeLabelGlobal(): void {
        $label = $this->controller()->buildScopeLabel('global', null, null, $this->adminLang());

        $this->assertNotSame('', $label);
    }

    function testBuildScopeLabelCategoryEnthaeltKategorieBezeichner(): void {
        $label = $this->controller()->buildScopeLabel('category', 'ueber-uns', null, $this->adminLang());

        $this->assertStringContainsString('ueber-uns', $label);
    }

    function testBuildScopeLabelPageEnthaeltSeitenBezeichner(): void {
        $label = $this->controller()->buildScopeLabel('page', 'ueber-uns', 'kontakt', $this->adminLang());

        $this->assertStringContainsString('kontakt', $label);
    }

    // -----------------------------------------------------------
    // buildSaveButtonLabel()
    // -----------------------------------------------------------

    function testBuildSaveButtonLabelGlobalOhneKategorie(): void {
        $label = $this->controller()->buildSaveButtonLabel(null, null, $this->adminLang());

        $this->assertNotSame('', $label);
    }

    function testBuildSaveButtonLabelCategoryEnthaeltKategorieBezeichner(): void {
        $label = $this->controller()->buildSaveButtonLabel('impressum', null, $this->adminLang());

        $this->assertStringContainsString('impressum', $label);
    }

    function testBuildSaveButtonLabelPageEnthaeltSeitenBezeichner(): void {
        $label = $this->controller()->buildSaveButtonLabel('impressum', 'kontakt', $this->adminLang());

        $this->assertStringContainsString('kontakt', $label);
    }

    // -----------------------------------------------------------
    // renderSaveResultNotice()
    // -----------------------------------------------------------

    function testRenderSaveResultNoticeErfolg(): void {
        $html = $this->controller()->renderSaveResultNotice(['success' => true, 'errors' => []], $this->adminLang());

        $this->assertStringContainsString('schemaOrgData-notice--success', $html);
    }

    function testRenderSaveResultNoticeFehlerListetFehlerAuf(): void {
        $html = $this->controller()->renderSaveResultNotice(
            ['success' => false, 'errors' => ['Feld X ist ungültig']], $this->adminLang()
        );

        $this->assertStringContainsString('schemaOrgData-notice--error', $html);
        $this->assertStringContainsString('Feld X ist ungültig', $html);
    }

    // -----------------------------------------------------------
    // renderExistingJsonLdNotice()
    // -----------------------------------------------------------

    function testRenderExistingJsonLdNoticeLeerOhneVorhandenesJsonLd(): void {
        $settings = new \InMemorySettings();

        $html = $this->controller()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame('', $html);
    }

    function testRenderExistingJsonLdNoticeMitVorhandenemJsonLd(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => [
                'existing_jsonld' => true,
                'jsonld_mode' => 'keep',
                'existing_jsonld_content' => '{"@type":"LocalBusiness"}',
            ],
        ]);

        $html = $this->controller()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('schemaOrgData-jsonld-notice', $html);
        $this->assertStringContainsString('schemaOrgData-autofill-btn', $html);
    }

    // -----------------------------------------------------------
    // renderCollisionNotice()
    // -----------------------------------------------------------

    function testRenderCollisionNoticeLeerOhneKollision(): void {
        $settings = new \InMemorySettings();

        $html = $this->controller()->renderCollisionNotice(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame('', $html);
    }

    function testRenderCollisionNoticeMitKollisionAufGlobalerEbene(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH']]);

        $html = $this->controller()->renderCollisionNotice(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('schemaOrgData-notice--info', $html);
    }

    // -----------------------------------------------------------
    // resolveInheritableFields()
    // -----------------------------------------------------------

    function testResolveInheritableFieldsGlobalLiefertLeeresErgebnis(): void {
        $settings = new \InMemorySettings();

        $result = $this->controller()->resolveInheritableFields(
            'global', null, null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame([], $result['data']);
        $this->assertSame([], $result['originLabel']);
    }

    function testResolveInheritableFieldsCategoryErbtVonGlobal(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH', 'email' => 'info@example.com']]);

        $result = $this->controller()->resolveInheritableFields(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
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
            'page', 'ueber-uns', 'kontakt', 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame('Kategorie GmbH', $result['data']['name']);
    }

    // -----------------------------------------------------------
    // renderExcludedCatsField()
    // -----------------------------------------------------------

    function testRenderExcludedCatsFieldOhneCatPageNurDebugCheckbox(): void {
        $html = $this->controller()->renderExcludedCatsField([], false, $this->adminLang());

        $this->assertStringNotContainsString('schemaOrgData-checkbox--all', $html);
        $this->assertStringContainsString('schemaOrgData_global_debug_output', $html);
    }

    function testRenderExcludedCatsFieldDebugCheckboxGesetztWennAktiv(): void {
        $html = $this->controller()->renderExcludedCatsField([], true, $this->adminLang());

        $this->assertStringContainsString('schemaOrgData_global_debug_output" name="schemaOrgData[global][debug_output]" value="1" checked="checked"', $html);
    }

    // -----------------------------------------------------------
    // renderScopeSelector()
    // -----------------------------------------------------------

    function testRenderScopeSelectorOhneCatPageLiefertLeerenString(): void {
        $this->assertSame('', $this->controller()->renderScopeSelector(null, null, $this->adminLang()));
    }

    // -----------------------------------------------------------
    // sanitizeAddressData() (Schritt 12b)
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
    // sanitizePostData() (Schritt 12b)
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
    // renderScopeSection() (Schritt 12b)
    // -----------------------------------------------------------

    private function callRenderScopeSection(
        string $scope, ?string $cat, ?string $page, bool $active, ?string $idPrefix, bool $saveFailed, $settings
    ): string {
        return $this->controller()->renderScopeSection(
            $scope, $cat, $page, $active, $idPrefix, $saveFailed,
            $this->adminLang(), $this->scopeResolver(), $settings, $this->schemaRepository(),
            $this->pluginSelfDir(), $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            'deDE', $this->pluginSelfDir(), $this->weekdayLang(), $this->idReferenceService(),
            $this->openingHoursHelper(), $this->validator()
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

    // -----------------------------------------------------------
    // saveConfig() (Schritt 12b)
    // -----------------------------------------------------------

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->controller()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper()
        );
    }

    function testSaveConfigSpeichertGueltigeDatenUnterScopeKey(): void {
        $settings = new \InMemorySettings();

        $result = $this->callSaveConfig('global', $this->validLocalBusinessData(), $settings);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Muster GmbH', $settings->get('config_global')['LocalBusiness']['name']);
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

    // -----------------------------------------------------------
    // handlePostRequest() (Schritt 12b)
    // -----------------------------------------------------------

    private function callHandlePostRequest($settings): ?array {
        return $this->controller()->handlePostRequest(
            $settings, $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper()
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
    // renderAdminPage() (Schritt 12b)
    // -----------------------------------------------------------

    private function callRenderAdminPage($settings): string {
        return $this->controller()->renderAdminPage(
            $settings, $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            'deDE', $this->pluginSelfDir(), $this->weekdayLang(), $this->idReferenceService(),
            $this->validator(), $this->openingHoursHelper(), $this->collisionDetector()
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
}
