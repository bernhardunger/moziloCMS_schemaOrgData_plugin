<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_IdReferenceService.
* Echte, zustandslose ScopeResolver-/SchemaRepository-Instanzen,
* $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures des
* Plugins.
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
            $settings, $this->pluginSelfDir(), $this->adminLang(), new \SchemaOrgData_PersonsRegistryService()
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
            $settings, $this->pluginSelfDir(), $this->adminLang(), new \SchemaOrgData_PersonsRegistryService()
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
            $settings, $this->pluginSelfDir(), $this->adminLang(), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $fragments);
    }

    // -----------------------------------------------------------
    // isFragmentAllowedForReferenceTargets() / filterFragmentsByReferenceTargets()
    // -----------------------------------------------------------

    function testIsFragmentAllowedOhneReferenceTargetsIstImmerErlaubt(): void {
        $this->assertTrue(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('organization', null));
        $this->assertTrue(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('person-jane-doe', null));
    }

    function testIsFragmentAllowedPrueftOrganizationUndPersonsGetrennt(): void {
        $this->assertTrue(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('organization', ['organization']));
        $this->assertFalse(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('organization', ['persons']));
        $this->assertTrue(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('person-jane-doe', ['persons']));
        $this->assertFalse(\SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets('person-jane-doe', ['organization']));
    }

    function testFilterFragmentsByReferenceTargetsOhnePropertyLiefertUnveraendert(): void {
        $availableFragments = ['organization' => 'Organization', 'person-jane-doe' => 'Jane Doe'];

        $filtered = \SchemaOrgData_IdReferenceService::filterFragmentsByReferenceTargets($availableFragments, []);

        $this->assertSame($availableFragments, $filtered);
    }

    function testFilterFragmentsByReferenceTargetsSchraenktAufPersonsEin(): void {
        $availableFragments = ['organization' => 'Organization', 'person-jane-doe' => 'Jane Doe'];
        $fieldSchema = ['ui:referenceTargets' => ['persons']];

        $filtered = \SchemaOrgData_IdReferenceService::filterFragmentsByReferenceTargets($availableFragments, $fieldSchema);

        $this->assertSame(['person-jane-doe' => 'Jane Doe'], $filtered);
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
            $settings, $this->pluginSelfDir(), $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
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
            $settings, $this->pluginSelfDir(), $scopeConfigs, true, new \SchemaOrgData_PersonsRegistryService()
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
            $settings, $this->pluginSelfDir(), $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['name' => 'Beispiel e. V.'], $result['global']['NGO'],
            'Stub darf ausschließlich name enthalten - @type/@id folgen erst über die Ausgabeschleife');
    }

    /***************************************************************
    *
    * Der Stub-Zweig hilft bei einem typlosen Global-Bestand nicht
    * nach: Er setzt einen Type-Schlüssel mit passendem ui:idFragment
    * in config_global voraus. Ein Bestand, der nur Verwaltungsdaten
    * trägt, erzeugt deshalb keinen Stub - auch dann nicht, wenn der
    * keep-Vorrang gar nicht greift ($globalSuppressedByKeep = false)
    * und der Zweig somit tatsächlich betreten wird. Positivkontrolle
    * ist der vorstehende Test mit typisiertem config_global.
    *
    ***************************************************************/
    function testTyploserGlobalBestandErzeugtKeinenStub(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['_meta' => ['existing_jsonld' => false, 'jsonld_mode' => 'keep']]);

        $scopeConfigs = ['page' => ['DonateAction' => []]];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertArrayNotHasKey('global', $result,
            'Ohne Type-Schlüssel in config_global darf der Stub-Zweig keinen globalen Scope anlegen');
    }

    /***************************************************************
    *
    * applyDanglingReferenceGuard() lädt in seiner ersten Schleife
    * für JEDEN Type in $scopeConfigs zunächst
    * dessen eigenes Schema per loadSchema($pluginSelfDir, $type) -
    * unabhängig vom späteren _mode-Zweig. "TestIdRefType" existiert
    * nicht unter den echten Plugin-Schemas; mit $this->pluginSelfDir()
    * würde loadSchema() bereits null liefern und die Schleife den Typ
    * überspringen, bevor der _mode-Zweig überhaupt geprüft wird - der
    * Test wäre dann nur zufällig grün. Deshalb hier das Hilfsschema
    * TestIdRefType.json analog PersonFragmentEmissionTest::createPlugin()
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
            $settings, $pluginSelfDir, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->removeTempPluginDir($pluginSelfDir);

        $this->assertSame([], $suppressed, 'Literal-Modus darf keinen Dangling-Guard auslösen');
        $this->assertSame($scopeConfigs, $result, 'scopeConfigs darf bei Literal-Modus nicht verändert werden');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard() - org_relations als zusätzliche
    // Personen-Referenzquelle (siehe SchemaOrgData_OrgRelationsService)
    // -----------------------------------------------------------

    function testOrgRelationsSlugWirdAlsAktivePersonSlugsAufgenommen(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY, [
            'max-mustermann' => ['name' => 'Max Mustermann', 'status' => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE],
        ]);

        [, $suppressed, $activePersonSlugs] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), [], false, new \SchemaOrgData_PersonsRegistryService(),
            [['person' => 'max-mustermann', 'role' => 'founder']]
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['max-mustermann'], $activePersonSlugs);
    }

    function testOrgRelationsSlugAufGeloeschtePersonWirdUnterdrueckt(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        // Kein Registry-Eintrag - Slug existiert nicht (mehr).

        [, $suppressed, $activePersonSlugs] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), [], false, new \SchemaOrgData_PersonsRegistryService(),
            [['person' => 'geloescht', 'role' => 'founder']]
        );

        $this->assertSame(['person-geloescht'], $suppressed);
        $this->assertSame([], $activePersonSlugs);
    }

    function testOrgRelationsUndIdReferenceOrLiteralAufDenselbenSlugDedupenZuEinemEintrag(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();
        $settings->set(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY, [
            'max-mustermann' => ['name' => 'Max Mustermann', 'status' => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE],
        ]);

        $scopeConfigs = [
            'page' => ['Event' => [
                'name' => 'Termin',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'person-max-mustermann'],
            ]],
        ];

        [, $suppressed, $activePersonSlugs] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService(),
            [['person' => 'max-mustermann', 'role' => 'employee']]
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['max-mustermann'], $activePersonSlugs,
            'org_relations und id_reference_or_literal dürfen für denselben Slug nur einen Eintrag ergeben');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard() - Vollunterdrueckung bei
    // ui:required-Referenzfeldern (F5, PP4)
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Nebenwirkung von F5, keine ProfilePage-Sonderbehandlung: die
    * Vollunterdrueckung ist generisch an "ui:required" + Fragment-
    * Praefix "person-" gebunden, nicht an einen Type-Namen. JobPosting.
    * hiringOrganization ist ebenfalls ui:required und setzt kein
    * ui:referenceTargets, kann also (theoretisch, ueber die
    * Admin-Auswahlliste) ebenso auf eine Registry-Person zeigen -
    * verschwindet diese, greift dieselbe Regel wie bei ProfilePage.
    * Bewusst als Regressionstest festgehalten, damit diese Ausweitung
    * nicht unbemerkt bleibt, statt sie nur beilaeufig hinzunehmen.
    *
    ***************************************************************/
    function testRequiredHiringOrganizationSuppressesEntireJobPostingWhenPersonDeleted(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();

        $scopeConfigs = [
            'page' => ['JobPosting' => [
                'title'              => 'Steuerfachangestellte(r)',
                'hiringOrganization' => ['_mode' => 'reference', '_fragment' => 'person-geloescht'],
            ]],
        ];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertContains('person-geloescht', $suppressed);
        $this->assertArrayNotHasKey('JobPosting', $result['page']);
    }

    /***************************************************************
    *
    * Abgrenzung: die Vollunterdrueckung darf NICHT auf schema-statische
    * Fragmente (z. B. "organization") anschlagen, obwohl auch diese bei
    * keep-Modus in $suppressedIdTargets landen koennen - dort bedeutet
    * die Unterdrueckung eine bewusste Nutzerentscheidung (vorhandenes
    * JSON-LD beibehalten), keine Nichtexistenz des Zielknotens. Ohne
    * diese Abgrenzung wuerde jedes keep-Modus-Szenario mit einer
    * ui:required-id_reference(_or_literal)-Property auf "organization"
    * (z. B. DonateAction.recipient, JobPosting.hiringOrganization) den
    * gesamten Knoten verwerfen statt nur die Property - siehe auch
    * DonateActionTest::testKeepModusUnterdrueckStattStubZuErzeugen()-
    * Analogon in IdReferenceServiceTest (testKeepModusUnterdrueckt...).
    *
    ***************************************************************/
    function testRequiredReferenceToOrganizationFragmentDoesNotTriggerFullSuppressionInKeepMode(): void {
        $service = new \SchemaOrgData_IdReferenceService();
        $settings = new \InMemorySettings();

        $scopeConfigs = [
            'page' => ['JobPosting' => [
                'title'              => 'Steuerfachangestellte(r)',
                'hiringOrganization' => ['_mode' => 'reference', '_fragment' => 'organization'],
            ]],
        ];

        [$result, $suppressed] = $service->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(),
            $settings, $this->pluginSelfDir(), $scopeConfigs, true, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertContains('organization', $suppressed);
        $this->assertArrayHasKey('JobPosting', $result['page'],
            'organization-Fragmente loesen die Vollunterdrueckung nicht aus - keep-Modus bedeutet keine Nichtexistenz des Zielknotens');
    }
}
