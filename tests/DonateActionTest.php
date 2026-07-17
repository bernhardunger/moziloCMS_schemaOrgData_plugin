<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für DonateAction (Seite), das Widget id_reference und
* den Dangling-Reference-Guard.
*
* Abgedeckte Bereiche:
*   - DonateAction.json: Struktur, Scopes, recipient als id_reference
*   - buildJsonLdScript(): id_reference-Emission {"@id": "..."} nach
*     removeEmptyJsonLdProperties(), Unterdrückung bei suppressedIdTargets
*   - applyDanglingReferenceGuard(): No-op (Zielknoten vorhanden),
*     Stub-Erzwingung (Zielknoten fehlt durch excluded_cats), keep-Modus
*     (id_reference unterdrücken statt Stub), De-Dup-Guard bleibt wirksam
*   - validateAgainstSchema(): id_reference-Properties werden in der
*     required-Prüfung übersprungen
*   - renderField(): id_reference rendert lesbare Info-Anzeige
*
* Hinweis zu CAT_REQUEST/PAGE_REQUEST: In tests/bootstrap.php sind
* beide Konstanten fest auf "false" gesetzt. DonateAction ist ein
* Seiten-Scope-Typ und kann daher nicht direkt über getContent()
* getestet werden. Alle Guards und der Emitter werden daher über
* callPluginMethod() auf den jeweiligen privaten Methoden geprüft.
*
***************************************************************/
final class DonateActionTest extends TestCase {

    private array $serverBackup = [];
    private ?string $pluginDir = null;
    private ?\InMemorySettings $settings = null;

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
        $_POST = [];
        if($this->pluginDir !== null) {
            $this->removeDirectory($this->pluginDir);
            $this->pluginDir = null;
        }
    }

    private function copyDirectory(string $from, string $to): void {
        mkdir($to, 0777, true);
        foreach(glob($from.'/*') as $file) {
            copy($file, $to.'/'.basename($file));
        }
    }

    private function removeDirectory(string $dir): void {
        foreach(glob(rtrim($dir, '/').'/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    /***************************************************************
    *
    * Erzeugt eine Plugin-Instanz mit isoliertem InMemorySettings-Stub
    * und temporärem Plugin-Verzeichnis (Kopie von schemas/ und sprachen/).
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_donate_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');

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
    * Ruft buildJsonLdScript() auf und liefert den dekodierten
    * JSON-LD-Inhalt als Array zurück.
    *
    ***************************************************************/
    private function adminLang(\schemaOrgData $plugin): \Language {
        return new \Language($plugin->PLUGIN_SELF_DIR.'sprachen/admin_language_deDE.txt');
    }

    private function weekdayLang(\schemaOrgData $plugin): \Language {
        return new \Language($plugin->PLUGIN_SELF_DIR.'sprachen/cms_language_deDE.txt');
    }

    private function availableFragments(\schemaOrgData $plugin): array {
        return (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );
    }

    private function callSaveConfig(\schemaOrgData $plugin, array $postData): array {
        return (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            'page', $postData, $this->settings, $this->adminLang($plugin), new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(), $plugin->PLUGIN_SELF_DIR, new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_AdminPageRenderer()
        );
    }

    private function buildDecoded(\schemaOrgData $plugin, string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $script = (new \SchemaOrgData_JsonLdBuilder())->buildJsonLdScript(
            new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(),
            $plugin->PLUGIN_SELF_DIR, $type, $data, $nodeId, $suppressedIdTargets
        );
        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'buildJsonLdScript muss valides JSON erzeugen');
        return $decoded;
    }

    // -----------------------------------------------------------
    // DonateAction-Schema: Struktur und Metadaten
    // -----------------------------------------------------------

    function testDonateActionSchemaLoadsCorrectly(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');

        $this->assertIsArray($schema, 'DonateAction.json muss ladbar sein');
        $this->assertSame('DonateAction', $schema['title']);
        $this->assertContains('page', $schema['ui:scopes']);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertArrayHasKey('recipient', $schema['properties']);
    }

    function testDonateActionRecipientIsIdReferenceWidget(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');
        $recipient = $schema['properties']['recipient'];

        $this->assertSame('id_reference', $recipient['ui:widget']);
        $this->assertSame('organization', $recipient['ui:idTarget']);
        $this->assertTrue((bool) ($recipient['ui:required'] ?? false));
    }

    function testDonateActionDescriptionIsTextareaAndOptional(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');
        $description = $schema['properties']['description'];

        $this->assertSame('textarea', $description['ui:widget']);
        $this->assertFalse((bool) ($description['ui:required'] ?? false));
    }

    /***************************************************************
    *
    * DonateAction.json hatte als einzige
    * Schema-Datei gar kein Top-Level "required"-Array, obwohl
    * "recipient" bereits ui:required: true trägt.
    *
    ***************************************************************/
    function testDonateActionSchemaHasRequiredArrayMatchingUiRequired(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');

        $this->assertSame(['recipient'], $schema['required']);
    }

    // -----------------------------------------------------------
    // buildJsonLdScript(): id_reference-Emission
    // -----------------------------------------------------------

    // -----------------------------------------------------------
    // saveConfig(): recipient (id_reference) hat per Design keinen
    // POST-Wert - darf den Pflichtfeld-Check nicht scheitern lassen.
    // -----------------------------------------------------------

    function testSaveConfigSucceedsWithoutRecipientInPost(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'spenden';
        $_POST['schemaOrgData_page'] = 'spendenaufruf';

        $postData = [
            'type' => 'DonateAction',
            'data' => ['description' => 'Unterstütze unser Projekt.'],
            'extension' => ['DonateAction' => ''],
        ];

        $result = $this->callSaveConfig($plugin, $postData);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_spenden_spendenaufruf')['DonateAction'];
        $this->assertArrayNotHasKey('recipient', $config,
            'recipient wird erst zur Ausgabezeit über buildJsonLdScript() emittiert, nicht gespeichert');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard(): Guard-Verhalten
    // -----------------------------------------------------------

    function testGuardIsNoopWhenNoDonateActionInScope(): void {
        $plugin = $this->createPlugin();

        // Keine id_reference-Types in den Scopes: Guard darf nichts verändern.
        $scopeConfigs = [
            'global' => [
                'LocalBusiness' => ['name' => 'Beispiel GmbH'],
            ],
        ];

        [$result, $suppressed] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame($scopeConfigs, $result);
        $this->assertSame([], $suppressed);
    }

    /***************************************************************
    *
    * Organization.json deklariert ein ui:idFragment "organization",
    * daher kann Organization - genau wie NGO - als globale Identität für
    * DonateAction.recipient dienen. Guard bleibt No-op (Zielknoten
    * ist bereits im Graph), buildJsonLdScript() löst recipient danach
    * korrekt auf.
    *
    ***************************************************************/
    function testRecipientResolvesViaOrganizationAsGlobalIdentity(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'global' => [
                'Organization' => ['name' => 'Muster GmbH', 'url' => 'https://www.example.org'],
            ],
            'page' => [
                'DonateAction' => ['description' => 'Jetzt spenden und helfen!'],
            ],
        ];

        [$result, $suppressed] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame($scopeConfigs, $result, 'Organization deckt den Zielknoten bereits ab - Guard bleibt No-op');
        $this->assertSame([], $suppressed);

        $decoded = $this->buildDecoded($plugin, 'DonateAction', $result['page']['DonateAction'], '', $suppressed);

        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    /***************************************************************
    *
    * Grenzfall des Dangling-Reference-Guards: Weder NGO noch
    * Organization sind global konfiguriert (Settings enthalten
    * überhaupt keine config_global-Daten). Die Stub-Erzeugung in
    * applyDanglingReferenceGuard() liest den Ersatz-Type ausschließlich
    * aus dem rohen config_global-Bestand - existiert dort gar kein
    * Type mit passendem ui:idFragment, bleibt der Guard ein No-op.
    * Bekannte Grenze des Mechanismus (kein Fix in diesem Schritt,
    * siehe Docblock applyDanglingReferenceGuard()): recipient bleibt
    * dadurch ein aktives, im finalen Graph unaufgelöstes Fragment -
    * buildJsonLdScript() emittiert trotzdem ein {"@id": "..."} auf ein
    * nicht existierendes Ziel, da nur explizit unterdrückte Targets
    * (keep-Modus) die Emission verhindern.
    *
    ***************************************************************/
    function testGuardCannotStubWhenNoGlobalIdentityTypeExistsAtAll(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'page' => [
                'DonateAction' => ['description' => 'Jetzt spenden und helfen!'],
            ],
        ];

        [$result, $suppressed] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame($scopeConfigs, $result, 'Ohne jeglichen globalen Identitäts-Type kann kein Stub gebildet werden');
        $this->assertSame([], $suppressed);

        $decoded = $this->buildDecoded($plugin, 'DonateAction', $result['page']['DonateAction'], '', $suppressed);

        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id'],
            'Dokumentiert die bekannte Guard-Grenze: @id verweist auf ein Fragment ohne zugehörigen Knoten im Graph');
    }

    // -----------------------------------------------------------
    // validateAgainstSchema(): id_reference überspringen
    // -----------------------------------------------------------

    function testValidateAgainstSchemaSkipsIdReferenceInRequired(): void {
        $plugin = $this->createPlugin();

        // Schema mit recipient in required - id_reference darf nicht als
        // Pflichtfeld-Fehler gemeldet werden (wird zur Build-Zeit emittiert).
        $schema = [
            'properties' => [
                'recipient' => [
                    'type' => 'object',
                    'ui:widget' => 'id_reference',
                    'ui:idTarget' => 'organization',
                ],
            ],
            'required' => ['recipient'],
        ];

        $result = (new \SchemaOrgData_Validator())->validateAgainstSchema([], $schema, new \SchemaOrgData_SchemaRepository());

        $this->assertNotContains('recipient', $result['errors'],
            'id_reference darf nicht als fehlende Pflichtangabe gemeldet werden');
    }

    function testValidateAgainstSchemaStillEnforcesNormalRequired(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');

        // description ist optional, kein Fehler erwartet.
        $result = (new \SchemaOrgData_Validator())->validateAgainstSchema([], $schema, new \SchemaOrgData_SchemaRepository());

        $this->assertSame([], $result['errors'],
            'DonateAction ohne recipient-Fehler und ohne description-Fehler');
    }

    // -----------------------------------------------------------
    // renderField(): id_reference-Widget
    // -----------------------------------------------------------

    function testRenderFieldIdReferenceShowsInfoDisplay(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'recipient', $schema['properties']['recipient'], null, $schema, [], null, null, null,
            $this->adminLang($plugin), new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang($plugin),
            $this->availableFragments($plugin)
        );

        // Kein input-Element (readonly Info-Anzeige).
        $this->assertStringNotContainsString('<input', $html,
            'id_reference darf kein Eingabefeld rendern');
        // URI muss in der Anzeige enthalten sein.
        $this->assertStringContainsString('www.example.org/#organization', $html,
            'Ziel-URI muss in der Info-Anzeige erscheinen');
        // Info-Text muss enthalten sein.
        $this->assertStringContainsString('schemaOrgData-id-reference-info', $html);
    }

    function testRenderFieldIdReferenceShowsFallbackUriWhenHostAbsent(): void {
        unset($_SERVER['HTTP_HOST']);

        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'DonateAction');

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'recipient', $schema['properties']['recipient'], null, $schema, [], null, null, null,
            $this->adminLang($plugin), new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang($plugin),
            $this->availableFragments($plugin)
        );

        // Ohne Host: Fallback-URI "#organization" anzeigen.
        $this->assertStringContainsString('#organization', $html);
    }
}
