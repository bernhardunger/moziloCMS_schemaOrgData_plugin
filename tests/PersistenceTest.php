<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die Persistenz-Logik: handlePostRequest(),
* saveConfig(), deleteConfig() und sanitizePostData().
*
* Geprüft werden Speicherformat ("<?php die(); ?>" + serialize(),
* siehe loadScopeMeta()/loadScopeConfig()), Dateinamen-Konvention
* (getScopeConfFile()), Validierung (Pflichtfelder, Erweiterungs-
* feld), Spezialfelder (excluded_cats, jsonld_mode), Löschen sowie
* die Sanitierung von CAT_REQUEST/PAGE_REQUEST-Bezeichnern.
*
* Jeder Test arbeitet auf einem temporären Plugin-Verzeichnis
* (Kopie von schemas/ und sprachen/, leeres conf/-Verzeichnis),
* damit das echte plugins/schemaOrgData/conf/ unangetastet bleibt.
*
***************************************************************/
final class PersistenceTest extends TestCase {

    private string $pluginDir;

    protected function setUp(): void {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_test_'.uniqid().'/';
        mkdir($this->pluginDir.'conf', 0777, true);
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
    * temporäre Testverzeichnis zeigt (eigenes conf/, Kopien von
    * schemas/ und sprachen/), damit saveConfig()/deleteConfig()
    * nicht das echte conf/-Verzeichnis des Plugins verändern.
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $plugin = new \schemaOrgData();
        $plugin->PLUGIN_SELF_DIR = $this->pluginDir;
        return $plugin;
    }

    /***************************************************************
    *
    * Liest eine conf-Datei ("<?php die(); ?>" + serialize()) ein.
    *
    ***************************************************************/
    private function readConf(string $file): array {
        return (new \Properties($file))->toArray();
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

    function testGlobalConfigIsSavedToGlobalConfFile(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertFileExists($this->pluginDir.'conf/_global.conf.php');

        $config = $this->readConf($this->pluginDir.'conf/_global.conf.php');
        $this->assertSame('Muster GmbH', $config['LocalBusiness']['name']);
        $this->assertSame('https://www.example.com', $config['LocalBusiness']['url']);
    }

    function testCategoryConfigIsSavedToOwnConfFile(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['category', $this->validLocalBusinessData('Filiale Nord')]);
        $this->assertTrue($result['success']);

        // CAT_REQUEST ist im Test-Bootstrap "false" (siehe tests/bootstrap.php),
        // resolveScopeIdentifiers() liefert daher [null, null]. Die Datei wird
        // dennoch über die Konvention von getScopeConfFile() ermittelt und ist
        // eine eigenständige, von _global.conf.php getrennte Datei.
        $expectedFile = callPluginMethod($plugin, 'getScopeConfFile', ['category', null, null]);
        $this->assertFileExists($expectedFile);
        $this->assertNotSame($this->pluginDir.'conf/_global.conf.php', $expectedFile);

        $config = $this->readConf($expectedFile);
        $this->assertSame('Filiale Nord', $config['LocalBusiness']['name']);
    }

    function testPageConfigIsSavedToOwnConfFile(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['page', $this->validFaqPageData()]);
        $this->assertTrue($result['success']);

        $expectedFile = callPluginMethod($plugin, 'getScopeConfFile', ['page', null, null]);
        $this->assertFileExists($expectedFile);

        $config = $this->readConf($expectedFile);
        $this->assertSame('Wie erreiche ich euch?', $config['FAQPage']['mainEntity'][0]['name']);
    }

    function testExistingConfigIsOverwrittenOnResave(): void {
        $plugin = $this->createPlugin();

        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData('Erste Version')]);
        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData('Zweite Version')]);

        $this->assertTrue($result['success']);

        $config = $this->readConf($this->pluginDir.'conf/_global.conf.php');
        $this->assertSame('Zweite Version', $config['LocalBusiness']['name']);
    }

    // -----------------------------------------------------------
    // Dateiformat
    // -----------------------------------------------------------

    function testSavedFileStartsWithPhpDieStatement(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $content = file_get_contents($this->pluginDir.'conf/_global.conf.php');

        $this->assertStringStartsWith('<?php die(); ?>', $content);
    }

    function testSavedDataIsSerialized(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $content = file_get_contents($this->pluginDir.'conf/_global.conf.php');
        $serialized = trim((string) preg_replace('/^<\?php[^\n]*\n/', '', $content, 1));

        // serialize() eines Arrays beginnt stets mit "a:"
        $this->assertStringStartsWith('a:', $serialized);

        $data = unserialize($serialized);
        $this->assertIsArray($data);
        $this->assertSame('Muster GmbH', $data['LocalBusiness']['name']);
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
        $this->assertFileDoesNotExist($this->pluginDir.'conf/_global.conf.php');
    }

    function testMissingRequiredNameIsNotSaved(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        unset($postData['data']['name']);

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFileDoesNotExist($this->pluginDir.'conf/_global.conf.php');
    }

    function testValidDataIsSavedSuccessfully(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
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

        $config = $this->readConf($this->pluginDir.'conf/_global.conf.php');
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

    function testDeleteConfigRemovesConfFile(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        $this->assertFileExists($this->pluginDir.'conf/_global.conf.php');

        $result = callPluginMethod($plugin, 'deleteConfig', ['global']);

        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($this->pluginDir.'conf/_global.conf.php');
    }

    function testDeleteConfigOnNonexistentFileReturnsSuccess(): void {
        $plugin = $this->createPlugin();

        $result = callPluginMethod($plugin, 'deleteConfig', ['global']);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
    }

    // -----------------------------------------------------------
    // Sicherheit
    // -----------------------------------------------------------

    function testGetScopeConfFileFollowsNamingConvention(): void {
        $plugin = $this->createPlugin();

        $this->assertSame(
            $this->pluginDir.'conf/_global.conf.php',
            callPluginMethod($plugin, 'getScopeConfFile', ['global', null, null])
        );
        $this->assertSame(
            $this->pluginDir.'conf/cat_ueber-uns.conf.php',
            callPluginMethod($plugin, 'getScopeConfFile', ['category', 'ueber-uns', null])
        );
        $this->assertSame(
            $this->pluginDir.'conf/page_ueber-uns_team.conf.php',
            callPluginMethod($plugin, 'getScopeConfFile', ['page', 'ueber-uns', 'team'])
        );
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

        $file = callPluginMethod($plugin, 'getScopeConfFile', ['category', $sanitized, null]);
        $this->assertSame($this->pluginDir.'conf/cat_etcpasswd.conf.php', $file);
        $this->assertStringStartsWith($this->pluginDir.'conf', $file);
    }
}
