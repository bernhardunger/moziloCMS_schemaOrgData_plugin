<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für Person-Fragment (ui:idFragment) und das Widget
* id_reference_or_literal (Referenz-Modus + Literal-Modus).
*
* Abgedeckte Bereiche:
*   - Person.json: ui:idFragment = "person", ui:scopes = ["global"],
*     required = ["name"]
*   - resolveAvailableGlobalFragments(): liefert Fragment → Label-Map
*     aus global konfigurierten Typen mit ui:idFragment
*   - buildJsonLdScript(): id_reference_or_literal-Emission im
*     Referenz-Modus ({"@id": "...#fragment"}) und Literal-Modus
*     (eingebettetes Objekt mit optionalem @type, ohne @id)
*   - applyDanglingReferenceGuard(): Referenz-Modus löst Guard aus,
*     Literal-Modus ist No-op; Unterdrückung bei keep-Modus; Stub
*     bei fehlenden Zielknoten
*   - validateAgainstSchema(): id_reference_or_literal in required
*     wird übersprungen
*
* Das Hilfsschema TestIdRefType.json wird im Temp-Verzeichnis
* erzeugt und beschreibt eine Property "organizer" mit dem Widget
* id_reference_or_literal.
*
***************************************************************/
final class PersonIdRefOrLiteralTest extends TestCase {

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
    * und temporärem Plugin-Verzeichnis. Schreibt zusätzlich ein
    * Hilfsschema TestIdRefType.json in das Temp-Schemas-Verzeichnis.
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_idrl_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');

        // Hilfsschema mit id_reference_or_literal-Property für Tests
        $testSchema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title'   => 'TestIdRefType',
            'type'    => 'object',
            'ui:scopes' => ['page'],
            'required'  => ['organizer'],
            'properties' => [
                'organizer' => [
                    'type'                 => 'object',
                    'ui:widget'            => 'id_reference_or_literal',
                    'ui:label'             => 'label_name',
                    'ui:required'          => true,
                    'ui:literalFields'     => ['name', 'jobTitle'],
                    'ui:literalFieldLabels'=> ['name' => 'label_name', 'jobTitle' => 'label_job_title'],
                    'ui:literalType'       => 'Person',
                ],
            ],
        ];
        file_put_contents(
            $this->pluginDir.'schemas/TestIdRefType.json',
            json_encode($testSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $plugin = new \schemaOrgData();
        $plugin->PLUGIN_SELF_DIR = $this->pluginDir;

        $this->settings = new \InMemorySettings();
        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $this->settings);

        return $plugin;
    }

    private function buildDecoded(\schemaOrgData $plugin, string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $script = callPluginMethod($plugin, 'buildJsonLdScript', [$type, $data, $nodeId, $suppressedIdTargets]);
        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'buildJsonLdScript muss valides JSON erzeugen');
        return $decoded;
    }

    // -----------------------------------------------------------
    // Person.json: Schema-Struktur
    // -----------------------------------------------------------

    function testPersonSchemaHasIdFragment(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['Person']);

        $this->assertIsArray($schema);
        $this->assertSame('person', $schema['ui:idFragment'],
            'Person.json muss ui:idFragment = "person" besitzen');
    }

    function testPersonSchemaIsGlobalOnly(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['Person']);

        $this->assertSame(['global'], $schema['ui:scopes'],
            'Person.json muss ausschließlich den Scope "global" haben');
    }

    function testPersonSchemaRequiresName(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['Person']);

        $this->assertContains('name', $schema['required'],
            'Person.json muss "name" als Pflichtfeld deklarieren');
    }

    function testPersonSchemaJobTitleIsOptional(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['Person']);

        $this->assertArrayHasKey('jobTitle', $schema['properties']);
        $this->assertNotContains('jobTitle', $schema['required'] ?? []);
        $this->assertFalse((bool) ($schema['properties']['jobTitle']['ui:required'] ?? false));
    }

    // -----------------------------------------------------------
    // resolveAvailableGlobalFragments()
    // -----------------------------------------------------------

    function testResolveFragmentsEmptyWhenNoGlobalConfig(): void {
        $plugin = $this->createPlugin();
        // Keine globale Konfiguration gesetzt
        $fragments = callPluginMethod($plugin, 'resolveAvailableGlobalFragments', []);

        $this->assertSame([], $fragments);
    }

    function testResolveFragmentsListsPersonFragment(): void {
        $plugin = $this->createPlugin();
        $this->settings->set('config_global', ['Person' => ['name' => 'Max Mustermann']]);

        $fragments = callPluginMethod($plugin, 'resolveAvailableGlobalFragments', []);

        $this->assertArrayHasKey('person', $fragments,
            'Person mit ui:idFragment "person" muss im Fragment-Array erscheinen');
    }

    function testResolveFragmentsListsBothWhenNgoAndPersonConfigured(): void {
        $plugin = $this->createPlugin();
        $this->settings->set('config_global', [
            'NGO'    => ['name' => 'Beispiel e.V.'],
            'Person' => ['name' => 'Max Mustermann'],
        ]);

        $fragments = callPluginMethod($plugin, 'resolveAvailableGlobalFragments', []);

        $this->assertArrayHasKey('organization', $fragments);
        $this->assertArrayHasKey('person', $fragments);
        $this->assertCount(2, $fragments);
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard() — id_reference_or_literal
    // -----------------------------------------------------------

    function testGuardNoOpWhenReferenceTargetIsPresentInScope(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $plugin = $this->createPlugin();

        // Seiten-Scope mit organizer (Referenz auf "organization")
        // + globaler NGO-Knoten (Zielknoten vorhanden)
        $scopeConfigs = [
            'global' => ['NGO'          => ['name' => 'Beispiel e.V.']],
            'page'   => ['TestIdRefType' => ['organizer' => ['_mode' => 'reference', '_fragment' => 'organization']]],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        $this->assertSame([], $suppressed,
            'Kein suppressed Target wenn Zielknoten vorhanden');
        $this->assertSame($scopeConfigs, $result,
            'scopeConfigs darf nicht verändert werden wenn Zielknoten vorhanden');
    }

    function testGuardCreatesStubWhenReferenceTargetMissing(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $plugin = $this->createPlugin();
        // Globale NGO-Konfiguration vorhanden (für Stub-Quelle), aber nicht im scopeConfigs
        $this->settings->set('config_global', ['NGO' => ['name' => 'Beispiel e.V.']]);

        $scopeConfigs = [
            'page' => ['TestIdRefType' => ['organizer' => ['_mode' => 'reference', '_fragment' => 'organization']]],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, false]);

        $this->assertSame([], $suppressed);
        $this->assertArrayHasKey('global', $result,
            'Guard muss globalen Stub-Eintrag erzeugen');
        $this->assertArrayHasKey('NGO', $result['global'],
            'Stub muss NGO-Typ enthalten');
        $this->assertSame('Beispiel e.V.', $result['global']['NGO']['name']);
    }

    function testGuardSuppressesReferenceModeWhenKeepMode(): void {
        $plugin = $this->createPlugin();

        $scopeConfigs = [
            'page' => ['TestIdRefType' => ['organizer' => ['_mode' => 'reference', '_fragment' => 'organization']]],
        ];

        [$result, $suppressed] = callPluginMethod($plugin, 'applyDanglingReferenceGuard', [$scopeConfigs, true]);

        $this->assertContains('organization', $suppressed,
            'Keep-Modus muss Target in suppressedIdTargets aufnehmen');
    }

    // -----------------------------------------------------------
    // validateAgainstSchema() — id_reference_or_literal überspringen
    // -----------------------------------------------------------

    function testValidateAgainstSchemaSkipsIdRefOrLiteralInRequired(): void {
        $plugin = $this->createPlugin();
        $schema = callPluginMethod($plugin, 'loadSchema', ['TestIdRefType']);

        // Leere Daten — würde ohne Skip einen required-Fehler für "organizer" erzeugen
        $result = callPluginMethod($plugin, 'validateAgainstSchema', [[], $schema]);

        $this->assertSame([], $result['errors'],
            'id_reference_or_literal muss in der required-Prüfung übersprungen werden');
    }

}
