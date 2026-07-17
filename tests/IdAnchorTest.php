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

    // -----------------------------------------------------------
    // resolveNodeId() - schema-deklariertes Fragment + De-Dup
    // -----------------------------------------------------------

    function testResolveNodeIdUsesSchemaFragment(): void {
        $plugin = new \schemaOrgData();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assigned = [];
        $id = (new \SchemaOrgData_JsonLdBuilder())->resolveNodeId(
            new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(),
            $plugin->PLUGIN_SELF_DIR, 'NGO', $assigned
        );

        $this->assertSame('https://www.example.org/#organization', $id);
        $this->assertSame(['organization'], $assigned);
    }

    // -----------------------------------------------------------
    // buildJsonLdScript() - @id-Einbettung nach dem Leerfilter
    // -----------------------------------------------------------

    private function buildDecoded(string $type, array $data, string $nodeId, array $suppressedIdTargets = []): array {
        $script = (new \SchemaOrgData_JsonLdBuilder())->buildJsonLdScript(
            new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(),
            \BASE_DIR.'plugins/schemaOrgData/', $type, $data, $nodeId, $suppressedIdTargets
        );

        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function adminLang(\schemaOrgData $plugin): \Language {
        return new \Language($plugin->PLUGIN_SELF_DIR.'sprachen/admin_language_deDE.txt');
    }

    function testBuildJsonLdScriptWithoutIdHasNoIdKey(): void {
        $decoded = $this->buildDecoded('NGO', ['name' => 'Beispiel e. V.'], '');

        $this->assertArrayNotHasKey('@id', $decoded);
    }

    // -----------------------------------------------------------
    // NGO-Schema: Global-Scope, Fragment, nonprofitStatus-Enum
    // -----------------------------------------------------------

    function testNgoSchemaIsGlobalScopeWithOrganizationFragment(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'NGO');

        $this->assertContains('global', $schema['ui:scopes']);
        $this->assertSame('organization', $schema['ui:idFragment']);
        $this->assertContains('name', $schema['required']);
        $this->assertContains('url', $schema['required']);
    }

    function testNonprofitStatusEnumContainsExpectedTerms(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'NGO');
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

    /***************************************************************
    *
    * Analog zu testDeDupGuardAcrossTwoTypesSharingFragment(), aber mit
    * den beiden echten Schema-Types NGO und Organization statt einem
    * synthetischen Zweit-Schema - beide teilen real das Fragment
    * "organization" (ui:idFragment in Organization.json).
    *
    ***************************************************************/
    function testDeDupGuardAcrossNgoAndOrganizationSharingFragment(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        // NGO zuerst -> erhält den Anker; Organization teilt das Fragment
        // und bleibt daher ohne @id.
        $this->settings->set('config_global', [
            'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://www.example.org'],
            'Organization' => ['name' => 'Beispiel GmbH', 'url' => 'https://www.example.org'],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertCount(2, $blocks);
        $this->assertSame('https://www.example.org/#organization', $byType['NGO']['@id']);
        $this->assertArrayNotHasKey('@id', $byType['Organization']);
    }

    function testDeDupGuardAcrossOrganizationAndNgoSharingFragmentReversedOrder(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        // Umgekehrte Reihenfolge im config_global-Array: Organization zuerst
        // -> erhält den Anker; NGO bleibt ohne @id. Dokumentiert, dass die
        // Ausgabereihenfolge (nicht ein hartcodierter Type-Vorrang) über die
        // @id-Vergabe entscheidet.
        $this->settings->set('config_global', [
            'Organization' => ['name' => 'Beispiel GmbH', 'url' => 'https://www.example.org'],
            'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://www.example.org'],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertCount(2, $blocks);
        $this->assertSame('https://www.example.org/#organization', $byType['Organization']['@id']);
        $this->assertArrayNotHasKey('@id', $byType['NGO']);
    }

    // -----------------------------------------------------------
    // LocalBusiness-Familie: ui:idFragment "organization"
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Erweiterung von testDeDupGuardAcrossNgoAndOrganizationSharingFragment()
    * auf drei gleichzeitig (fehlerhaft) global konfigurierte Types, die
    * alle das Fragment "organization" teilen - NGO und zwei Types aus der
    * LocalBusiness-Familie. Nur der erste in Ausgabereihenfolge erhält
    * den Anker, die übrigen bleiben ohne @id.
    *
    ***************************************************************/
    function testDeDupGuardAcrossThreeTypesInLocalBusinessFamilySharingFragment(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        $this->settings->set('config_global', [
            'NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://www.example.org'],
            'AccountingService' => ['name' => 'Muster Steuerberatung', 'url' => 'https://www.example.org'],
            'LegalService' => ['name' => 'Muster Kanzlei', 'url' => 'https://www.example.org'],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertCount(3, $blocks);
        $this->assertSame('https://www.example.org/#organization', $byType['NGO']['@id']);
        $this->assertArrayNotHasKey('@id', $byType['AccountingService']);
        $this->assertArrayNotHasKey('@id', $byType['LegalService']);
    }

    /***************************************************************
    *
    * resolveAvailableGlobalFragments() liefert bei global konfiguriertem
    * AccountingService (ohne NGO/Organization) einen Eintrag für das
    * Fragment "organization" mit dem AccountingService-Typlabel - zeigt,
    * dass die Fragment-Liste nicht auf NGO/Organization/Person beschränkt
    * ist, sondern jeden Type mit ui:idFragment berücksichtigt.
    *
    ***************************************************************/
    function testResolveAvailableGlobalFragmentsListsAccountingServiceTypeLabel(): void {
        $plugin = $this->createPluginWithSettings();
        $this->settings->set('config_global', [
            'AccountingService' => ['name' => 'Muster Steuerberatung', 'url' => 'https://www.example.org'],
        ]);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertArrayHasKey('organization', $fragments);
        $this->assertStringContainsString('AccountingService', $fragments['organization']);
        $this->assertStringContainsString('Muster Steuerberatung', $fragments['organization']);
    }

    /***************************************************************
    *
    * DonateAction.recipient (id_reference) löst korrekt auf, wenn eine
    * als AccountingService konfigurierte globale Identität - statt wie
    * bisher nur NGO/Organization - den Zielknoten stellt. Der Guard
    * bleibt No-op, weil der Zielknoten bereits im Graph vorhanden ist.
    *
    ***************************************************************/
    function testDonateActionRecipientResolvesViaAccountingServiceAsSoleGlobalIdentity(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        $scopeConfigs = [
            'global' => [
                'AccountingService' => ['name' => 'Muster Steuerberatung', 'url' => 'https://www.example.org'],
            ],
            'page' => [
                'DonateAction' => ['description' => 'Jetzt spenden und helfen!'],
            ],
        ];

        [$result, $suppressed] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame($scopeConfigs, $result, 'AccountingService deckt den Zielknoten bereits ab - Guard bleibt No-op');
        $this->assertSame([], $suppressed);

        $decoded = $this->buildDecoded('DonateAction', $result['page']['DonateAction'], '', $suppressed);

        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    /***************************************************************
    *
    * Event.organizer (id_reference_or_literal, Referenz-Modus) löst
    * korrekt auf, wenn eine als LegalService konfigurierte globale
    * Identität den Zielknoten stellt - Kernaussage von ADR-Entscheidung
    * (b): die Fragment-Auflösung ist unabhängig vom konkreten @type.
    *
    ***************************************************************/
    function testEventOrganizerResolvesViaLegalServiceAsSoleGlobalIdentity(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();

        $scopeConfigs = [
            'global' => [
                'LegalService' => ['name' => 'Muster Kanzlei', 'url' => 'https://www.example.org'],
            ],
            'page' => [
                'Event' => [
                    'name' => 'Tag der offenen Tür',
                    'startDate' => '2026-09-15T19:00:00+02:00',
                    'organizer' => ['_mode' => 'reference', '_fragment' => 'organization'],
                ],
            ],
        ];

        [$result, $suppressed] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame($scopeConfigs, $result, 'LegalService deckt den Zielknoten bereits ab - Guard bleibt No-op');
        $this->assertSame([], $suppressed);

        $decoded = $this->buildDecoded('Event', $result['page']['Event'], '', $suppressed);

        $this->assertSame('https://www.example.org/#organization', $decoded['organizer']['@id']);
        $this->assertArrayNotHasKey('name', $decoded['organizer']);
    }

    /***************************************************************
    *
    * Type-Wechsel-Regressionstest (ADR-Entscheidung b): die globale
    * Identität wechselt von AccountingService zu LegalService (z. B.
    * Rebranding) - eine zuvor gespeicherte "_fragment: organization"-
    * Referenz (DonateAction.recipient) bleibt ohne manuelles Nachziehen
    * auflösbar, weil beide Types dasselbe Fragment teilen.
    *
    ***************************************************************/
    function testGlobalIdentityTypeChangeFromAccountingServiceToLegalServiceKeepsReferenceResolvable(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPluginWithSettings();
        $donateActionScope = ['DonateAction' => ['description' => 'Jetzt spenden und helfen!']];

        // Vorher: AccountingService als globale Identität.
        $scopeConfigsBefore = [
            'global' => ['AccountingService' => ['name' => 'Muster Steuerberatung', 'url' => 'https://www.example.org']],
            'page' => $donateActionScope,
        ];
        [$resultBefore, $suppressedBefore] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigsBefore, false, new \SchemaOrgData_PersonsRegistryService()
        );
        $decodedBefore = $this->buildDecoded('DonateAction', $resultBefore['page']['DonateAction'], '', $suppressedBefore);

        // Nachher: Rebranding auf LegalService, dieselbe Referenz bleibt auflösbar.
        $scopeConfigsAfter = [
            'global' => ['LegalService' => ['name' => 'Muster Kanzlei', 'url' => 'https://www.example.org']],
            'page' => $donateActionScope,
        ];
        [$resultAfter, $suppressedAfter] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigsAfter, false, new \SchemaOrgData_PersonsRegistryService()
        );
        $decodedAfter = $this->buildDecoded('DonateAction', $resultAfter['page']['DonateAction'], '', $suppressedAfter);

        $this->assertSame('https://www.example.org/#organization', $decodedBefore['recipient']['@id']);
        $this->assertSame($decodedBefore['recipient']['@id'], $decodedAfter['recipient']['@id'],
            'Type-Wechsel der globalen Identität darf eine bestehende Fragment-Referenz nicht dangling machen');
    }
}
