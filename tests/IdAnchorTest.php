<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für den generischen, schema-deklarierten @id-Anker:
* resolveBaseUrl() (Basis-URL via Core-{CANONICAL_LINK}-Muster),
* resolveNodeId() (ui:idFragment-Auflösung + De-Dup-Guard),
* buildJsonLdScript() (@id-Einbettung nach dem Leerfilter) sowie
* der NGO-Schema-Type (Global-Scope, nonprofitStatus-Enum).
*
* Hinweis: resolveBaseUrl() spiegelt das Core-Muster aus
* $_SERVER['HTTPS'] + $_SERVER['HTTP_HOST'] + Pfad. Die Tests
* setzen diese $_SERVER-Schlüssel gezielt und räumen sie in
* tearDown() wieder ab.
*
***************************************************************/
final class IdAnchorTest extends TestCase {

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

    /***************************************************************
    *
    * Erzeugt eine Plugin-Instanz auf einer Kopie von schemas/ und
    * sprachen/ mit isoliertem InMemorySettings-Stub (analog zu
    * JsonLdOutputTest), damit getContent() ohne die echte
    * plugin.conf.php getestet werden kann.
    *
    ***************************************************************/
    private function createPluginWithSettings(): \schemaOrgData {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_idtest_'.uniqid().'/';
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
    * Liefert alle JSON-LD-<script>-Blöcke aus getContent() dekodiert.
    *
    ***************************************************************/
    private function getJsonLdBlocks(\schemaOrgData $plugin): array {
        $output = $plugin->getContent('');
        preg_match_all('#<script type="application/ld\+json">\n(.*?)\n</script>\n#s', $output, $matches);
        return array_map(fn(string $json): mixed => json_decode($json, true), $matches[1]);
    }

    // -----------------------------------------------------------
    // resolveBaseUrl() - Core-Spiegelung
    // -----------------------------------------------------------

    function testResolveBaseUrlMirrorsCanonicalPattern(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertSame(
            'https://www.example.org/',
            callPluginMethod($plugin, 'resolveBaseUrl', [])
        );
    }

    function testResolveBaseUrlUsesHttpWhenNotSecure(): void {
        $plugin = new \schemaOrgData();
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertSame(
            'http://example.test/',
            callPluginMethod($plugin, 'resolveBaseUrl', [])
        );
    }

    function testResolveBaseUrlKeepsSubdirectoryPath(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/cms/index.php';

        $this->assertSame(
            'https://www.example.org/cms/',
            callPluginMethod($plugin, 'resolveBaseUrl', [])
        );
    }

    function testResolveBaseUrlReturnsEmptyWithoutHost(): void {
        $plugin = new \schemaOrgData();
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertSame('', callPluginMethod($plugin, 'resolveBaseUrl', []));
    }

    // -----------------------------------------------------------
    // resolveNodeId() - schema-deklariertes Fragment + De-Dup
    // -----------------------------------------------------------

    function testResolveNodeIdUsesSchemaFragment(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assigned = [];
        $id = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);

        $this->assertSame('https://www.example.org/#organization', $id);
        $this->assertSame(['organization'], $assigned);
    }

    function testResolveNodeIdEmptyForSchemaWithoutFragment(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assigned = [];
        $id = callPluginMethod($plugin, 'resolveNodeId', ['LocalBusiness', &$assigned]);

        $this->assertSame('', $id);
        $this->assertSame([], $assigned);
    }

    function testResolveNodeIdDeDupGuardAssignsFragmentOnlyOnce(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        // Erster Knoten mit Fragment "organization" erhält den Anker ...
        $assigned = [];
        $first = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);
        // ... ein zweiter Knoten mit demselben Fragment bleibt ohne @id.
        $second = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);

        $this->assertSame('https://www.example.org/#organization', $first);
        $this->assertSame('', $second);
        $this->assertSame(['organization'], $assigned);
    }

    function testResolveNodeIdEmptyWhenBaseUrlUnresolvable(): void {
        $plugin = new \schemaOrgData();
        unset($_SERVER['HTTP_HOST']);

        // Leer-Tilgungs-Guard: ohne Basis-URL wird KEIN (leeres) @id gebildet
        // und das Fragment bleibt unbelegt.
        $assigned = [];
        $id = callPluginMethod($plugin, 'resolveNodeId', ['NGO', &$assigned]);

        $this->assertSame('', $id);
        $this->assertSame([], $assigned);
    }

    // -----------------------------------------------------------
    // buildJsonLdScript() - @id-Einbettung nach dem Leerfilter
    // -----------------------------------------------------------

    private function buildDecoded(string $type, array $data, string $nodeId): array {
        $plugin = new \schemaOrgData();
        $script = callPluginMethod($plugin, 'buildJsonLdScript', [$type, $data, $nodeId]);

        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    function testBuildJsonLdScriptInjectsIdAfterType(): void {
        $decoded = $this->buildDecoded('NGO', ['name' => 'Beispiel e. V.'], 'https://www.example.org/#organization');

        $this->assertSame('https://www.example.org/#organization', $decoded['@id']);

        // @id steht direkt hinter @type (Reihenfolge im erzeugten JSON).
        $keys = array_keys($decoded);
        $this->assertSame(['@context', '@type', '@id'], array_slice($keys, 0, 3));
    }

    function testBuildJsonLdScriptWithoutIdHasNoIdKey(): void {
        $decoded = $this->buildDecoded('NGO', ['name' => 'Beispiel e. V.'], '');

        $this->assertArrayNotHasKey('@id', $decoded);
    }

    function testSetIdSurvivesEmptyPropertyFilter(): void {
        // Leer-Tilgungs-Guard auf Builder-Ebene: trotz leerer Properties
        // (die removeEmptyJsonLdProperties() entfernt) bleibt ein gesetztes
        // @id erhalten, weil es erst NACH dem Leerfilter eingefügt wird.
        $decoded = $this->buildDecoded('NGO', [
            'name' => 'Beispiel e. V.',
            'description' => '',
            'logo' => null,
        ], 'https://www.example.org/#organization');

        $this->assertSame('https://www.example.org/#organization', $decoded['@id']);
        $this->assertArrayNotHasKey('description', $decoded);
        $this->assertArrayNotHasKey('logo', $decoded);
    }

    // -----------------------------------------------------------
    // NGO-Schema: Global-Scope, Fragment, nonprofitStatus-Enum
    // -----------------------------------------------------------

    function testNgoSchemaIsGlobalScopeWithOrganizationFragment(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['NGO']);

        $this->assertContains('global', $schema['ui:scopes']);
        $this->assertSame('organization', $schema['ui:idFragment']);
        $this->assertContains('name', $schema['required']);
        $this->assertContains('url', $schema['required']);
    }

    function testNonprofitStatusEnumContainsExpectedTerms(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['NGO']);
        $field = $schema['properties']['nonprofitStatus'];

        $expected = [
            'DECooperativeCharity',
            'DEFoundationCharity',
            'DEJointStockCompanyCharity',
            'DELimitedLiabilityCharity',
            'DENotRegisteredAssociationCharity',
            'DEPublicCharity',
            'DERegisteredAssociationCharity',
        ];

        $this->assertSame($expected, $field['enum']);
        $this->assertSame('DERegisteredAssociationCharity', $field['default']);
    }

    function testNonprofitStatusKnownValueIsSelected(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['NGO']);
        $field = $schema['properties']['nonprofitStatus'];

        $html = callPluginMethod($plugin, 'renderSelectWidget', [
            'ngo_status', 'ngo[status]', $field, 'DEFoundationCharity',
        ]);

        // Bekannter Enum-Wert ist als ausgewählte Option mit lesbarem Label gerendert.
        $this->assertStringContainsString(
            '<option value="DEFoundationCharity" selected="selected">Gemeinnützige Stiftung</option>',
            $html
        );
    }

    function testNonprofitStatusUnknownValueIsNotSelected(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['NGO']);
        $field = $schema['properties']['nonprofitStatus'];

        $html = callPluginMethod($plugin, 'renderSelectWidget', [
            'ngo_status', 'ngo[status]', $field, 'NichtVorhandenerStatus',
        ]);

        // Unbekannter Wert taucht in keiner Option auf und ist nicht selektiert.
        $this->assertStringNotContainsString('NichtVorhandenerStatus', $html);
        $this->assertStringNotContainsString('selected="selected"', $html);
    }

    // -----------------------------------------------------------
    // Integration: NGO im Global-Scope mit @id über getContent()
    // -----------------------------------------------------------

    function testNgoGlobalConfigOutputsOrganizationIdAnchor(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();
        $this->settings->set('config_global', [
            'NGO' => [
                'name' => 'Beispiel-Hilfe e. V.',
                'url' => 'https://www.example.org',
                'nonprofitStatus' => 'DERegisteredAssociationCharity',
            ],
        ]);

        [$jsonLd] = $this->getJsonLdBlocks($plugin);

        $this->assertSame('NGO', $jsonLd['@type']);
        $this->assertSame('https://www.example.org/#organization', $jsonLd['@id']);
        $this->assertSame('DERegisteredAssociationCharity', $jsonLd['nonprofitStatus']);
    }

    function testDeDupGuardAcrossTwoTypesSharingFragment(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        // Zweiten Global-Type mit demselben Fragment "organization" anlegen,
        // um den De-Dup-Guard über zwei verschiedene Typen zu prüfen.
        file_put_contents($this->pluginDir.'schemas/TestOrgAnchor.json', json_encode([
            'title' => 'TestOrgAnchor',
            'type' => 'object',
            'ui:scopes' => ['global'],
            'ui:idFragment' => 'organization',
            'required' => ['name', 'url'],
            'properties' => [
                'name' => ['type' => 'string', 'ui:widget' => 'text', 'ui:label' => 'label_name'],
                'url' => ['type' => 'string', 'ui:widget' => 'text', 'ui:label' => 'label_url'],
            ],
        ]));

        // NGO zuerst -> erhält den Anker; TestOrgAnchor teilt das Fragment
        // und bleibt daher ohne @id.
        $this->settings->set('config_global', [
            'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://www.example.org'],
            'TestOrgAnchor' => ['name' => 'Zweite Org', 'url' => 'https://www.example.org'],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertCount(2, $blocks);
        $this->assertSame('https://www.example.org/#organization', $byType['NGO']['@id']);
        $this->assertArrayNotHasKey('@id', $byType['TestOrgAnchor']);
    }
}
