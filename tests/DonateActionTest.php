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
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
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
    private function buildDecoded(\schemaOrgData $plugin, string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $script = callPluginMethod($plugin, 'buildJsonLdScript', [$type, $data, $nodeId, $suppressedIdTargets]);
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
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);

        $this->assertIsArray($schema, 'DonateAction.json muss ladbar sein');
        $this->assertSame('DonateAction', $schema['title']);
        $this->assertContains('page', $schema['ui:scopes']);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertArrayHasKey('recipient', $schema['properties']);
    }

    function testDonateActionRecipientIsIdReferenceWidget(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);
        $recipient = $schema['properties']['recipient'];

        $this->assertSame('id_reference', $recipient['ui:widget']);
        $this->assertSame('organization', $recipient['ui:idTarget']);
        $this->assertTrue((bool) ($recipient['ui:required'] ?? false));
    }

    function testDonateActionDescriptionIsTextareaAndOptional(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);
        $description = $schema['properties']['description'];

        $this->assertSame('textarea', $description['ui:widget']);
        $this->assertFalse((bool) ($description['ui:required'] ?? false));
    }

    // -----------------------------------------------------------
    // buildJsonLdScript(): id_reference-Emission
    // -----------------------------------------------------------

    function testIdReferenceEmitsAtIdObjectWhenHostIsSet(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        // Leere data: recipient ist kein gespeichertes Feld,
        // wird aber trotzdem aus dem Schema emittiert.
        $decoded = $this->buildDecoded($plugin, 'DonateAction', []);

        $this->assertArrayHasKey('recipient', $decoded,
            'id_reference-Property muss zur Build-Zeit emittiert werden');
        $this->assertArrayHasKey('@id', $decoded['recipient'],
            'Emittierter Wert muss {"@id": "..."} sein');
        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    function testIdReferenceInsertedAfterEmptyPropertyFilter(): void {
        // Regressionsschutz analog NGO-Test aus 0.4.9-beta:
        // id_reference muss NACH removeEmptyJsonLdProperties() eingefügt werden,
        // damit auch bei leerem $data-Array die Emission erfolgt.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $decoded = $this->buildDecoded($plugin, 'DonateAction', [
            'description' => '',   // Wird durch removeEmptyJsonLdProperties entfernt
        ]);

        $this->assertArrayNotHasKey('description', $decoded,
            'Leere description muss durch den Leerfilter entfernt werden');
        $this->assertArrayHasKey('recipient', $decoded,
            'id_reference-Emission muss trotz leerem data-Array erfolgen');
        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    function testIdReferenceNotEmittedWhenHostIsAbsent(): void {
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $decoded = $this->buildDecoded($plugin, 'DonateAction', []);

        // Ohne Host kann keine @id-URI gebildet werden → kein recipient.
        $this->assertArrayNotHasKey('recipient', $decoded);
    }

    function testIdReferenceNotEmittedWhenTargetSuppressed(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        // 'organization' ist in der Suppressionsliste (keep-Modus-Szenario).
        $decoded = $this->buildDecoded($plugin, 'DonateAction', [], '', ['organization']);

        $this->assertArrayNotHasKey('recipient', $decoded,
            'id_reference darf bei suppressedIdTargets nicht emittiert werden');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard(): Guard-Verhalten
    // -----------------------------------------------------------

    function testGuardIsNoopWhenTargetNodeAlreadyInGraph(): void {
        $plugin = $this->createPlugin();

        // NGO (mit ui:idFragment "organization") ist regulär im globalen Scope.
        // DonateAction ist im Seiten-Scope (simuliert).
        $scopeConfigs = [
            'global' => [
                'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://example.org'],
            ],
            'page' => [
                'DonateAction' => [],
            ],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        // No-op: globaler Scope unverändert, kein Stub eingefügt.
        $this->assertSame($scopeConfigs, $result);
        $this->assertSame([], $suppressed);
    }

    function testGuardCreatesStubWhenTargetMissingDueToExcludedCats(): void {
        $plugin = $this->createPlugin();

        // Global-Scope durch excluded_cats entfernt (nur DonateAction im page-Scope).
        // Globale Konfiguration muss über loadScopeConfig() lesbar sein.
        $this->settings->set('config_global', [
            'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://example.org'],
        ]);

        $scopeConfigs = [
            'page' => [
                'DonateAction' => [],
            ],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        // Stub muss in global['NGO'] eingefügt worden sein.
        $this->assertArrayHasKey('global', $result,
            'Guard muss global scope mit Stub anlegen');
        $this->assertArrayHasKey('NGO', $result['global'],
            'Stub muss den Org-Type (NGO) enthalten');
        $this->assertSame('Beispiel e. V.', $result['global']['NGO']['name'],
            'Stub muss name aus der gespeicherten globalen Konfiguration übernehmen');
        $this->assertSame([], $suppressed,
            'Bei Stub-Erzwingung darf suppressed leer sein');
    }

    function testGuardSuppressesIdReferenceWhenGlobalKeptByKeep(): void {
        $plugin = $this->createPlugin();

        // Global durch keep-Modus unterdrückt (keine globale Ausgabe gewünscht).
        $scopeConfigs = [
            'page' => [
                'DonateAction' => [],
            ],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, true]);

        // Kein Stub, stattdessen 'organization' in suppressedIdTargets.
        $this->assertArrayNotHasKey('global', $result,
            'Bei keep-Modus darf kein Stub-Scope angelegt werden');
        $this->assertContains('organization', $suppressed,
            'Bei keep-Modus muss das Target in suppressedIdTargets stehen');
    }

    function testGuardStubGoesthroughDeDupGuardWhenRendered(): void {
        // Wenn der Guard einen Stub einfügt und der Stub anschließend über
        // resolveNodeId() ausgegeben wird, darf das Fragment nur einmal
        // vergeben werden (De-Dup-Guard von resolveNodeId()).
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();

        // Stub manuell in scopeConfigs anlegen (Guard-Ausgabe simulieren).
        $stubScopeConfigs = [
            'global' => [
                'NGO' => ['name' => 'Beispiel e. V.'],
            ],
        ];

        // Erster resolveNodeId-Aufruf: Fragment 'organization' wird vergeben.
        $assigned = [];
        $first = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);

        $this->assertSame('https://www.example.org/#organization', $first);

        // Zweiter Aufruf mit demselben Fragment: De-Dup-Guard greift.
        $second = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);
        $this->assertSame('', $second);
        $this->assertCount(1, $assigned, 'Fragment darf nur einmal in assignedFragments stehen');
    }

    function testGuardIsNoopWhenNoDonateActionInScope(): void {
        $plugin = $this->createPlugin();

        // Keine id_reference-Types in den Scopes: Guard darf nichts verändern.
        $scopeConfigs = [
            'global' => [
                'LocalBusiness' => ['name' => 'Beispiel GmbH'],
            ],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        $this->assertSame($scopeConfigs, $result);
        $this->assertSame([], $suppressed);
    }

    function testGuardDoesNotAddDuplicateNodeWhenTargetPresent(): void {
        $plugin = $this->createPlugin();

        // NGO ist regulär im globalen Scope vorhanden.
        $scopeConfigs = [
            'global' => [
                'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://example.org'],
            ],
            'page' => [
                'DonateAction' => [],
            ],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        // Global-Scope muss unverändert sein (kein zusätzlicher NGO-Eintrag).
        $this->assertCount(1, $result['global'],
            'Guard darf keine doppelten Knoten einfügen');
        $this->assertArrayHasKey('NGO', $result['global']);
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

        $result = callPluginMethod($plugin, 'validateAgainstSchema', [[], $schema]);

        $this->assertNotContains('recipient', $result['errors'],
            'id_reference darf nicht als fehlende Pflichtangabe gemeldet werden');
    }

    function testValidateAgainstSchemaStillEnforcesNormalRequired(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);

        // description ist optional, kein Fehler erwartet.
        $result = callPluginMethod($plugin, 'validateAgainstSchema', [[], $schema]);

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
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);

        $html = callPluginMethod($plugin, 'renderField', [
            'page', 'recipient', $schema['properties']['recipient'], null, $schema, [],
        ]);

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
        $schema = callPluginMethod($plugin, 'loadSchema', ['DonateAction']);

        $html = callPluginMethod($plugin, 'renderField', [
            'page', 'recipient', $schema['properties']['recipient'], null, $schema, [],
        ]);

        // Ohne Host: Fallback-URI "#organization" anzeigen.
        $this->assertStringContainsString('#organization', $html);
    }
}
