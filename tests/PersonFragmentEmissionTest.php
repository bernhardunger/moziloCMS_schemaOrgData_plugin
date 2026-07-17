<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die @id-Fragmente von Registry-Personen (Fragment
* "person-{slug}") und deren Emission als eigenständige JSON-LD-
* Knoten (siehe README.md, Abschnitt "@id-Anker und
* Knotenreferenzen").
*
* Abgedeckte Bereiche:
*   - SchemaOrgData_PersonsRegistryService::buildFragment()/
*     resolveAbsoluteImageUrl()
*   - SchemaOrgData_UrlHelper::resolveMediaBaseUrl()
*   - resolveAvailableGlobalFragments(): listet aktive Registry-
*     Personen zusätzlich zu schema-statischen Fragmenten
*   - applyDanglingReferenceGuard(): person-{slug}-Ziele (vorhanden/
*     fehlend), Rückgabe von $activePersonSlugs
*   - SchemaOrgData_JsonLdBuilder::resolvePersonNodeId(): De-Dup-Guard
*   - Ende-zu-Ende über getContent(): Emission bei Referenz, Dangling-
*     Vermeidung, Status-Unabhängigkeit, De-Dup
*   - validateAgainstSchema(): id_reference_or_literal in required wird
*     weiterhin übersprungen (generischer Widget-Mechanismus, nicht
*     Person-spezifisch)
*
* Das Hilfsschema TestIdRefType.json (id_reference_or_literal-Felder
* "organizer" und "secondaryContact") wird im Temp-Verzeichnis erzeugt,
* um den generischen Widget-Mechanismus unabhängig von einem konkreten
* produktiven Schema zu prüfen.
*
***************************************************************/
final class PersonFragmentEmissionTest extends TestCase {

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
    * und temporärem Plugin-Verzeichnis (Kopie der echten schemas/ und
    * sprachen/, inkl. Event.json - Träger des einzigen produktiven
    * id_reference_or_literal-Aufrufers mit Referenzziel "Person").
    * Schreibt zusätzlich ein Hilfsschema TestIdRefType.json mit ZWEI
    * id_reference_or_literal-Feldern (für den De-Dup-Test) in das
    * Temp-Schemas-Verzeichnis.
    *
    ***************************************************************/
    private function createPlugin(): \schemaOrgData {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_personfrag_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');

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
                'secondaryContact' => [
                    'type'      => 'object',
                    'ui:widget' => 'id_reference_or_literal',
                    'ui:label'  => 'label_name',
                    'ui:literalFields' => ['name'],
                    'ui:literalType'   => 'Person',
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

    private function adminLang(\schemaOrgData $plugin): \Language {
        return new \Language($plugin->PLUGIN_SELF_DIR.'sprachen/admin_language_deDE.txt');
    }

    private function setActivePerson(string $slug, array $overrides = []): void {
        $registry = $this->settings->keyExists(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            ? $this->settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            : [];
        $registry[$slug] = array_merge([
            'name'            => 'Max Mustermann',
            'honorificPrefix' => '',
            'jobTitle'        => '',
            'description'     => '',
            'url'             => '',
            'sameAs'          => [],
            'image'           => '',
            'status'          => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE,
            'sortOrder'       => 100,
        ], $overrides);
        $this->settings->set(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY, $registry);
    }

    private function getJsonLdBlocks(\schemaOrgData $plugin): array {
        $output = $plugin->getContent('');
        preg_match_all('#<script type="application/ld\+json">\n(.*?)\n</script>\n#s', $output, $matches);
        return array_map(fn(string $json): mixed => json_decode($json, true), $matches[1]);
    }

    // -----------------------------------------------------------
    // SchemaOrgData_PersonsRegistryService::buildFragment()
    // -----------------------------------------------------------

    function testBuildFragmentPrefixesSlugWithPerson(): void {
        $this->assertSame('person-max-mustermann', \SchemaOrgData_PersonsRegistryService::buildFragment('max-mustermann'));
    }

    // -----------------------------------------------------------
    // SchemaOrgData_UrlHelper::resolveMediaBaseUrl()
    // -----------------------------------------------------------

    function testResolveMediaBaseUrlCombinesFrontendBaseUrlAndContentFilesDirName(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertSame('https://www.example.org/dateien/', (new \SchemaOrgData_UrlHelper())->resolveMediaBaseUrl());
    }

    function testResolveMediaBaseUrlEmptyWithoutHost(): void {
        unset($_SERVER['HTTP_HOST']);

        $this->assertSame('', (new \SchemaOrgData_UrlHelper())->resolveMediaBaseUrl());
    }

    // -----------------------------------------------------------
    // SchemaOrgData_PersonsRegistryService::resolveAbsoluteImageUrl()
    // -----------------------------------------------------------

    function testResolveAbsoluteImageUrlLeavesAbsoluteUrlUnchanged(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_PersonsRegistryService())->resolveAbsoluteImageUrl(
            'https://cdn.example.org/max.jpg', new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame('https://cdn.example.org/max.jpg', $result);
    }

    function testResolveAbsoluteImageUrlCompletesRelativePath(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_PersonsRegistryService())->resolveAbsoluteImageUrl(
            'personen/max.jpg', new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame('https://www.example.org/dateien/personen/max.jpg', $result);
    }

    function testResolveAbsoluteImageUrlLeavesEmptyValueUnchanged(): void {
        $result = (new \SchemaOrgData_PersonsRegistryService())->resolveAbsoluteImageUrl(
            '', new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame('', $result);
    }

    // -----------------------------------------------------------
    // resolveAvailableGlobalFragments() - Registry-Personen
    // -----------------------------------------------------------

    function testResolveFragmentsEmptyWhenNoGlobalConfigAndNoPersons(): void {
        $plugin = $this->createPlugin();
        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $fragments);
    }

    function testResolveFragmentsListsActiveRegistryPerson(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', ['name' => 'Max Mustermann']);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertArrayHasKey('person-max-mustermann', $fragments);
        $this->assertSame('Max Mustermann', $fragments['person-max-mustermann']);
    }

    function testResolveFragmentsLabelIncludesJobTitleWhenPresent(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', ['name' => 'Max Mustermann', 'jobTitle' => 'Geschäftsführer']);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame('Max Mustermann – Geschäftsführer', $fragments['person-max-mustermann']);
    }

    function testResolveFragmentsExcludesInactivePerson(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', ['status' => \SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE]);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertArrayNotHasKey('person-max-mustermann', $fragments);
        $this->assertSame([], $fragments);
    }

    function testResolveFragmentsCombinesSchemaStaticAndRegistryFragments(): void {
        $plugin = $this->createPlugin();
        $this->settings->set('config_global', ['NGO' => ['name' => 'Beispiel e.V.']]);
        $this->setActivePerson('max-mustermann', ['name' => 'Max Mustermann']);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertArrayHasKey('organization', $fragments);
        $this->assertArrayHasKey('person-max-mustermann', $fragments);
        $this->assertCount(2, $fragments);
    }

    function testResolveFragmentsSortsBySortOrderThenName(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('b-person', ['name' => 'Berta Beispiel', 'sortOrder' => 100]);
        $this->setActivePerson('a-person', ['name' => 'Anna Anders', 'sortOrder' => 50]);

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame(['person-a-person', 'person-b-person'], array_keys($fragments),
            'sortOrder 50 (Anna) muss vor sortOrder 100 (Berta) stehen');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard() - person-{slug}-Ziele
    // -----------------------------------------------------------

    function testGuardAddsExistingSlugToActivePersonSlugsWithoutSuppression(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann');

        $scopeConfigs = [
            'page' => ['Event' => [
                'name' => 'Termin',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'person-max-mustermann'],
            ]],
        ];

        [$result, $suppressed, $activePersonSlugs] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['max-mustermann'], $activePersonSlugs);
        $this->assertSame($scopeConfigs, $result, 'scopeConfigs bleibt für Registry-Personen unverändert - Emission erfolgt separat');
    }

    function testGuardSuppressesReferenceToMissingSlug(): void {
        $plugin = $this->createPlugin();
        // Kein Eintrag in der Registry - Slug existiert nicht (mehr).

        $scopeConfigs = [
            'page' => ['Event' => [
                'name' => 'Termin',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'person-geloescht'],
            ]],
        ];

        [$result, $suppressed, $activePersonSlugs] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame(['person-geloescht'], $suppressed,
            'Referenz auf nicht (mehr) existierenden Slug muss unterdrückt werden - lieber keine Referenz als eine hängende @id');
        $this->assertSame([], $activePersonSlugs);
        $this->assertSame($scopeConfigs, $result);
    }

    function testGuardIncludesInactivePersonSlugAsActive(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', ['status' => \SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE]);

        $scopeConfigs = [
            'page' => ['Event' => [
                'name' => 'Termin',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'person-max-mustermann'],
            ]],
        ];

        [, $suppressed, $activePersonSlugs] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed,
            'Status ist für die Dangling-Prüfung irrelevant - nur die Slug-Existenz zählt');
        $this->assertSame(['max-mustermann'], $activePersonSlugs);
    }

    function testGuardDedupesTwoReferencesToSameSlugIntoOneActivePersonSlug(): void {
        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann');

        $scopeConfigs = [
            'page' => ['TestIdRefType' => [
                'organizer'        => ['_mode' => 'reference', '_fragment' => 'person-max-mustermann'],
                'secondaryContact' => ['_mode' => 'reference', '_fragment' => 'person-max-mustermann'],
            ]],
        ];

        [, $suppressed, $activePersonSlugs] = (new \SchemaOrgData_IdReferenceService())->applyDanglingReferenceGuard(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['max-mustermann'], $activePersonSlugs,
            'Zwei Referenzen auf denselben Slug dürfen nur einen Eintrag ergeben');
    }

    // -----------------------------------------------------------
    // SchemaOrgData_JsonLdBuilder::resolvePersonNodeId() - De-Dup-Guard
    // -----------------------------------------------------------

    function testResolvePersonNodeIdBuildsFragmentUri(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assigned = [];
        $id = (new \SchemaOrgData_JsonLdBuilder())->resolvePersonNodeId(new \SchemaOrgData_UrlHelper(), 'max-mustermann', $assigned);

        $this->assertSame('https://www.example.org/#person-max-mustermann', $id);
        $this->assertSame(['person-max-mustermann'], $assigned);
    }

    function testResolvePersonNodeIdSecondCallForSameSlugReturnsEmpty(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $builder = new \SchemaOrgData_JsonLdBuilder();
        $urlHelper = new \SchemaOrgData_UrlHelper();
        $assigned = [];

        $first = $builder->resolvePersonNodeId($urlHelper, 'max-mustermann', $assigned);
        $second = $builder->resolvePersonNodeId($urlHelper, 'max-mustermann', $assigned);

        $this->assertNotSame('', $first);
        $this->assertSame('', $second, 'Zweite Vergabe desselben Fragments muss leer bleiben (De-Dup-Guard)');
    }

    // -----------------------------------------------------------
    // Ende-zu-Ende über getContent(): Emission/Dangling/Status/De-Dup
    //
    // CAT_REQUEST/PAGE_REQUEST sind in tests/bootstrap.php fest auf
    // "false" gesetzt (siehe JsonLdOutputTest.php) - Seiten-Scope-
    // Konfiguration (z. B. Event.organizer) kann über getContent()
    // daher nicht getestet werden. Die Ende-zu-Ende-Tests nutzen
    // stattdessen org_relations (Global-Scope, siehe
    // SchemaOrgData_OrgRelationsService) als Trägermechanismus - der
    // zugrundeliegende Personen-Emissionsmechanismus (applyDanglingReferenceGuard()/
    // $activePersonSlugs/resolvePersonNodeId()) ist identisch zu dem der
    // id_reference_or_literal-Widgets (z. B. Event.organizer, Article.author),
    // seit dem Entfall des LocalBusiness.employee-Einzelfelds (AP3) jedoch
    // ohne global erreichbaren id_reference_or_literal-Träger mehr.
    // -----------------------------------------------------------

    private function validLocalBusinessConfig(array $overrides = []): array {
        return array_merge([
            'name' => 'Beispiel GmbH',
            'url'  => 'https://www.example.org',
        ], $overrides);
    }

    function testGlobalOrgRelationReferenceEmitsStandalonePersonNode(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', [
            'name' => 'Max Mustermann',
            'jobTitle' => 'Geschäftsführer',
            'image' => 'personen/max.jpg',
        ]);
        $this->settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            'org_relations' => [['person' => 'max-mustermann', 'role' => 'employee']],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertArrayHasKey('Person', $byType, 'Referenzierte Registry-Person muss als eigenständiger Knoten emittiert werden');
        $this->assertSame('https://www.example.org/#person-max-mustermann', $byType['Person']['@id']);
        $this->assertSame('Max Mustermann', $byType['Person']['name']);
        $this->assertSame('Geschäftsführer', $byType['Person']['jobTitle']);
        $this->assertSame('https://www.example.org/dateien/personen/max.jpg', $byType['Person']['image']);
        $this->assertSame('https://www.example.org/#person-max-mustermann', $byType['LocalBusiness']['employee'][0]['@id']);
    }

    function testNoPersonNodeWithoutReference(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann');
        $this->settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);
        // Keine Referenz gesetzt - Person bleibt trotz aktivem Registry-Eintrag unerwähnt.

        $blocks = $this->getJsonLdBlocks($plugin);
        $types = array_column($blocks, '@type');

        $this->assertNotContains('Person', $types);
    }

    function testDanglingOrgRelationToDeletedSlugEmitsNeitherNodeNorId(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        // Kein Registry-Eintrag - Referenz zeigt auf einen (mehr) nicht existierenden Slug.
        $this->settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            'org_relations' => [['person' => 'geloescht', 'role' => 'employee']],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertArrayNotHasKey('Person', $byType);
        $this->assertArrayNotHasKey('employee', $byType['LocalBusiness'],
            'Dangling-Referenz darf kein hängendes {"@id": ...} im LocalBusiness-Knoten hinterlassen');
    }

    /***************************************************************
    *
    * Kontrast-Test zur AP2-Statuslogik (siehe testGuardIncludesInactivePersonSlugAsActive()
    * oben): Für id_reference_or_literal-Referenzen (z. B. Event.organizer,
    * künftig Article.author) ist der Status einer referenzierten Person
    * für deren Emission irrelevant - eine inaktive Person bleibt bei
    * bestehender Referenz sichtbar. Für org_relations gilt bewusst das
    * Gegenteil (SRD Abschnitt 4.6): die Relation selbst (das Array-Element
    * im "employee"/"founder"/"member"-Property des Organisations-Knotens)
    * wird für eine inaktive Person NICHT ausgegeben. Die zugrundeliegende
    * Personen-Emission (applyDanglingReferenceGuard()/$activePersonSlugs)
    * bleibt dabei unverändert status-unabhängig - dieselbe Person kann
    * daher trotzdem als eigenständiger Knoten erscheinen, sofern sie noch
    * über eine andere, sichtbare Quelle referenziert wird. Beide
    * Verhaltensweisen dürfen künftig nicht versehentlich vereinheitlicht
    * werden.
    *
    ***************************************************************/
    function testInactivePersonOrgRelationNotEmittedInGroupedOutput(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann', ['status' => \SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE]);
        $this->settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            'org_relations' => [['person' => 'max-mustermann', 'role' => 'employee']],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertArrayNotHasKey('employee', $byType['LocalBusiness'],
            'Eine Relation zu einer inaktiven Person wird nicht in der gruppierten Ausgabe des Organisations-Knotens aufgeführt');

        // Reaktivierung: dieselbe Relation erscheint automatisch wieder,
        // ohne dass org_relations selbst erneut gespeichert werden muss -
        // die Relation blieb während der Inaktivität unverändert bestehen.
        $this->setActivePerson('max-mustermann', ['status' => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE]);
        $blocksReactivated = $this->getJsonLdBlocks($plugin);
        $byTypeReactivated = [];
        foreach($blocksReactivated as $block) {
            $byTypeReactivated[$block['@type']] = $block;
        }

        $this->assertSame('https://www.example.org/#person-max-mustermann', $byTypeReactivated['LocalBusiness']['employee'][0]['@id']);
    }

    function testTwoOrgRelationsToSameSlugEmitOnlyOnePersonNode(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $this->setActivePerson('max-mustermann');
        // Zwei Relationen (unterschiedliche Rollen) referenzieren denselben Slug.
        $this->settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            'org_relations' => [
                ['person' => 'max-mustermann', 'role' => 'founder'],
                ['person' => 'max-mustermann', 'role' => 'employee'],
            ],
        ]);

        $blocks = $this->getJsonLdBlocks($plugin);
        $personBlocks = array_values(array_filter($blocks, fn(array $b): bool => ($b['@type'] ?? '') === 'Person'));
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertCount(1, $personBlocks, 'Zwei Relationen auf denselben Slug dürfen nur einen Personen-Knoten erzeugen');
        $this->assertSame('https://www.example.org/#person-max-mustermann', $byType['LocalBusiness']['founder'][0]['@id']);
        $this->assertSame('https://www.example.org/#person-max-mustermann', $byType['LocalBusiness']['employee'][0]['@id']);
    }

    // -----------------------------------------------------------
    // validateAgainstSchema() - id_reference_or_literal überspringen
    // (generischer Widget-Mechanismus, nicht Person-spezifisch)
    // -----------------------------------------------------------

    function testValidateAgainstSchemaSkipsIdRefOrLiteralInRequired(): void {
        $plugin = $this->createPlugin();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'TestIdRefType');

        $result = (new \SchemaOrgData_Validator())->validateAgainstSchema([], $schema, new \SchemaOrgData_SchemaRepository());

        $this->assertSame([], $result['errors'],
            'id_reference_or_literal muss in der required-Prüfung übersprungen werden');
    }
}
