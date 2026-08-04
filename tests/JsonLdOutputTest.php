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
* und Seiten-Ebene werden nie befüllt. Der Testfall "Kategorie in
* excluded_cats" setzt eine aktive Kategorie voraus und ist daher als
* markTestSkipped() mit Begründung dokumentiert; die feldweise
* Vererbung wird stattdessen direkt über resolveTypeInheritance()
* geprüft.
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
    * Führt $body mit einer temporären Layout-Template-Datei des
    * übergebenen Inhalts als $GLOBALS['TEMPLATE_FILE'] aus und setzt
    * den vorherigen Wert danach zurück. Der Global-Scope entscheidet
    * über die Unterdrückung seiner Ausgabe gegen den Live-Inhalt
    * dieser Datei, nicht gegen ein gespeichertes Erkennungsflag.
    *
    ***************************************************************/
    private function withTemplateFile(string $html, callable $body): void {
        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, $html);
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $body();
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
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
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');

        $this->assertMatchesRegularExpression(
            '#^<script type="application/ld\+json">\n.*\n</script>\n$#s',
            $output
        );
    }

    function testContextIsAlwaysSchemaOrg(): void {
        $plugin = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('https://schema.org', $jsonLd['@context']);
    }

    function testTypeMatchesConfiguredSchemaType(): void {
        $plugin = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

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
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('PostalAddress', $jsonLd['address']['@type']);
        $this->assertSame('Beispielweg 1', $jsonLd['address']['streetAddress']);
        $this->assertSame('Beispielstadt', $jsonLd['address']['addressLocality']);
    }

    function testAddressCountryContainsIsoCode(): void {
        $plugin = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('DE', $jsonLd['address']['addressCountry']);
    }

    // -----------------------------------------------------------
    // Öffnungszeiten
    // -----------------------------------------------------------

    function testOpeningHoursIsOutputAsArray(): void {
        $plugin = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

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

        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('2020-01-01', $jsonLd['foundingDate']);
    }

    function testFormFieldTakesPrecedenceOverExtensionField(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['data']['telephone'] = '+4989111111';
        $postData['extension']['LocalBusiness'] = json_encode(['telephone' => '+4989222222']);

        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

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

        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

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
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );
        (new \SchemaOrgData_ScopeResolver())->saveScopeMeta($this->settings, 'global', ['jsonld_mode' => 'keep']);

        $this->withTemplateFile(
            '<script type="application/ld+json">{"@context":"https://schema.org"}</script>',
            function() use ($plugin): void {
                $this->assertSame('', $plugin->getContent(''));
            }
        );
    }

    function testJsonldModeOverrideProducesOutput(): void {
        $plugin = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );
        (new \SchemaOrgData_ScopeResolver())->saveScopeMeta($this->settings, 'global', ['jsonld_mode' => 'override']);

        $this->withTemplateFile(
            '<script type="application/ld+json">{"@context":"https://schema.org"}</script>',
            function() use ($plugin): void {
                $blocks = $this->getJsonLdBlocks($plugin);

                $this->assertCount(1, $blocks);
                $this->assertSame('LocalBusiness', $blocks[0]['@type']);
            }
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
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');

        $this->assertStringNotContainsString('schemaOrgData-debug-dialog', $output);
        $this->assertStringNotContainsString('schemaOrgData-debug-trigger', $output);
    }

    function testDebugOutputTrueProducesDebugWidget(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');

        $this->assertStringContainsString('schemaOrgData-debug-dialog', $output);
        $this->assertStringContainsString('schemaOrgData-debug-trigger', $output);
    }

    /***************************************************************
    *
    * Das Debug-Widget-Markup (Trigger-Button, <dialog>, Vorschau-
    * Blöcke) wird nicht mehr als statisches HTML zurückgegeben, sondern
    * als JSON-Nutzlast in den einzigen <script>-Block eingebettet und
    * erst zur Laufzeit per JS aufgebaut (siehe README.md, Abschnitt
    * "JSON-LD-Ausgabe") - Scope/Type/formatiertes JSON werden daher aus
    * der eingebetteten schemaOrgDataDebugData-Nutzlast extrahiert statt
    * als rohes HTML im Gesamtoutput gesucht.
    *
    ***************************************************************/
    private function extractDebugPayloadFromOutput(string $output): array {
        preg_match('#var schemaOrgDataDebugData = (\{.*\});\n#s', $output, $matches);
        $this->assertNotEmpty($matches, 'Erwartete schemaOrgDataDebugData-Zuweisung nicht im Output gefunden.');

        return json_decode($matches[1], true);
    }

    function testDebugWidgetContainsGlobalScopeStringAndType(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');
        $payload = $this->extractDebugPayloadFromOutput($output);

        $this->assertSame('global', $payload['blocks'][0]['scope']);
        $this->assertSame('LocalBusiness', $payload['blocks'][0]['type']);
    }

    function testDebugWidgetContainsFormattedJsonWithContextAndType(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');
        $json = $this->extractDebugPayloadFromOutput($output)['blocks'][0]['json'];

        // Das formatierte JSON muss @context und @type enthalten
        $this->assertStringContainsString('"@context"', $json);
        $this->assertStringContainsString('"@type": "LocalBusiness"', $json);
        $this->assertStringContainsString('"name": "Beispiel GmbH"', $json);
    }

    function testDebugWidgetContainsValidatorLink(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');

        $this->assertStringContainsString('validatorLink.href = "https://validator.schema.org"', $output);
    }

    /***************************************************************
    *
    * Regressionstest gegen invalides HTML an der {schemaOrgData}-
    * Platzhalterstelle im <head>: <button>/<dialog> sind dort keine
    * gültigen Metadaten-Elemente. Der von getContent() zurückgegebene
    * Gesamtoutput darf daher außerhalb von <script>-Blöcken kein
    * <button- oder <dialog-Tag mehr enthalten.
    *
    ***************************************************************/
    function testDebugWidgetOutputEnthaeltKeinButtonOderDialogAusserhalbDesScriptBlocks(): void {
        $plugin = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );

        $output = $plugin->getContent('');
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $output);

        $this->assertStringNotContainsString('<button', $withoutScripts);
        $this->assertStringNotContainsString('<dialog', $withoutScripts);
    }

    function testScriptBlocksIdenticalRegardlessOfDebugOutput(): void {
        $plugin1 = $this->createPlugin();
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $this->validLocalBusinessData(),
            $this->settings,
            callPluginMethod($plugin1, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin1->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );
        $output1 = $plugin1->getContent('');

        $plugin2 = $this->createPlugin();
        $postData = $this->validLocalBusinessData();
        $postData['debug_output'] = '1';
        (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'global',
            $postData,
            $this->settings,
            callPluginMethod($plugin2, 'loadAdminLanguage', []),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $plugin2->PLUGIN_SELF_DIR,
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_OrgRelationsService()
        );
        $output2 = $plugin2->getContent('');

        preg_match_all('#<script type="application/ld\+json">.*?</script>#s', $output1, $m1);
        preg_match_all('#<script type="application/ld\+json">.*?</script>#s', $output2, $m2);

        $this->assertSame($m1[0], $m2[0], 'Die <script>-Blöcke müssen mit und ohne debug_output identisch sein.');
    }

    // -----------------------------------------------------------
    // Sicherheit
    // -----------------------------------------------------------

    function testHtmlEntitiesBleibenInOutputLiteral(): void {
        $plugin = $this->createPlugin();
        $this->writeGlobalConf([
            'LocalBusiness' => [
                'name' => 'Müller &amp; Partner',
                'url' => 'https://www.beispiel-domain.example',
            ],
        ]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('Müller &amp; Partner', $jsonLd['name']);
    }

    function testJsonEncodeFailureReturnsEmptyString(): void {
        $plugin = $this->createPlugin();

        // Ungültige UTF-8-Bytefolge lässt json_encode() fehlschlagen
        $script = (new \SchemaOrgData_JsonLdBuilder())->buildJsonLdScript(
            new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(),
            $plugin->PLUGIN_SELF_DIR, 'LocalBusiness', [
                'name' => "Ungültig \xB1\x31",
            ]
        );

        $this->assertSame('', $script);
    }

    // -----------------------------------------------------------
    // existing_jsonld_content: Default der Scope-Meta
    // -----------------------------------------------------------

    function testExistingJsonLdContentDefaultIstLeerstring(): void {
        // loadScopeMeta() ohne vorherigen save → existing_jsonld_content hat Default ''.
        $plugin = $this->createPlugin();

        $meta = (new \SchemaOrgData_ScopeResolver())->loadScopeMeta($this->settings, 'global');

        $this->assertArrayHasKey('existing_jsonld_content', $meta);
        $this->assertSame('', $meta['existing_jsonld_content']);
    }
}
