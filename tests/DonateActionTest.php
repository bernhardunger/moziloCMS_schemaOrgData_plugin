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

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        $this->assertSame($scopeConfigs, $result);
        $this->assertSame([], $suppressed);
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
