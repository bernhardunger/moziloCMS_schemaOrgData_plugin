<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die JSON-LD-Ausgabe: getContent() (Aufbau der
* <script type="application/ld+json">-Blöcke je Geltungsebene)
* sowie buildJsonLdScript() (Verschachtelung von PostalAddress/
* GeoCoordinates/Person, leere Properties, Sicherheit).
*
* Hinweis zu CAT_REQUEST/PAGE_REQUEST: In tests/bootstrap.php sind
* beide Konstanten fest auf "false" gesetzt (entspricht der
* Startseite ohne aktive Kategorie/Seite, siehe auch
* PersistenceTest.php). getContent() lädt in diesem Testkontext
* daher ausschließlich die globale Konfiguration - die Kategorie-
* und Seiten-Ebene werden nie befüllt. Testfälle, die eine aktive
* Kategorie bzw. Seite voraussetzen (Type-Kollision Global/Kategorie
* bzw. Kategorie/Seite sowie "Kategorie in excluded_cats"), sind
* daher als markTestSkipped() mit Begründung dokumentiert.
*
* Jeder Test arbeitet auf einem temporären Plugin-Verzeichnis
* (Kopie von schemas/ und sprachen/, leeres conf/-Verzeichnis),
* damit das echte plugins/schemaOrgData/conf/ unangetastet bleibt.
*
***************************************************************/
final class JsonLdOutputTest extends TestCase {

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
    * temporäre Testverzeichnis zeigt.
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $plugin = new \schemaOrgData();
        $plugin->PLUGIN_SELF_DIR = $this->pluginDir;
        return $plugin;
    }

    /***************************************************************
    *
    * Schreibt die globale conf-Datei direkt im Format
    * "<?php die(); ?>" + serialize() - für Testfälle, die eine
    * bestimmte Rohstruktur (mehrere Types, leere Properties)
    * unabhängig von saveConfig() benötigen.
    *
    ***************************************************************/
    private function writeGlobalConf(array $data): void {
        file_put_contents(
            $this->pluginDir.'conf/_global.conf.php',
            '<?php die(); ?>'."\n".serialize($data)
        );
    }

    /***************************************************************
    *
    * Ruft getContent() auf und liefert alle enthaltenen
    * JSON-LD-<script>-Blöcke dekodiert als Arrays zurück.
    *
    ***************************************************************/
    private function getJsonLdBlocks(\schemaOrgData $plugin): array {
        $output = $plugin->getContent('');

        preg_match_all('#<script type="application/ld\+json">\n(.*?)\n</script>\n#s', $output, $matches);

        return array_map(
            fn(string $json): mixed => json_decode($json, true),
            $matches[1]
        );
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness"
    * (Geltungsbereich global).
    *
    ***************************************************************/
    private function validLocalBusinessData(string $name = 'Beispiel GmbH'): array {
        return [
            'type' => 'LocalBusiness',
            'data' => [
                'name' => $name,
                'url' => 'https://www.beispiel-domain.example',
                'address' => [
                    'streetAddress' => 'Beispielweg 1',
                    'postalCode' => '54321',
                    'addressLocality' => 'Beispielstadt',
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

    // -----------------------------------------------------------
    // Grundausgabe
    // -----------------------------------------------------------

    function testConfiguredScopeProducesScriptBlock(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $output = $plugin->getContent('');

        $this->assertMatchesRegularExpression(
            '#^<script type="application/ld\+json">\n.*\n</script>\n$#s',
            $output
        );
    }

    function testContextIsAlwaysSchemaOrg(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('https://schema.org', $jsonLd['@context']);
    }

    function testTypeMatchesConfiguredSchemaType(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('LocalBusiness', $jsonLd['@type']);
    }

    function testEmptyPropertiesAreNotOutput(): void {
        $plugin = $this->createPlugin();
        $this->writeGlobalConf([
            'LocalBusiness' => [
                'name' => 'Beispiel GmbH',
                'url' => 'https://www.beispiel-domain.example',
                'description' => '',
                'telephone' => null,
                'openingHours' => [],
            ],
        ]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertArrayNotHasKey('description', $jsonLd);
        $this->assertArrayNotHasKey('telephone', $jsonLd);
        $this->assertArrayNotHasKey('openingHours', $jsonLd);
    }

    // -----------------------------------------------------------
    // PostalAddress
    // -----------------------------------------------------------

    function testAddressIsNestedAsPostalAddress(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('PostalAddress', $jsonLd['address']['@type']);
        $this->assertSame('Beispielweg 1', $jsonLd['address']['streetAddress']);
        $this->assertSame('Beispielstadt', $jsonLd['address']['addressLocality']);
    }

    function testAddressCountryContainsIsoCode(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('DE', $jsonLd['address']['addressCountry']);
    }

    // -----------------------------------------------------------
    // Öffnungszeiten
    // -----------------------------------------------------------

    function testOpeningHoursIsOutputAsArray(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame(['Mo-Fr 09:00-18:00'], $jsonLd['openingHours']);
    }

    // -----------------------------------------------------------
    // Erweiterungsfeld
    // -----------------------------------------------------------

    function testExtensionFieldPropertiesAppearInOutput(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['extension']['LocalBusiness'] = json_encode(['foundingDate' => '2020-01-01']);

        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('2020-01-01', $jsonLd['foundingDate']);
    }

    function testFormFieldTakesPrecedenceOverExtensionField(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['data']['priceRange'] = '€€';
        $postData['extension']['LocalBusiness'] = json_encode(['priceRange' => '€€€']);

        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('€€', $jsonLd['priceRange']);
    }

    // -----------------------------------------------------------
    // Ausschlussliste
    // -----------------------------------------------------------

    function testCategoryNotInExcludedCatsOutputsGlobalBlock(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['excluded_cats'] = ['impressum', 'datenschutz'];

        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $blocks = $this->getJsonLdBlocks($plugin);

        $this->assertCount(1, $blocks);
        $this->assertSame('LocalBusiness', $blocks[0]['@type']);
    }

    function testCategoryInExcludedCatsSuppressesGlobalBlock(): void {
        $this->markTestSkipped(
            'CAT_REQUEST ist in tests/bootstrap.php fest auf "false" gesetzt '
            .'(keine aktive Kategorie) und kann daher nie in excluded_cats '
            .'enthalten sein - dieser Fall ist im Testkontext nicht '
            .'auslösbar, ohne tests/bootstrap.php zu ändern.'
        );
    }

    // -----------------------------------------------------------
    // jsonld_mode
    // -----------------------------------------------------------

    function testJsonldModeKeepSuppressesOutput(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        callPluginMethod($plugin, 'saveScopeMeta', ['global', ['existing_jsonld' => true, 'jsonld_mode' => 'keep']]);

        $output = $plugin->getContent('');

        $this->assertSame('', $output);
    }

    function testJsonldModeOverrideProducesOutput(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        callPluginMethod($plugin, 'saveScopeMeta', ['global', ['existing_jsonld' => true, 'jsonld_mode' => 'override']]);

        $blocks = $this->getJsonLdBlocks($plugin);

        $this->assertCount(1, $blocks);
        $this->assertSame('LocalBusiness', $blocks[0]['@type']);
    }

    // -----------------------------------------------------------
    // Type-Kollision
    // -----------------------------------------------------------

    function testSameTypeOnGlobalAndCategoryOutputsOnlyCategory(): void {
        $this->markTestSkipped(
            'CAT_REQUEST ist in tests/bootstrap.php fest auf "false" gesetzt, '
            .'getContent() lädt daher nie eine Kategorie-Konfiguration - eine '
            .'Type-Kollision Global/Kategorie ist im Testkontext nicht '
            .'auslösbar, ohne tests/bootstrap.php zu ändern.'
        );
    }

    function testSameTypeOnCategoryAndPageOutputsOnlyPage(): void {
        $this->markTestSkipped(
            'CAT_REQUEST/PAGE_REQUEST sind in tests/bootstrap.php fest auf '
            .'"false" gesetzt, getContent() lädt daher nie eine Kategorie- '
            .'oder Seiten-Konfiguration - eine Type-Kollision Kategorie/'
            .'Seite ist im Testkontext nicht auslösbar, ohne '
            .'tests/bootstrap.php zu ändern.'
        );
    }

    function testDifferentTypesProduceBothBlocks(): void {
        $plugin = $this->createPlugin();
        $this->writeGlobalConf([
            'LocalBusiness' => [
                'name' => 'Beispiel GmbH',
                'url' => 'https://www.beispiel-domain.example',
            ],
            'WebSite' => [
                'name' => 'Beispiel-Website',
                'url' => 'https://www.beispiel-domain.example',
            ],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $types = array_column($blocks, '@type');

        $this->assertCount(2, $blocks);
        $this->assertContains('LocalBusiness', $types);
        $this->assertContains('WebSite', $types);
    }

    // -----------------------------------------------------------
    // Sicherheit
    // -----------------------------------------------------------

    function testHtmlEntitiesAreDecodedInOutput(): void {
        $plugin = $this->createPlugin();
        $this->writeGlobalConf([
            'LocalBusiness' => [
                'name' => 'Müller &amp; Partner',
                'url' => 'https://www.beispiel-domain.example',
            ],
        ]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('Müller & Partner', $jsonLd['name']);
    }

    function testJsonEncodeFailureReturnsEmptyString(): void {
        $plugin = $this->createPlugin();

        // Ungültige UTF-8-Bytefolge lässt json_encode() fehlschlagen
        $script = callPluginMethod($plugin, 'buildJsonLdScript', ['LocalBusiness', [
            'name' => "Ungültig \xB1\x31",
        ]]);

        $this->assertSame('', $script);
    }
}
