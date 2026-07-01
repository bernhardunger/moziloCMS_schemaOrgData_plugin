<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für buildJsonLdScript() - Erzeugung des
* <script type="application/ld+json">-Blocks.
*
***************************************************************/
final class JsonLdBuilderTest extends TestCase {

    /***************************************************************
    *
    * Direkt-Tests der Komponente SchemaOrgData_JsonLdBuilder
    * (Refactoring-Schritt 5, siehe doc/adr_komponenten_refactoring.md).
    * Echte, zustandslose SchemaRepository-/UrlHelper-Instanzen,
    * $pluginSelfDir zeigt auf die realen Schema-Fixtures des Plugins.
    *
    ***************************************************************/

    private function pluginSelfDir(): string {
        return BASE_DIR.'plugins/schemaOrgData/';
    }

    function testResolveNodeIdDeDupliziertGeteiltesFragmentUeberZweiAufrufe(): void {
        $builder = new \SchemaOrgData_JsonLdBuilder();
        $schemaRepo = new \SchemaOrgData_SchemaRepository();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assignedFragments = [];

        $firstId = $builder->resolveNodeId(
            $schemaRepo, $urlHelper, $this->pluginSelfDir(), 'NGO', $assignedFragments
        );
        $secondId = $builder->resolveNodeId(
            $schemaRepo, $urlHelper, $this->pluginSelfDir(), 'NGO', $assignedFragments
        );

        $this->assertSame('http://example.com/#organization', $firstId);
        $this->assertSame('', $secondId);
        $this->assertSame(['organization'], $assignedFragments);
    }

    function testBuildJsonLdScriptGibtLeerenKnotenNichtAllenDurchIdOderTypeAlsNichtleerAus(): void {
        $builder = new \SchemaOrgData_JsonLdBuilder();
        $schemaRepo = new \SchemaOrgData_SchemaRepository();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $script = $builder->buildJsonLdScript(
            $schemaRepo, $urlHelper, $this->pluginSelfDir(),
            'Organization', [], 'https://example.com/#organization'
        );

        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => 'https://example.com/#organization',
        ], $decoded);
    }

    /***************************************************************
    *
    * Test-Migration Phase 1b-1 (kein Fahrplan-Schritt, siehe
    * doc/adr_komponenten_refactoring.md Punkt 14 "Aufräumen
    * (BLOCKIERT)"): Direkt-Tests, die dieselben Szenarien wie die
    * obigen bzw. externen Fassade-Tests (callPluginMethod()) prüfen,
    * hier jedoch direkt gegen SchemaOrgData_JsonLdBuilder statt gegen
    * die Fassade schemaOrgData. Reine Testverkabelung - kein
    * Verhaltens-Refactoring, Originaltests bleiben unverändert bestehen.
    *
    ***************************************************************/

    private array $serverBackup = [];
    private ?string $tempSchemaDir = null;

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
        if($this->tempSchemaDir !== null) {
            $this->removeDirectory($this->tempSchemaDir);
            $this->tempSchemaDir = null;
        }
    }

    private function removeDirectory(string $dir): void {
        foreach(glob(rtrim($dir, '/').'/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    /**
     * Instanziiert SchemaOrgData_JsonLdBuilder/-SchemaRepository/-UrlHelper
     * (Muster: die beiden Bestandstests oben) und ruft buildJsonLdScript()
     * gegen ein beliebiges $pluginSelfDir direkt auf.
     */
    private function buildViaComponent(string $pluginSelfDir, string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $builder = new \SchemaOrgData_JsonLdBuilder();
        $schemaRepo = new \SchemaOrgData_SchemaRepository();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $script = $builder->buildJsonLdScript(
            $schemaRepo, $urlHelper, $pluginSelfDir, $type, $data, $nodeId, $suppressedIdTargets
        );

        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'buildJsonLdScript muss valides JSON erzeugen');

        return $decoded;
    }

    /**
     * Legt ein isoliertes Schema-Verzeichnis mit dem Hilfsschema
     * TestIdRefType.json an (Property "organizer" als
     * id_reference_or_literal) - identischer Schema-Inhalt wie in
     * PersonIdRefOrLiteralTest::createPlugin(). buildJsonLdScript()
     * benötigt für dieses Schema keine weiteren Schema-Dateien (der
     * Referenz-Modus liest das Ziel-Fragment direkt aus dem
     * gespeicherten Wert, nicht aus dem Zielschema).
     */
    private function createTestIdRefTypeDir(): string {
        $dir = sys_get_temp_dir().'/schemaOrgData_jsonldbuilder_idrl_'.uniqid().'/';
        mkdir($dir.'schemas', 0777, true);

        $testSchema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title'   => 'TestIdRefType',
            'type'    => 'object',
            'ui:scopes' => ['page'],
            'required'  => ['organizer'],
            'properties' => [
                'organizer' => [
                    'type'                  => 'object',
                    'ui:widget'             => 'id_reference_or_literal',
                    'ui:label'              => 'label_name',
                    'ui:required'           => true,
                    'ui:literalFields'      => ['name', 'jobTitle'],
                    'ui:literalFieldLabels' => ['name' => 'label_name', 'jobTitle' => 'label_job_title'],
                    'ui:literalType'        => 'Person',
                ],
            ],
        ];
        file_put_contents(
            $dir.'schemas/TestIdRefType.json',
            json_encode($testSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->tempSchemaDir = $dir;
        return $dir;
    }

    // -----------------------------------------------------------
    // Basisfälle (migriert aus den Facade-Tests oben in dieser Datei)
    // -----------------------------------------------------------

    function testRequiredFieldsProduceValidJsonLdDirekt(): void {
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'LocalBusiness', [
            'name' => 'Muster GmbH',
            'url' => 'https://example.com',
        ]);

        $this->assertSame('Muster GmbH', $decoded['name']);
        $this->assertSame('https://example.com', $decoded['url']);
    }

    function testContextAndTypeAreAlwaysPresentDirekt(): void {
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'Organization', ['name' => 'Beispiel GmbH']);

        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertSame('Organization', $decoded['@type']);
    }

    function testPostalAddressIsNestedCorrectlyDirekt(): void {
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'LocalBusiness', [
            'name' => 'Muster GmbH',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Musterstraße 12',
                'postalCode' => '12345',
                'addressLocality' => 'Musterstadt',
                'addressCountry' => 'DE',
            ],
        ]);

        $this->assertSame('PostalAddress', $decoded['address']['@type']);
        $this->assertSame('Musterstraße 12', $decoded['address']['streetAddress']);
        $this->assertSame('Musterstadt', $decoded['address']['addressLocality']);
        $this->assertSame('DE', $decoded['address']['addressCountry']);
    }

    function testOpeningHoursArrayIsFormattedCorrectlyDirekt(): void {
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'LocalBusiness', [
            'name' => 'Muster GmbH',
            'openingHours' => ['Mo-Fr 09:00-18:00', 'Sa 10:00-14:00'],
        ]);

        $this->assertSame(['Mo-Fr 09:00-18:00', 'Sa 10:00-14:00'], $decoded['openingHours']);
    }

    function testOpeningHoursWithSecondRangeProducesTwoEntriesDirekt(): void {
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'LocalBusiness', [
            'name' => 'Muster GmbH',
            'openingHours' => ['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00'],
        ]);

        $this->assertContains('Mo-Fr 08:00-12:00', $decoded['openingHours']);
        $this->assertContains('Mo-Fr 13:00-17:00', $decoded['openingHours']);
        $this->assertCount(2, $decoded['openingHours']);
    }

    // -----------------------------------------------------------
    // @id-Einbettung und resolveNodeId()-Randfälle (migriert aus IdAnchorTest)
    // -----------------------------------------------------------

    function testResolveNodeIdEmptyForSchemaWithoutFragmentDirekt(): void {
        $builder = new \SchemaOrgData_JsonLdBuilder();
        $schemaRepo = new \SchemaOrgData_SchemaRepository();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $assigned = [];
        $id = $builder->resolveNodeId($schemaRepo, $urlHelper, $this->pluginSelfDir(), 'LocalBusiness', $assigned);

        $this->assertSame('', $id);
        $this->assertSame([], $assigned);
    }

    function testResolveNodeIdEmptyWhenBaseUrlUnresolvableDirekt(): void {
        $builder = new \SchemaOrgData_JsonLdBuilder();
        $schemaRepo = new \SchemaOrgData_SchemaRepository();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        unset($_SERVER['HTTP_HOST']);

        // Leer-Tilgungs-Guard: ohne Basis-URL wird KEIN (leeres) @id gebildet
        // und das Fragment bleibt unbelegt.
        $assigned = [];
        $id = $builder->resolveNodeId($schemaRepo, $urlHelper, $this->pluginSelfDir(), 'NGO', $assigned);

        $this->assertSame('', $id);
        $this->assertSame([], $assigned);
    }

    function testBuildJsonLdScriptInjectsIdAfterTypeDirekt(): void {
        $decoded = $this->buildViaComponent(
            $this->pluginSelfDir(), 'NGO', ['name' => 'Beispiel e. V.'], 'https://www.example.org/#organization'
        );

        $this->assertSame('https://www.example.org/#organization', $decoded['@id']);

        // @id steht direkt hinter @type (Reihenfolge im erzeugten JSON).
        $keys = array_keys($decoded);
        $this->assertSame(['@context', '@type', '@id'], array_slice($keys, 0, 3));
    }

    function testSetIdSurvivesEmptyPropertyFilterDirekt(): void {
        // Leer-Tilgungs-Guard auf Builder-Ebene: trotz leerer Properties
        // (die removeEmptyJsonLdProperties() entfernt) bleibt ein gesetztes
        // @id erhalten, weil es erst NACH dem Leerfilter eingefügt wird.
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'NGO', [
            'name' => 'Beispiel e. V.',
            'description' => '',
            'logo' => null,
        ], 'https://www.example.org/#organization');

        $this->assertSame('https://www.example.org/#organization', $decoded['@id']);
        $this->assertArrayNotHasKey('description', $decoded);
        $this->assertArrayNotHasKey('logo', $decoded);
    }

    // -----------------------------------------------------------
    // id_reference-Emission (migriert aus DonateActionTest)
    // -----------------------------------------------------------

    function testIdReferenceEmitsAtIdObjectWhenHostIsSetDirekt(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        // Leere data: recipient ist kein gespeichertes Feld,
        // wird aber trotzdem aus dem Schema emittiert.
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'DonateAction', []);

        $this->assertArrayHasKey('recipient', $decoded,
            'id_reference-Property muss zur Build-Zeit emittiert werden');
        $this->assertArrayHasKey('@id', $decoded['recipient'],
            'Emittierter Wert muss {"@id": "..."} sein');
        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    function testIdReferenceInsertedAfterEmptyPropertyFilterDirekt(): void {
        // Regressionsschutz analog NGO-Test aus 0.4.9-beta:
        // id_reference muss NACH removeEmptyJsonLdProperties() eingefügt werden,
        // damit auch bei leerem $data-Array die Emission erfolgt.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'DonateAction', [
            'description' => '',   // Wird durch removeEmptyJsonLdProperties entfernt
        ]);

        $this->assertArrayNotHasKey('description', $decoded,
            'Leere description muss durch den Leerfilter entfernt werden');
        $this->assertArrayHasKey('recipient', $decoded,
            'id_reference-Emission muss trotz leerem data-Array erfolgen');
        $this->assertSame('https://www.example.org/#organization', $decoded['recipient']['@id']);
    }

    function testIdReferenceNotEmittedWhenHostIsAbsentDirekt(): void {
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'DonateAction', []);

        // Ohne Host kann keine @id-URI gebildet werden → kein recipient.
        $this->assertArrayNotHasKey('recipient', $decoded);
    }

    function testIdReferenceNotEmittedWhenTargetSuppressedDirekt(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        // 'organization' ist in der Suppressionsliste (keep-Modus-Szenario).
        $decoded = $this->buildViaComponent($this->pluginSelfDir(), 'DonateAction', [], '', ['organization']);

        $this->assertArrayNotHasKey('recipient', $decoded,
            'id_reference darf bei suppressedIdTargets nicht emittiert werden');
    }

    // -----------------------------------------------------------
    // id_reference_or_literal-Emission (migriert aus PersonIdRefOrLiteralTest)
    // Referenz-Modus
    // -----------------------------------------------------------

    function testIdRefOrLiteralReferenceModeEmitsAtIdForOrganizationDirekt(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'reference', '_fragment' => 'organization']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayHasKey('organizer', $decoded,
            'Referenz-Modus muss Property "organizer" emittieren');
        $this->assertArrayHasKey('@id', $decoded['organizer'],
            'Referenz-Modus muss @id-Objekt erzeugen');
        $this->assertStringEndsWith('#organization', $decoded['organizer']['@id']);
    }

    function testIdRefOrLiteralReferenceModeEmitsAtIdForPersonDirekt(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'reference', '_fragment' => 'person']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayHasKey('organizer', $decoded);
        $this->assertArrayHasKey('@id', $decoded['organizer']);
        $this->assertStringEndsWith('#person', $decoded['organizer']['@id']);
    }

    function testIdRefOrLiteralReferenceModeInternalKeysNotInOutputDirekt(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'reference', '_fragment' => 'person']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $organizer = $decoded['organizer'];
        $this->assertArrayNotHasKey('_mode', $organizer,
            '_mode darf nicht im JSON-LD erscheinen');
        $this->assertArrayNotHasKey('_fragment', $organizer,
            '_fragment darf nicht im JSON-LD erscheinen');
    }

    function testIdRefOrLiteralReferenceModeSuppressedByGuardDirekt(): void {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'reference', '_fragment' => 'organization']];
        // 'organization' ist in suppressedIdTargets → Emission unterbleibt
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data, '', ['organization']);

        $this->assertArrayNotHasKey('organizer', $decoded,
            'Unterdrücktes Target darf kein organizer-Objekt emittieren');
    }

    function testIdRefOrLiteralEmptyStoredValueProducesNoPropertyDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        // Kein gespeicherter Wert
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', []);

        $this->assertArrayNotHasKey('organizer', $decoded);
    }

    // -----------------------------------------------------------
    // id_reference_or_literal-Emission (migriert aus PersonIdRefOrLiteralTest)
    // Literal-Modus
    // -----------------------------------------------------------

    function testIdRefOrLiteralLiteralModeEmitsEmbeddedObjectDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'literal', 'name' => 'Max Mustermann', 'jobTitle' => 'CEO']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayHasKey('organizer', $decoded);
        $organizer = $decoded['organizer'];
        $this->assertSame('Person', $organizer['@type'],
            'Literal-Modus muss ui:literalType als @type setzen');
        $this->assertSame('Max Mustermann', $organizer['name']);
        $this->assertSame('CEO', $organizer['jobTitle']);
    }

    function testIdRefOrLiteralLiteralModeHasNoAtIdDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'literal', 'name' => 'Max Mustermann']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayNotHasKey('@id', $decoded['organizer'],
            'Literal-Modus darf kein @id emittieren');
    }

    function testIdRefOrLiteralLiteralModeInternalKeysNotInOutputDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        $data = ['organizer' => ['_mode' => 'literal', 'name' => 'Max Mustermann']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayNotHasKey('_mode', $decoded['organizer']);
    }

    function testIdRefOrLiteralLiteralModeEmptyFieldsOmittedDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        // name befüllt, jobTitle leer → jobTitle darf nicht erscheinen
        $data = ['organizer' => ['_mode' => 'literal', 'name' => 'Max Mustermann', 'jobTitle' => '']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayHasKey('name', $decoded['organizer']);
        $this->assertArrayNotHasKey('jobTitle', $decoded['organizer']);
    }

    function testIdRefOrLiteralLiteralModeAllEmptyProducesNoPropertyDirekt(): void {
        $dir = $this->createTestIdRefTypeDir();
        // Alle Literal-Felder leer → Property entfällt
        $data = ['organizer' => ['_mode' => 'literal', 'name' => '', 'jobTitle' => '']];
        $decoded = $this->buildViaComponent($dir, 'TestIdRefType', $data);

        $this->assertArrayNotHasKey('organizer', $decoded);
    }
}

