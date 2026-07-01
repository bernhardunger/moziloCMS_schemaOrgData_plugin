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
}
