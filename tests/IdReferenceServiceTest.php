<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_IdReferenceService
* (Refactoring-Schritt 6, siehe doc/adr_komponenten_refactoring.md,
* Entscheidung c). Echte, zustandslose ScopeResolver-/
* SchemaRepository-Instanzen, $pluginSelfDir zeigt auf die realen
* Schema-/Sprach-Fixtures des Plugins. Die Facade-Delegator-Verträge
* (callPluginMethod 'resolveAvailableGlobalFragments' /
* 'applyDanglingReferenceGuard') sind bereits durch
* PersonIdRefOrLiteralTest und DonateActionTest abgedeckt.
*
***************************************************************/
final class IdReferenceServiceTest extends TestCase {

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    // -----------------------------------------------------------
    // resolveAvailableGlobalFragments()
    // -----------------------------------------------------------

    function testLabelEnthaeltTypeLabelUndGespeichertenNamen(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['NGO' => ['name' => 'Beispiel e.V.']]);

        $fragments = $service->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $this->adminLang()
        );

        $this->assertArrayHasKey('organization', $fragments);
        $this->assertStringContainsString('Beispiel e.V.', $fragments['organization']);
        $this->assertStringContainsString('—', $fragments['organization'],
            'Format muss "TypeLabel — name" sein, wenn ein Name gespeichert ist');
    }

    function testLabelOhneGespeichertenNamenIstNurDasTypeLabel(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['NGO' => ['url' => 'https://example.org']]);

        $fragments = $service->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $this->adminLang()
        );

        $this->assertArrayHasKey('organization', $fragments);
        $this->assertStringNotContainsString('—', $fragments['organization'],
            'Ohne gespeicherten Namen darf kein " — " im Label stehen');
    }

    function testTypesOhneUiIdFragmentWerdenUebersprungen(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        // Article hat kein ui:idFragment.
        $settings->set('config_global', ['Article' => ['headline' => 'Testbeitrag']]);

        $fragments = $service->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $this->adminLang()
        );

        $this->assertSame([], $fragments);
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard()
    // -----------------------------------------------------------

    function testNoOpWennZielknotenBereitsImGraphIst(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();

        $scopeConfigs = [
            'global' => ['NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://example.org']],
            'page'   => ['DonateAction' => []],
        ];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, false
        );

        $this->assertSame($scopeConfigs, $result);
        $this->assertSame([], $suppressed);
    }

    function testKeepModusUnterdruecktStattStubZuErzeugen(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();

        $scopeConfigs = ['page' => ['DonateAction' => []]];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, true
        );

        $this->assertArrayNotHasKey('global', $result,
            'Bei keep-Modus darf kein Stub-Scope angelegt werden');
        $this->assertContains('organization', $suppressed,
            'keep-Modus muss Vorrang vor der Stub-Erzeugung haben');
    }

    function testFehlenderZielknotenErzeugtMinimalStubNurMitName(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['NGO' => ['name' => 'Beispiel e. V.', 'url' => 'https://example.org']]);

        $scopeConfigs = ['page' => ['DonateAction' => []]];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, false
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['name' => 'Beispiel e. V.'], $result['global']['NGO'],
            'Stub darf ausschließlich name enthalten - @type/@id folgen erst über die Ausgabeschleife');
    }

    /***************************************************************
    *
    * Migriert aus PersonIdRefOrLiteralTest::testGuardNoOpForLiteralMode().
    * Abweichend vom Original: applyDanglingReferenceGuard() lädt in
    * seiner ersten Schleife für JEDEN Type in $scopeConfigs zunächst
    * dessen eigenes Schema per loadSchema($pluginSelfDir, $type) -
    * unabhängig vom späteren _mode-Zweig. "TestIdRefType" existiert
    * nicht unter den echten Plugin-Schemas; mit $this->pluginSelfDir()
    * würde loadSchema() bereits null liefern und die Schleife den Typ
    * überspringen, bevor der _mode-Zweig überhaupt geprüft wird - der
    * Test wäre dann nur zufällig grün. Deshalb hier das Hilfsschema
    * TestIdRefType.json analog PersonIdRefOrLiteralTest::createPlugin()
    * in einem eigenen Temp-Verzeichnis nachgebaut.
    *
    ***************************************************************/
    private function createTempPluginDirWithTestIdRefTypeSchema(): string {
        $pluginSelfDir = sys_get_temp_dir().'/schemaOrgData_idreftest_'.uniqid().'/';
        mkdir($pluginSelfDir.'schemas', 0777, true);
        file_put_contents($pluginSelfDir.'schemas/TestIdRefType.json', json_encode([
            'title' => 'TestIdRefType',
            'type' => 'object',
            'ui:scopes' => ['page'],
            'required' => ['organizer'],
            'properties' => [
                'organizer' => [
                    'type' => 'object',
                    'ui:widget' => 'id_reference_or_literal',
                    'ui:literalFields' => ['name'],
                    'ui:literalType' => 'Person',
                ],
            ],
        ]));
        return $pluginSelfDir;
    }

    private function removeTempPluginDir(string $pluginSelfDir): void {
        unlink($pluginSelfDir.'schemas/TestIdRefType.json');
        rmdir($pluginSelfDir.'schemas');
        rmdir($pluginSelfDir);
    }

    function testNoOpFuerLiteralModusBeiIdReferenceOrLiteral(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $pluginSelfDir = $this->createTempPluginDirWithTestIdRefTypeSchema();

        $scopeConfigs = [
            'page' => ['TestIdRefType' => ['organizer' => ['_mode' => 'literal', 'name' => 'Max']]],
        ];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $pluginSelfDir, $scopeConfigs, false
        );

        $this->removeTempPluginDir($pluginSelfDir);

        $this->assertSame([], $suppressed, 'Literal-Modus darf keinen Dangling-Guard auslösen');
        $this->assertSame($scopeConfigs, $result, 'scopeConfigs darf bei Literal-Modus nicht verändert werden');
    }
}
