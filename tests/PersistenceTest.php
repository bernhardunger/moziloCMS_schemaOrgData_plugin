<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/***************************************************************
*
* Tests für die Persistenz-Logik: handlePostRequest(),
* saveConfig(), deleteConfig() und sanitizePostData().
*
* Geprüft werden das Speicherformat (Array unter einem
* settings-Key, siehe loadScopeMeta()/loadScopeConfig()), die
* Key-Konvention (getScopeSettingsKey()), Validierung
* (Pflichtfelder, Erweiterungsfeld), Spezialfelder
* (excluded_cats, jsonld_mode), Löschen sowie die Sanitierung
* von CAT_REQUEST/PAGE_REQUEST-Bezeichnern.
*
* Jeder Test arbeitet auf einem temporären Plugin-Verzeichnis
* (Kopie von schemas/ und sprachen/) und einem isolierten
* InMemorySettings-Stub als $this->settings, damit die echte
* plugin.conf.php des Plugins unangetastet bleibt.
*
***************************************************************/
final class PersistenceTest extends TestCase {

    private string $pluginDir;
    private \InMemorySettings $settings;

    protected function setUp(): void {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_test_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');

        $_POST = [];
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->pluginDir);
        $_POST = [];
    }

    /***************************************************************
    *
    * Kopiert ein Verzeichnis flach (nur Dateien, keine
    * Unterverzeichnisse) - ausreichend für schemas/ und sprachen/.
    *
    ***************************************************************/
    private function copyDirectory(string $from, string $to): void {
        mkdir($to, 0777, true);
        foreach(glob($from.'/*') as $file) {
            copy($file, $to.'/'.basename($file));
        }
    }

    /***************************************************************
    *
    * Entfernt ein temporäres Testverzeichnis inkl. Inhalt.
    *
    ***************************************************************/
    private function removeDirectory(string $dir): void {
        foreach(glob(rtrim($dir, '/').'/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    /***************************************************************
    *
    * Erzeugt eine Plugin-Instanz, deren PLUGIN_SELF_DIR auf das
    * temporäre Testverzeichnis zeigt (Kopien von schemas/ und
    * sprachen/) und deren $this->settings durch einen isolierten
    * InMemorySettings-Stub ersetzt wurde, damit saveConfig()/
    * deleteConfig() nicht die echte plugin.conf.php verändern.
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $plugin = new \schemaOrgData();
        $plugin->PLUGIN_SELF_DIR = $this->pluginDir;

        $this->settings = new \InMemorySettings();
        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $this->settings);

        return $plugin;
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness"
    * (gültig für die Geltungsbereiche global/category).
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
                    'Tu' => ['from' => '09:00', 'to' => '18:00'],
                    'We' => ['from' => '09:00', 'to' => '18:00'],
                    'Th' => ['from' => '09:00', 'to' => '18:00'],
                    'Fr' => ['from' => '09:00', 'to' => '18:00'],
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
    * (gültig für die Geltungsbereiche category/page).
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
    // Speichern
    // -----------------------------------------------------------

    function testGlobalConfigIsSavedUnderGlobalSettingsKey(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($this->settings->keyExists('config_global'));

        $config = $this->settings->get('config_global');
        $this->assertSame('Muster GmbH', $config['LocalBusiness']['name']);
        $this->assertSame('https://www.example.com', $config['LocalBusiness']['url']);
    }

    function testCategoryConfigIsSavedUnderOwnSettingsKey(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'ueber-uns';

        $result = callPluginMethod($plugin, 'saveConfig', ['category', $this->validLocalBusinessData('Filiale Nord')]);
        $this->assertTrue($result['success']);

        // Eigenständiger, von config_global getrennter settings-Key
        // gemäß getScopeSettingsKey()-Konvention.
        $this->assertTrue($this->settings->keyExists('config_cat_ueber-uns'));
        $this->assertFalse($this->settings->keyExists('config_global'));

        $config = $this->settings->get('config_cat_ueber-uns');
        $this->assertSame('Filiale Nord', $config['LocalBusiness']['name']);
    }

    function testPageConfigIsSavedUnderOwnSettingsKey(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'ueber-uns';
        $_POST['schemaOrgData_page'] = 'team';

        $result = callPluginMethod($plugin, 'saveConfig', ['page', $this->validFaqPageData()]);
        $this->assertTrue($result['success']);

        $this->assertTrue($this->settings->keyExists('config_page_ueber-uns_team'));

        $config = $this->settings->get('config_page_ueber-uns_team');
        $this->assertSame('Wie erreiche ich euch?', $config['FAQPage']['mainEntity'][0]['name']);
    }

    function testExistingConfigIsOverwrittenOnResave(): void {
        $plugin = $this->createPlugin();

        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData('Erste Version')]);
        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData('Zweite Version')]);

        $this->assertTrue($result['success']);

        $config = $this->settings->get('config_global');
        $this->assertSame('Zweite Version', $config['LocalBusiness']['name']);
    }

    // -----------------------------------------------------------
    // Speicherformat
    // -----------------------------------------------------------

    function testSavedConfigIsStoredAsArrayUnderSettingsKey(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $config = $this->settings->get('config_global');

        $this->assertIsArray($config);
        $this->assertSame('Muster GmbH', $config['LocalBusiness']['name']);
    }

    function testRoundTripViaLoadScopeConfig(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $loaded = callPluginMethod($plugin, 'loadScopeConfig', ['global']);

        $this->assertSame('Muster GmbH', $loaded['LocalBusiness']['name']);
        $this->assertSame('https://www.example.com', $loaded['LocalBusiness']['url']);
        $this->assertSame('Musterstadt', $loaded['LocalBusiness']['address']['addressLocality']);
        $this->assertSame('DE', $loaded['LocalBusiness']['address']['addressCountry']);
        $this->assertSame(['Mo-Fr 09:00-18:00'], $loaded['LocalBusiness']['openingHours']);
    }

    // -----------------------------------------------------------
    // Validierung beim Speichern
    // -----------------------------------------------------------

    function testInvalidExtensionJsonIsNotSaved(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['extension']['LocalBusiness'] = '{ungueltiges json';

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($this->settings->keyExists('config_global'));
    }

    function testMissingRequiredNameIsNotSaved(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        unset($postData['data']['name']);

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($this->settings->keyExists('config_global'));
    }

    function testValidDataIsSavedSuccessfully(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
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
        $plugin = $this->createPlugin();

        $postData = $this->validLocalBusinessData();
        // Komplett leere Adresse — nur die voreingestellte Länder-Select (DE).
        // isAddressProvided() muss false liefern → kein Pflichtfeld-Fehler.
        $postData['data']['address'] = [
            'streetAddress' => '',
            'postalCode' => '',
            'addressLocality' => '',
            'addressRegion' => '',
            'addressCountry' => 'DE',
        ];

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $this->assertTrue($result['success'],
            'Speichern mit komplett leerer Adresse (nur Default DE) muss erfolgreich sein.');
        $this->assertSame([], $result['errors']);
    }

    /***************************************************************
    *
    * Bug 2 (0.3.6-beta): Pflichtfeld-Fehlermeldungen mit Sonderzeichen
    * im Label (z. B. FAQPage "Fragen & Antworten") dürfen nicht doppelt
    * HTML-kodiert werden. renderSaveResultNotice() kodiert die Meldung
    * einmal via htmlspecialchars(); der Label-Wert selbst muss daher
    * unkodiert aus der Sprachdatei kommen, sodass exactly "&amp;" (statt
    * "&amp;amp;") im gerenderten Hinweisblock erscheint.
    *
    ***************************************************************/
    function testRequiredFieldErrorWithAmpersandLabelIsSingleEncoded(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'faq';
        $_POST['schemaOrgData_page'] = 'allgemein';

        $postData = $this->validFaqPageData();
        $postData['data']['mainEntity'] = [];

        $result = callPluginMethod($plugin, 'saveConfig', ['page', $postData]);
        $this->assertFalse($result['success']);

        $html = callPluginMethod($plugin, 'renderSaveResultNotice', [$result]);

        $this->assertStringContainsString('Fragen &amp; Antworten', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    // -----------------------------------------------------------
    // Spezialfelder
    // -----------------------------------------------------------

    function testExcludedCatsIsSavedAndLoadedAsArray(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['excluded_cats'] = ['impressum', 'datenschutz'];

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $postData]);
        $this->assertTrue($result['success']);

        $config = $this->settings->get('config_global');
        $this->assertSame(['impressum', 'datenschutz'], explode(',', $config['excluded_cats']));
    }

    function testJsonldModeIsSavedAndLoaded(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_jsonld_mode_global'] = 'override';

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        $this->assertTrue($result['success']);

        $meta = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
        $this->assertSame('override', $meta['jsonld_mode']);
    }

    // -----------------------------------------------------------
    // Löschen
    // -----------------------------------------------------------

    function testDeleteConfigRemovesSettingsKey(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        $this->assertTrue($this->settings->keyExists('config_global'));

        $result = callPluginMethod($plugin, 'deleteConfig', ['global']);

        $this->assertTrue($result['success']);
        $this->assertFalse($this->settings->keyExists('config_global'));
    }

    function testDeleteConfigOnNonexistentKeyReturnsSuccess(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'deleteConfig', ['global']);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
    }

    // -----------------------------------------------------------
    // Sicherheit
    // -----------------------------------------------------------

    function testGetScopeSettingsKeyFollowsNamingConvention(): void {
        $plugin = $this->createPlugin();

        $this->assertSame(
            'config_global',
            callPluginMethod($plugin, 'getScopeSettingsKey', ['global', null, null])
        );
        $this->assertSame(
            'config_cat_ueber-uns',
            callPluginMethod($plugin, 'getScopeSettingsKey', ['category', 'ueber-uns', null])
        );
        $this->assertSame(
            'config_page_ueber-uns_team',
            callPluginMethod($plugin, 'getScopeSettingsKey', ['page', 'ueber-uns', 'team'])
        );

        // Ohne Kategorie/Seite ist kein eindeutiger Key bestimmbar.
        $this->assertNull(callPluginMethod($plugin, 'getScopeSettingsKey', ['category', null, null]));
        $this->assertNull(callPluginMethod($plugin, 'getScopeSettingsKey', ['page', 'ueber-uns', null]));
    }

    function testSpecialCharactersInIdentifierAreSanitized(): void {
        $plugin = $this->createPlugin();

        $sanitized = callPluginMethod($plugin, 'sanitizeScopeIdentifier', ['Über uns! (Kontakt)']);

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_\-]*$/', $sanitized);
        $this->assertStringNotContainsString(' ', $sanitized);
        $this->assertStringNotContainsString('!', $sanitized);
    }

    function testPathTraversalAttemptIsSanitized(): void {
        $plugin = $this->createPlugin();

        $sanitized = callPluginMethod($plugin, 'sanitizeScopeIdentifier', ['../../etc/passwd']);

        $this->assertSame('etcpasswd', $sanitized);

        $key = callPluginMethod($plugin, 'getScopeSettingsKey', ['category', $sanitized, null]);
        $this->assertSame('config_cat_etcpasswd', $key);
    }

    // -----------------------------------------------------------
    // POST-Retention nach fehlgeschlagenem Save (renderScopeSection)
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Regressionstest für 0.2.2-beta: schlägt das Speichern fehl
    * (z. B. wegen ungültiger url), müssen die vom Nutzer
    * eingegebenen POST-Werte erhalten bleiben - auch wenn bereits
    * eine andere, gespeicherte Konfiguration existiert.
    *
    ***************************************************************/
    function testFailedSaveRetainsPostedScalarValuesInActiveSection(): void {
        $plugin = $this->createPlugin();

        $oldData = $this->validLocalBusinessData('Alte Firma');
        callPluginMethod($plugin, 'saveConfig', ['global', $oldData]);

        $newData = $this->validLocalBusinessData('Neue Firma');
        $newData['data']['url'] = 'nicht-eine-url';
        $newData['data']['address']['addressLocality'] = 'Neustadt';
        $_POST['schemaOrgData'] = ['global' => $newData];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = callPluginMethod($plugin, 'renderScopeSection', ['global', null, null, true, 'global', true]);

        $this->assertStringContainsString('Neue Firma', $html);
        $this->assertStringContainsString('Neustadt', $html);
        $this->assertStringContainsString('nicht-eine-url', $html);
        $this->assertStringNotContainsString('Alte Firma', $html);
        $this->assertStringNotContainsString('Altstadt', $html);
    }

    /***************************************************************
    *
    * Regressionstest: ein ungültiges Zeitformat (z. B. "8:00" statt
    * "08:00") in einem Öffnungszeiten-Feld darf beim Re-Display nach
    * fehlgeschlagenem Save nicht zu leeren Von/Bis-Feldern führen.
    * buildOpeningHoursArray()/parseOpeningHours() würden den Eintrag
    * sonst verlustbehaftet verwerfen (siehe renderScopeSection /
    * renderOpeningHoursWidget).
    *
    ***************************************************************/
    function testFailedSaveRetainsInvalidOpeningHoursTime(): void {
        $plugin = $this->createPlugin();

        $postData = $this->validLocalBusinessData();
        $postData['data']['url'] = 'nicht-eine-url';
        $postData['data']['openingHours']['Mo'] = ['from' => '8:00', 'to' => '18:00'];
        $_POST['schemaOrgData'] = ['global' => $postData];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $html = callPluginMethod($plugin, 'renderScopeSection', ['global', null, null, true, 'global', true]);

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
        $plugin = $this->createPlugin();

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

        $html = callPluginMethod($plugin, 'renderScopeSection', ['global', null, null, true, 'global', true]);

        $this->assertStringContainsString('value="13:00"', $html);
        $this->assertStringContainsString('value="17:00"', $html);
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
        // renderAdminPage() benötigt die im PLUGINADMIN-Kontext von
        // moziloCMS gesetzten Admin-Konstanten - in eigenem Prozess
        // definiert, damit andere Tests (getContent() im Frontend-
        // Kontext) nicht durch defined('PLUGINADMIN') beeinflusst werden.
        define('ADMIN_DIR_NAME', 'admin');
        define('PLUGINADMIN', 'schemaOrgData');
        define('ACTION', 'plugin_admin');
        define('EXT_PAGE', '.txt.php');
        define('EXT_HIDDEN', '.hid.php');

        global $CatPage;
        $rawCat = 'Über-uns';
        $CatPage = new FakeCatPageWithPages([$rawCat]);

        $plugin = $this->createPlugin();

        $postData = $this->validLocalBusinessData('Filiale Nord');
        $postData['data']['address']['streetAddress'] = 'Musterstrasse 5';
        $postData['data']['address']['addressLocality'] = '';

        $_POST['schemaOrgData'] = ['category' => $postData];
        $_POST['schemaOrgData_cat'] = $rawCat;
        $_POST['schemaOrgData_page'] = '';

        $html = callPluginMethod($plugin, 'renderAdminPage', []);

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

        global $CatPage;
        $cat = 'unterseite';
        $CatPage = new FakeCatPageWithPages([$cat]);

        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = $cat;
        $_POST['schemaOrgData_page'] = '';

        callPluginMethod($plugin, 'renderAdminPage', []);

        unset($CatPage);
        unlink($layoutDir . '/template.html');
        rmdir($layoutDir);

        $globalMeta = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
        $categoryMeta = callPluginMethod($plugin, 'loadScopeMeta', ['category', $cat]);

        $this->assertTrue($globalMeta['existing_jsonld']);
        $this->assertFalse($categoryMeta['existing_jsonld']);
    }
}

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Klasse CatPage, ausschließlich
* für renderAdminPage()/renderScopeSelector() (get_CatArray() und
* get_PageArray()).
*
***************************************************************/
final class FakeCatPageWithPages {

    function __construct(private array $cats, private array $pages = []) {
    }

    function get_CatArray(bool $all = false, $showlink = false, $containspage = null): array {
        return $this->cats;
    }

    function get_PageArray(string $cat, $extensions = null, $showlink = true): array {
        return $this->pages[$cat] ?? [];
    }
}
