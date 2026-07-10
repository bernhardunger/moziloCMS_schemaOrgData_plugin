<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminController:
* Orchestrierung - renderScopeSection(), renderAdminPage(). Die reinen
* Anzeige-Bausteine sind in SchemaOrgData_AdminPageRenderer ausgelagert
* (siehe tests/AdminPageRendererTest.php), die POST-Verarbeitung
* (handlePostRequest()) in SchemaOrgData_AdminRequestHandler (siehe
* tests/AdminRequestHandlerTest.php), die feldweise Vererbungsanzeige
* (resolveInheritableFields()), POST-Sanitizing (sanitizePostData(),
* sanitizeAddressData()) und Speichern/Validieren (saveConfig()) in
* SchemaOrgData_ConfigSaveService (siehe
* tests/ConfigSaveServiceTest.php). Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_FormRenderer-/SchemaOrgData_Validator-/
* SchemaOrgData_OpeningHoursHelper-/SchemaOrgData_DataSplitHelper-/
* SchemaOrgData_UrlHelper-/SchemaOrgData_IdReferenceService-/
* SchemaOrgData_CollisionDetector-/SchemaOrgData_AdminPageRenderer-/
* SchemaOrgData_ConfigSaveService-Instanzen, $pluginSelfDir zeigt auf
* die realen Schema-/Sprach-Fixtures des Plugins, $settings ist ein
* isolierter InMemorySettings-Stub.
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
    * normalen Suite-Lauf wird die Klasse ggf. bereits durch eine andere
    * Testdatei geladen, die tests/Fixtures/FakeCatPageWithPages.php
    * referenziert; in
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

    private function configSaveService(): \SchemaOrgData_ConfigSaveService {
        return new \SchemaOrgData_ConfigSaveService();
    }

    private function importService(): \SchemaOrgData_ImportService {
        return new \SchemaOrgData_ImportService();
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness".
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
    * Minimale, gültige Formulardaten für den Type "FAQPage".
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
    // renderScopeSection()
    // -----------------------------------------------------------

    private function buildAdminRequestContext($settings, ?\Language $lang = null): \SchemaOrgData_AdminRequestContext {
        return new \SchemaOrgData_AdminRequestContext(
            $settings, $lang ?? $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            'deDE', $this->pluginSelfDir(), $this->weekdayLang(), $this->idReferenceService(),
            $this->validator(), $this->openingHoursHelper(), $this->collisionDetector(),
            $this->adminPageRenderer(), $this->adminRequestHandler(), $this->configSaveService(),
            $this->importService()
        );
    }

    private function callRenderScopeSection(
        string $scope, ?string $cat, ?string $page, bool $active, ?string $idPrefix, bool $saveFailed, $settings
    ): string {
        return $this->controller()->renderScopeSection(
            $scope, $cat, $page, $active, $idPrefix, $saveFailed,
            $this->buildAdminRequestContext($settings)
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
    * Regressionstest: data-save-label wurde bislang zusätzlich zum
    * bereits HTML-escapten Ergebnis von buildSaveButtonLabel() ein
    * zweites Mal mit htmlspecialchars() escaped - im Attributwert
    * erschien dadurch "&amp;auml;" statt "&auml;", initScopeSelector()
    * (validator.js) übernahm den Rohstring per textContent unverändert
    * sichtbar in den Button ("...Steuererkl&auml;rung..." statt "ä").
    *
    ***************************************************************/
    function testRenderScopeSectionDataSaveLabelIstEinfachKodiertBeiUmlautImSeitennamen(): void {
        $html = $this->callRenderScopeSection(
            'page', 'steuerberatung', rawurlencode('Steuererklärung'), true, 'page', false, new \InMemorySettings()
        );

        $this->assertStringContainsString('Steuererkl&auml;rung', $html);
        $this->assertStringNotContainsString('&amp;auml;', $html);
    }

    /***************************************************************
    *
    * [TEMP] Dev-Reset-Button (SchemaOrgData_AdminController::
    * DEV_RESET_BUTTON_ENABLED). Deckt alle drei Geltungsbereiche ab,
    * da der Button je Scope einen eigenen Namen
    * (schemaOrgData_delete_{scope}) trägt, der vom bestehenden
    * handlePostRequest()-Pfad ausgewertet wird.
    *
    ***************************************************************/
    function testRenderScopeSectionEnthaeltDevResetButtonFuerAlleScopes(): void {
        $globalHtml = $this->callRenderScopeSection('global', null, null, true, 'global', false, new \InMemorySettings());
        $this->assertStringContainsString('name="schemaOrgData_delete_global"', $globalHtml);

        $catHtml = $this->callRenderScopeSection('category', 'ueber-uns', null, true, 'category', false, new \InMemorySettings());
        $this->assertStringContainsString('name="schemaOrgData_delete_category"', $catHtml);

        $pageHtml = $this->callRenderScopeSection('page', 'ueber-uns', 'team', true, 'page', false, new \InMemorySettings());
        $this->assertStringContainsString('name="schemaOrgData_delete_page"', $pageHtml);
    }

    /***************************************************************
    *
    * [TEMP] Sicherheitsnetz-Verifikation für den Dev-Reset-Button:
    * <button> wird vom generischen input/select/textarea-disabled-
    * Mechanismus NICHT erfasst - die Klick-Sperre einer inaktiven
    * Sektion greift stattdessen über das umschließende
    * style="display:none" auf dem .schemaOrgData-scope-Container
    * (gilt element-unabhängig für jeden Nachfahren, siehe
    * validator.js activateSection()). Dieser Test stellt sicher,
    * dass der Button weiterhin innerhalb dieses Containers liegt.
    *
    ***************************************************************/
    function testRenderScopeSectionDevResetButtonInInaktiverSektionIstDurchDisplayNoneGeschuetzt(): void {
        $html = $this->callRenderScopeSection('global', null, null, false, 'global', false, new \InMemorySettings());

        $scopeDivPos = strpos($html, 'data-scope="global"');
        $buttonPos = strpos($html, 'name="schemaOrgData_delete_global"');
        $displayNonePos = strpos($html, 'style="display:none"');

        $this->assertNotFalse($scopeDivPos);
        $this->assertNotFalse($buttonPos);
        $this->assertNotFalse($displayNonePos);
        $this->assertLessThan($buttonPos, $displayNonePos);
    }

    /***************************************************************
    *
    * Regressionstest: schlägt das Speichern fehl
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
    * Regressionstest: schlägt das Speichern fehl
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

    /***************************************************************
    *
    * saveConfig() lebt auf
    * SchemaOrgData_ConfigSaveService (siehe tests/ConfigSaveServiceTest.php)
    * - dieser Helper wird von testFailedSaveRetainsPostedScalarValuesInActiveSection()
    * benötigt, um vor der eigentlichen renderScopeSection()-Prüfung eine
    * bereits gespeicherte Konfiguration anzulegen.
    *
    ***************************************************************/
    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->configSaveService()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->adminPageRenderer()
        );
    }

    /***************************************************************
    *
    * LocalBusiness-Familie: ist bei Global bereits ein Familien-Type
    * konfiguriert, darf das
    * Kategorie-Dropdown nur diesen einen Familien-Type anbieten -
    * andere Familienmitglieder werden ausgeblendet, Content-Types
    * bleiben vollständig erhalten, und der Hinweistext erscheint.
    *
    ***************************************************************/
    function testRenderScopeSectionFiltertLocalBusinessFamilieAufGlobalenTypeBeiKategorie(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['AccountingService' => ['name' => 'Kanzlei Muster', 'url' => 'https://www.example.com']]);

        $html = $this->callRenderScopeSection('category', 'ueber-uns', null, true, 'category', false, $settings);

        $this->assertStringContainsString('value="AccountingService"', $html);
        $this->assertStringNotContainsString('value="LocalBusiness"', $html);
        $this->assertStringNotContainsString('value="ProfessionalService"', $html);
        $this->assertStringNotContainsString('value="LegalService"', $html);
        $this->assertStringNotContainsString('value="MedicalBusiness"', $html);
        // Content-Types bleiben unberührt
        $this->assertStringContainsString('value="Article"', $html);
        $this->assertStringContainsString('value="FAQPage"', $html);

        $lang = $this->adminLang();
        $this->assertStringContainsString(
            $lang->getLanguageValue('schema_type_accountingservice'),
            html_entity_decode(strip_tags($html))
        );
    }

    /***************************************************************
    *
    * Ohne bei Global konfigurierten Familien-Type (kein Schema
    * gewählt) gilt keine Einschränkung - alle Familienmitglieder
    * bleiben im Kategorie-Dropdown verfügbar, kein Hinweistext.
    *
    ***************************************************************/
    function testRenderScopeSectionOhneGlobalenFamilienTypeZeigtAlleFamilienmitgliederUngefiltert(): void {
        $html = $this->callRenderScopeSection('category', 'ueber-uns', null, true, 'category', false, new \InMemorySettings());

        $this->assertStringContainsString('value="LocalBusiness"', $html);
        $this->assertStringContainsString('value="ProfessionalService"', $html);
        $this->assertStringContainsString('value="LegalService"', $html);
        $this->assertStringContainsString('value="MedicalBusiness"', $html);
        $this->assertStringContainsString('value="AccountingService"', $html);
        $this->assertStringNotContainsString('schemaOrgData-hint--family-filtered', $html);
    }

    // -----------------------------------------------------------
    // renderAdminPage()
    // -----------------------------------------------------------

    private function adminRequestHandler(): \SchemaOrgData_AdminRequestHandler {
        return new \SchemaOrgData_AdminRequestHandler();
    }

    private function callRenderAdminPage($settings): string {
        return $this->controller()->renderAdminPage($this->buildAdminRequestContext($settings));
    }

    private function callRenderAdminPageWithLang($settings, \Language $lang): string {
        return $this->controller()->renderAdminPage($this->buildAdminRequestContext($settings, $lang));
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

    /***************************************************************
    *
    * js/validator.js liest getMessages().dateInvalid
    * bzw. getMessages().dateRangeInvalid für die date-time-Live-
    * Validierung von Event.startDate/endDate - ohne diese beiden Keys in
    * window.schemaOrgDataMessages liefen die Aufrufe dort ins Leere.
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageEnthaeltDateTimeMessageKeys(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        $this->assertStringContainsString('"dateInvalid"', $html);
        $this->assertStringContainsString('"dateRangeInvalid"', $html);
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
    * Regressionstest: window.schemaOrgDataMessages wird per
    * json_encode() mit JSON_HEX_TAG kodiert. Enthält ein
    * Sprachdatei-Wert "</script>",
    * darf dieser NICHT literal im erzeugten <script>-Block erscheinen
    * (Script-Break-out), sondern muss als Unicode-Escape kodiert sein.
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageEscaptSchemaOrgDataMessagesGegenScriptBreakout(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $maliciousLang = new class($this->adminLang()) extends \Language {
            private \Language $delegate;
            function __construct(\Language $delegate) {
                $this->delegate = $delegate;
            }
            function getLanguageValue(string $phrase, string $param1 = '', string $param2 = ''): string {
                if ($phrase === 'error_postal_code_format') {
                    return '</script><script>alert(1)</script>';
                }
                return $this->delegate->getLanguageValue($phrase, $param1, $param2);
            }
        };

        $html = $this->callRenderAdminPageWithLang(new \InMemorySettings(), $maliciousLang);

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        $this->assertStringContainsString(
            "\\u003C/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C/script\\u003E",
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest: renderAdminPage() ermittelt
    * $selectedCat aus sanitizeScopeIdentifier($_POST['schemaOrgData_cat'])
    * und verglich diesen Wert bislang direkt mit dem UNSANIERTEN
    * Kategorie-Bezeichner aus get_CatArray(). Enthält dieser
    * Zeichen, die sanitizeScopeIdentifier() entfernt (z. B. Umlaute),
    * blieb die gerade bearbeitete Kategorie-Sektion inaktiv
    * (display:none, disabled) und renderScopeSection() füllte das
    * Formular aus $config statt aus den POST-Daten. Bei einer noch
    * nie gespeicherten Kategorie (erster Speicherversuch, der wegen
    * der bedingungslosen addressLocality-Prüfung
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
    * Regressionstest: Ein im Layout-Template gefundener
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

    /***************************************************************
    *
    * Playwright-Regressionstest: Nach dem
    * Speichern von Event.organizer im Referenz-Modus zeigte das
    * Admin-Formular beim Neuladen keinen der beiden Radio-Buttons
    * (Referenz/Manuell) mehr als ausgewählt an. Ursache: renderAdminPage()
    * rendert das organizer-Widget für JEDE Seite mit Event-Type vor
    * (auch inaktive, per display:none/disabled ausgeblendete Seiten-
    * Sektionen), und renderIdReferenceOrLiteralWidget() verwendete für
    * den name des Radios bislang den literalen $scope ("page"), nicht
    * das seiten-eindeutige $idPrefix. Teilen sich zwei Seiten damit
    * denselben Radio-name, behandelt der Browser sie beim Parsen als
    * EINE Gruppe und entcheckt automatisch alle bis auf das zuletzt im
    * DOM stehende Exemplar - unabhängig von Sichtbarkeit/disabled. Die
    * eigentlich gespeicherte Seite kann so als "kein Radio ausgewählt"
    * erscheinen. Test rendert zwei Seiten derselben Kategorie (nur die
    * erste hat Event/organizer im Referenz-Modus gespeichert, die
    * zweite bleibt leer und zeigt daher den Default-Modus "reference")
    * und prüft, dass beide Radiogruppen eindeutige Namen tragen und
    * das Referenz-Radio der gespeicherten Seite als checked markiert
    * bleibt.
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testOrganizerReferenceRadioBleibtNachSpeichernAusgewaehltTrotzWeitererSeite(): void {
        define('ADMIN_DIR_NAME', 'admin');
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('EXT_PAGE', '.txt.php');
        define('EXT_HIDDEN', '.hid.php');

        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $postData = [
            'type' => 'Event',
            'data' => [
                'name' => 'Sommerfest',
                'startDate' => '15.09.2026 19:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'organization'],
            ],
            'extension' => ['Event' => ''],
        ];
        $saveResult = $this->configSaveService()->saveConfig(
            'page', $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->adminPageRenderer()
        );
        $this->assertTrue($saveResult['success'], implode(', ', $saveResult['errors']));

        $_POST = [];
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $this->ensureFakeCatPageWithPagesLoaded();
        global $CatPage;
        // zweite Seite derselben Kategorie ohne eigene Event-Konfiguration -
        // rendert trotzdem ein leeres Event-Formular mit organizer-Widget,
        // da Event für den Scope "page" grundsätzlich verfügbar ist
        $CatPage = new FakeCatPageWithPages(
            ['veranstaltungen'],
            ['veranstaltungen' => ['sommerfest', 'andere-seite']]
        );

        $html = $this->callRenderAdminPage($settings);

        unset($CatPage);

        // (disabled="disabled" wird bei inaktiven Sektionen per preg_replace()
        // direkt nach dem Tag-Namen eingefügt, siehe renderScopeSection())
        preg_match_all(
            '/<input (?:disabled="disabled" )?type="radio" class="schemaOrgData-idrl-radio" name="([^"]+)" value="([a-z]+)"( checked="checked")?/',
            $html, $radioMatches, PREG_SET_ORDER
        );

        $sommerfestGroups = array_values(array_filter($radioMatches, fn($m) => str_contains($m[1], 'sommerfest')));
        $andereSeiteGroups = array_values(array_filter($radioMatches, fn($m) => str_contains($m[1], 'andere-seite')));

        $this->assertNotEmpty($sommerfestGroups, 'organizer-Radios der Seite "sommerfest" müssen im HTML vorhanden sein');
        $this->assertNotEmpty($andereSeiteGroups, 'organizer-Radios der Seite "andere-seite" müssen im HTML vorhanden sein');

        // Regressionsschutz gegen die eigentliche Root Cause: die
        // Radiogruppen zweier unterschiedlicher Seiten dürfen keinen
        // identischen name teilen
        $this->assertNotSame($sommerfestGroups[0][1], $andereSeiteGroups[0][1]);

        $sommerfestReference = array_values(array_filter($sommerfestGroups, fn($m) => $m[2] === 'reference'))[0];
        $this->assertSame(
            ' checked="checked"', $sommerfestReference[3] ?? '',
            'Referenz-Radio der Seite "sommerfest" muss nach dem Speichern trotz weiterer Seite ausgewählt bleiben'
        );
    }

    /***************************************************************
    *
    * Fehlt der Plugin-Platzhalter {schemaOrgData} im aktiven
    * Layout-Template, MUSS renderAdminPage() den Hinweis anzeigen -
    * unabhängig vom aktuell gewählten Geltungsbereich (siehe
    * renderPlaceholderMissingNotice()).
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageZeigtHinweisBeiFehlendemPlatzhalter(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $layout = 'schemaOrgData_ph_missing_' . uniqid();
        $layoutDir = \BASE_DIR . \LAYOUT_DIR_NAME . '/' . $layout;
        mkdir($layoutDir, 0777, true);
        file_put_contents($layoutDir . '/template.html', '<head><title>Kein Platzhalter</title></head>');
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        unlink($layoutDir . '/template.html');
        rmdir($layoutDir);

        // Nicht auf die bloße CSS-Klasse prüfen - die steht wegen getAdminCss()
        // ohnehin immer im <style>-Block, unabhängig vom Prüfergebnis.
        $this->assertStringContainsString('<div class="schemaOrgData-notice schemaOrgData-placeholder-notice">', $html);
    }

    /***************************************************************
    *
    * Ist der Plugin-Platzhalter im aktiven Layout-Template vorhanden,
    * darf renderAdminPage() den Hinweis nicht anzeigen.
    *
    ***************************************************************/
    /***************************************************************
    *
    * Import-Verdrahtung: Ende-zu-Ende
    * über renderAdminPage() - Import-POST füllt das Formular mit den
    * importierten Werten, zeigt notice_import_success statt
    * notice_config_saved, unbekannte Property landet im
    * Erweiterungsfeld, Settings bleiben unverändert (kein Auto-Save).
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageEndeZuEndeImportBefuelltFormularUndZeigtEigenenHinweis(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_import_action'] = 'global';
        $_POST['schemaOrgData_import_global'] = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Importierte Firma',
            'url' => 'https://www.example.com',
            'hasMap' => 'https://maps.example.org/importiert',
        ]);

        $html = $this->callRenderAdminPage($settings);

        $this->assertStringContainsString('Importierte Firma', $html);
        $this->assertStringContainsString('hasMap', $html);
        $this->assertStringContainsString('maps.example.org/importiert', $html);

        $lang = $this->adminLang();
        $this->assertStringContainsString($lang->getLanguageHtml('notice_import_success'), $html);
        $this->assertStringNotContainsString($lang->getLanguageHtml('notice_config_saved'), $html);

        $this->assertFalse($settings->keyExists('config_global'));
    }

    /***************************************************************
    *
    * ADR (k): Import eines Types, der bereits auf einer anderen Ebene
    * konfiguriert ist, zeigt den bestehenden Type-Kollisionshinweis
    * bereits im Import-Redisplay - vor dem eigentlichen Speichern.
    *
    ***************************************************************/
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageImportZeigtTypeKollisionsHinweisVorDemSpeichern(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');
        define('EXT_PAGE', '.txt.php');
        define('EXT_HIDDEN', '.hid.php');

        $this->ensureFakeCatPageWithPagesLoaded();
        global $CatPage;
        $cat = 'ueber-uns';
        $CatPage = new FakeCatPageWithPages([$cat]);

        $settings = new \InMemorySettings();
        $this->callSaveConfig('global', $this->validLocalBusinessData('Global GmbH'), $settings);

        $_POST['schemaOrgData_cat'] = $cat;
        $_POST['schemaOrgData_page'] = '';
        $_POST['schemaOrgData_import_action'] = 'category';
        $_POST['schemaOrgData_import_category'] = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Filiale Über-uns',
            'url' => 'https://www.example.com/ueber-uns',
        ]);

        $html = $this->callRenderAdminPage($settings);

        unset($CatPage);

        $lang = $this->adminLang();
        $this->assertStringContainsString('schemaOrgData-notice--info', $html);
        $this->assertStringContainsString($lang->getLanguageValue('scope_global'), $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    function testRenderAdminPageZeigtKeinenHinweisBeiVorhandenemPlatzhalter(): void {
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('ADMIN_DIR_NAME', 'admin');

        $layout = 'schemaOrgData_ph_present_' . uniqid();
        $layoutDir = \BASE_DIR . \LAYOUT_DIR_NAME . '/' . $layout;
        mkdir($layoutDir, 0777, true);
        file_put_contents($layoutDir . '/template.html', '<head>{schemaOrgData}</head>');
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $html = $this->callRenderAdminPage(new \InMemorySettings());

        unlink($layoutDir . '/template.html');
        rmdir($layoutDir);

        // Nicht auf die bloße CSS-Klasse prüfen - die steht wegen getAdminCss()
        // ohnehin immer im <style>-Block, unabhängig vom Prüfergebnis.
        $this->assertStringNotContainsString('<div class="schemaOrgData-notice schemaOrgData-placeholder-notice">', $html);
    }
}
