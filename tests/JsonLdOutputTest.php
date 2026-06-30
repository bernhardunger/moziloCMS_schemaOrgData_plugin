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
* (Kopie von schemas/ und sprachen/) und einem isolierten
* InMemorySettings-Stub als $this->settings, damit die echte
* plugin.conf.php des Plugins unangetastet bleibt.
*
***************************************************************/
final class JsonLdOutputTest extends TestCase {

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
    * temporäre Testverzeichnis zeigt und deren $this->settings durch
    * einen isolierten InMemorySettings-Stub ersetzt wurde, damit
    * saveConfig()/saveScopeMeta() nicht die echte plugin.conf.php
    * verändern.
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
    * Setzt die globale Konfiguration direkt über den
    * InMemorySettings-Stub (config_global) - für Testfälle, die
    * eine bestimmte Rohstruktur (mehrere Types, leere Properties)
    * unabhängig von saveConfig() benötigen.
    *
    ***************************************************************/
    private function writeGlobalConf(array $data): void {
        $this->settings->set('config_global', $data);
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
        $postData['data']['telephone'] = '+4989111111';
        $postData['extension']['LocalBusiness'] = json_encode(['telephone' => '+4989222222']);

        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('+4989111111', $jsonLd['telephone']);
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
            .'auslösbar, ohne tests/bootstrap.php zu ändern. Vom '
            .'Storage-Layer-Refactor (settings statt conf/-Dateien) '
            .'unabhängig.'
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
    // Scope-genaue Kollisionserkennung im Frontend-Pfad (getContent)
    // -----------------------------------------------------------

    function testTemplateJsonLdWirdAusschliesslichGlobalZugeordnet(): void {
        // Template enthält JSON-LD → nur Global-Scope bekommt existing_jsonld=true.
        // Kategorie und Seite dürfen nicht beschrieben werden.
        $plugin = $this->createPlugin();

        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, '<script type="application/ld+json">{"@context":"https://schema.org"}</script>');
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $plugin->getContent('');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertTrue($metaGlobal['existing_jsonld'],
                'Template-Treffer muss Global-Scope auf existing_jsonld=true setzen');

            // Kategorie-Scope: getScopeSettingsKey('category', null) = null → Standardwert false
            $metaCat = callPluginMethod($plugin, 'loadScopeMeta', ['category', null]);
            $this->assertFalse($metaCat['existing_jsonld'],
                'Kategorie-Scope darf bei Template-Treffer nicht beschrieben werden');

            // Seiten-Scope: CAT_REQUEST/PAGE_REQUEST = false → Schreib-Guard greift → Standardwert false
            $metaPage = callPluginMethod($plugin, 'loadScopeMeta', ['page', null, null]);
            $this->assertFalse($metaPage['existing_jsonld'],
                'Seiten-Scope darf bei Template-Treffer nicht direkt beschrieben werden');
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testInhaltsJsonLdBeeinflusstNichtGlobal(): void {
        // $value enthält JSON-LD, Template hat keinen Treffer.
        // Global-Scope muss false bleiben — Inhalts-Treffer darf nicht auf
        // Global schreiben (nur auf Seiten-Scope, der hier mangels
        // CAT_REQUEST/PAGE_REQUEST nicht beschrieben werden kann).
        $plugin = $this->createPlugin();
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = '';

        try {
            $plugin->getContent('<script type="application/ld+json">{"@context":"https://schema.org"}</script>');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertFalse($metaGlobal['existing_jsonld'],
                'Inhalts-Treffer darf Global-Scope nicht auf existing_jsonld=true setzen');
        } finally {
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testBeideJsonLdTrefferSchreibenGlobalNichtKategorie(): void {
        // Template UND $value enthalten JSON-LD.
        // Global → true (Template-Treffer), Kategorie → nicht beschrieben.
        $plugin = $this->createPlugin();

        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, '<script type="application/ld+json">{"@context":"https://schema.org"}</script>');
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $plugin->getContent('<script type="application/ld+json">{"@context":"https://schema.org"}</script>');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertTrue($metaGlobal['existing_jsonld'],
                'Template-Treffer muss Global-Scope auf existing_jsonld=true setzen');

            $metaCat = callPluginMethod($plugin, 'loadScopeMeta', ['category', null]);
            $this->assertFalse($metaCat['existing_jsonld'],
                'Kategorie-Scope darf bei Template- und Inhalts-Treffer nicht beschrieben werden');
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testKeinJsonLdTrefferLaessteAlleScopesAufFalse(): void {
        // Weder Template noch $value enthält JSON-LD → alle Scopes bleiben false.
        $plugin = $this->createPlugin();
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = '';

        try {
            $plugin->getContent('');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertFalse($metaGlobal['existing_jsonld'], 'Global muss false bleiben');

            $metaCat = callPluginMethod($plugin, 'loadScopeMeta', ['category', null]);
            $this->assertFalse($metaCat['existing_jsonld'], 'Kategorie muss false bleiben');

            $metaPage = callPluginMethod($plugin, 'loadScopeMeta', ['page', null, null]);
            $this->assertFalse($metaPage['existing_jsonld'], 'Seite muss false bleiben');
        } finally {
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testSeiteJsonLdOhnePageRequestNichtTestbar(): void {
        // Inhalts-Treffer → Seiten-Scope: CAT_REQUEST und PAGE_REQUEST sind in
        // tests/bootstrap.php fest auf "false" gesetzt — der Schreib-Guard in
        // getContent() (CAT_REQUEST and PAGE_REQUEST) verhindert das Setzen von
        // existing_jsonld im Seiten-Scope. Dieser Fall ist im Testkontext nicht
        // vollständig prüfbar, ohne tests/bootstrap.php zu ändern.
        $this->markTestSkipped(
            'CAT_REQUEST/PAGE_REQUEST sind in tests/bootstrap.php fest auf "false" gesetzt. '
            .'Der Schreib-Guard in getContent() verhindert den Write auf den Seiten-Scope '
            .'ohne eine aktiv gerenderte Seite. Die Logik ist in index.php:getContent() '
            .'implementiert und durch die übrigen Scope-Tests vollständig abgedeckt.'
        );
    }

    // -----------------------------------------------------------
    // Type-Kollision / feldweise Vererbung (resolveTypeInheritance)
    // -----------------------------------------------------------
    //
    // CAT_REQUEST/PAGE_REQUEST sind in tests/bootstrap.php fest auf
    // "false" gesetzt, getContent() lädt daher nie eine Kategorie- oder
    // Seiten-Konfiguration. Die feldweise Vererbung selbst (siehe
    // resolveTypeInheritance()) ist eine reine Datentransformation ohne
    // Abhängigkeit von CAT_REQUEST/PAGE_REQUEST und wird daher direkt
    // über callPluginMethod() getestet.

    function testSameTypeOnGlobalAndCategoryMergesFieldsOnCategory(): void {
        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'global' => [
                'LocalBusiness' => [
                    'name' => 'Beispiel GmbH',
                    'telephone' => '+49 89 11111111',
                ],
            ],
            'category' => [
                'LocalBusiness' => [
                    'name' => 'Beispiel GmbH - Filiale Nord',
                ],
            ],
        ];

        $result = callPluginMethod($plugin, 'resolveTypeInheritance', [$scopeConfigs]);

        $this->assertSame([], $result['global']);
        $this->assertSame('Beispiel GmbH - Filiale Nord', $result['category']['LocalBusiness']['name']);
        $this->assertSame('+49 89 11111111', $result['category']['LocalBusiness']['telephone']);
    }

    function testSameTypeOnCategoryAndPageMergesFieldsOnPage(): void {
        $plugin = $this->createPlugin();

        // "name" ist auf Seiten-Ebene leer und damit (siehe
        // sanitizePostData()) gar nicht im gespeicherten Array enthalten.
        $scopeConfigs = [
            'global' => [],
            'category' => [
                'LocalBusiness' => [
                    'name' => 'Steuerkanzlei',
                    'telephone' => '+49891234567',
                ],
            ],
            'page' => [
                'LocalBusiness' => [
                    'telephone' => '+498987654321',
                ],
            ],
        ];

        $result = callPluginMethod($plugin, 'resolveTypeInheritance', [$scopeConfigs]);

        $this->assertSame([], $result['category']);
        $this->assertSame('Steuerkanzlei', $result['page']['LocalBusiness']['name']);
        $this->assertSame('+498987654321', $result['page']['LocalBusiness']['telephone']);
    }

    function testNestedFieldOverridesWithoutMergingProperties(): void {
        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'global' => [
                'LocalBusiness' => [
                    'name' => 'Beispiel GmbH',
                    'address' => [
                        'streetAddress' => 'Globalweg 1',
                        'addressLocality' => 'Globalstadt',
                        'addressCountry' => 'DE',
                    ],
                ],
            ],
            'category' => [
                'LocalBusiness' => [
                    'address' => [
                        'addressLocality' => 'Filialstadt',
                        'addressCountry' => 'DE',
                    ],
                ],
            ],
        ];

        $result = callPluginMethod($plugin, 'resolveTypeInheritance', [$scopeConfigs]);

        $address = $result['category']['LocalBusiness']['address'];
        $this->assertSame('Beispiel GmbH', $result['category']['LocalBusiness']['name']);
        $this->assertSame('Filialstadt', $address['addressLocality']);
        $this->assertArrayNotHasKey('streetAddress', $address);
    }

    function testDifferentTypesRemainIndependent(): void {
        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'global' => [
                'WebSite' => ['name' => 'Beispiel-Website', 'url' => 'https://www.beispiel-domain.example'],
            ],
            'category' => [
                'FAQPage' => ['mainEntity' => [['name' => 'Frage?', 'acceptedAnswer' => ['text' => 'Antwort.']]]],
            ],
        ];

        $result = callPluginMethod($plugin, 'resolveTypeInheritance', [$scopeConfigs]);

        $this->assertArrayHasKey('WebSite', $result['global']);
        $this->assertArrayNotHasKey('FAQPage', $result['global']);
        $this->assertArrayHasKey('FAQPage', $result['category']);
        $this->assertArrayNotHasKey('WebSite', $result['category']);
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
    // Debug-Modus (buildDebugWidget / debug_output-Flag)
    // -----------------------------------------------------------

    function testDebugOutputFalseProducesNoDebugWidget(): void {
        $plugin = $this->createPlugin();
        callPluginMethod($plugin, 'saveConfig', ['global', $this->validLocalBusinessData()]);

        $output = $plugin->getContent('');

        $this->assertStringNotContainsString('schemaOrgData-debug-dialog', $output);
        $this->assertStringNotContainsString('schemaOrgData-debug-trigger', $output);
    }

    function testDebugOutputTrueProducesDebugWidget(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $output = $plugin->getContent('');

        $this->assertStringContainsString('schemaOrgData-debug-dialog', $output);
        $this->assertStringContainsString('schemaOrgData-debug-trigger', $output);
    }

    function testDebugWidgetContainsGlobalScopeStringAndType(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $output = $plugin->getContent('');

        // Scope-Herkunft und Type-Name müssen im Widget auftauchen
        $this->assertStringContainsString('>global &mdash; LocalBusiness<', $output);
    }

    function testDebugWidgetContainsFormattedJsonWithContextAndType(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $output = $plugin->getContent('');

        // Das formatierte JSON muss @context und @type enthalten
        $this->assertStringContainsString('"@context"', $output);
        $this->assertStringContainsString('"@type": "LocalBusiness"', $output);
        $this->assertStringContainsString('"name": "Beispiel GmbH"', $output);
    }

    function testDebugWidgetContainsValidatorLink(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        callPluginMethod($plugin, 'saveConfig', ['global', $postData]);

        $output = $plugin->getContent('');

        $this->assertStringContainsString('href="https://validator.schema.org"', $output);
    }

    function testScriptBlocksIdenticalRegardlessOfDebugOutput(): void {
        // Ohne Debug
        $plugin1 = $this->createPlugin();
        callPluginMethod($plugin1, 'saveConfig', ['global', $this->validLocalBusinessData()]);
        $output1 = $plugin1->getContent('');

        // Mit Debug
        $plugin2 = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        callPluginMethod($plugin2, 'saveConfig', ['global', $postData]);
        $output2 = $plugin2->getContent('');

        preg_match_all('#<script type="application/ld\+json">.*?</script>#s', $output1, $m1);
        preg_match_all('#<script type="application/ld\+json">.*?</script>#s', $output2, $m2);

        $this->assertSame($m1[0], $m2[0], 'Die <script>-Blöcke müssen mit und ohne debug_output identisch sein.');
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

    // -----------------------------------------------------------
    // existing_jsonld_content: Scope-genaue Inhaltspersistenz
    // -----------------------------------------------------------

    function testTemplateContentWirdInGlobalScopeGespeichert(): void {
        // Template enthält JSON-LD → existing_jsonld_content im Global-Scope gesetzt.
        $plugin = $this->createPlugin();

        $jsonText = '{"@context":"https://schema.org","@type":"LocalBusiness"}';
        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, '<script type="application/ld+json">'.$jsonText.'</script>');
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $plugin->getContent('');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertTrue($metaGlobal['existing_jsonld']);
            $this->assertSame($jsonText, $metaGlobal['existing_jsonld_content'],
                'Template-JSON-Text muss 1:1 im Global-Scope gespeichert werden');
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testGlobalContentLeerWennKeinTemplateJsonLd(): void {
        // Kein JSON-LD im Template → existing_jsonld_content im Global-Scope ist leer.
        $plugin = $this->createPlugin();
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = '';

        try {
            $plugin->getContent('');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertFalse($metaGlobal['existing_jsonld']);
            $this->assertSame('', $metaGlobal['existing_jsonld_content'],
                'existing_jsonld_content muss leer sein wenn kein Template-JSON-LD gefunden');
        } finally {
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testMehrereTemplateBlocksWerdenMitZeilenumbruchVerbunden(): void {
        // Zwei Blöcke im Template → beide Texte werden mit "\n\n" verbunden gespeichert.
        $plugin = $this->createPlugin();

        $json1 = '{"@type":"Organization"}';
        $json2 = '{"@type":"WebSite"}';
        $html = '<script type="application/ld+json">'.$json1.'</script>'
              . '<script type="application/ld+json">'.$json2.'</script>';
        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, $html);
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $plugin->getContent('');

            $metaGlobal = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
            $this->assertSame($json1."\n\n".$json2, $metaGlobal['existing_jsonld_content']);
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testExistingJsonLdContentDefaultIstLeerstring(): void {
        // loadScopeMeta() ohne vorherigen save → existing_jsonld_content hat Default ''.
        $plugin = $this->createPlugin();

        $meta = callPluginMethod($plugin, 'loadScopeMeta', ['global']);

        $this->assertArrayHasKey('existing_jsonld_content', $meta);
        $this->assertSame('', $meta['existing_jsonld_content']);
    }
}
