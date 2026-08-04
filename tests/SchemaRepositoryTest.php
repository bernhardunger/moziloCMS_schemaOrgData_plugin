<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_SchemaRepository:
*
*   - loadSchema(): existierender/nicht existierender Type, Type-Name
*     mit Pfadanteil, $pluginSelfDir wird als Parameter durchgereicht
*   - resolveSchemaRef(): kein "$ref", aufgelöster "$ref" mit
*     lokalem Override, "$ref" ohne führendes "#/", "$ref" auf
*     nicht existierenden Pfad
*   - getAvailableSchemaTypes(): alphabetisch sortierte Liste
*     passend zu den echten Dateien in schemas/
*
***************************************************************/
final class SchemaRepositoryTest extends TestCase {

    private function repository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function pluginSelfDir(): string {
        return BASE_DIR.PLUGIN_DIR_NAME.'/schemaOrgData/';
    }

    // loadSchema() ----------------------------------------------------------

    function testLoadSchemaLiefertArrayFuerExistierendenType(): void {
        $schema = $this->repository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertSame('LocalBusiness', $schema['title']);
    }

    function testLoadSchemaLiefertNullFuerNichtExistierendenType(): void {
        $schema = $this->repository()->loadSchema($this->pluginSelfDir(), 'DoesNotExist');

        $this->assertNull($schema);
    }

    function testLoadSchemaNutztUebergebenenPluginSelfDirNichtObjektzustand(): void {
        $repository = $this->repository();

        $real = $repository->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        $missing = $repository->loadSchema(sys_get_temp_dir().'/', 'LocalBusiness');

        $this->assertIsArray($real);
        $this->assertNull($missing);
    }

    function testLoadSchemaLiefertNullFuerTypeMitPfadanteil(): void {
        $repository = $this->repository();

        // Alle vier Schreibweisen würden per basename() auf "LocalBusiness"
        // normalisieren; der Aufrufer arbeitet danach aber mit dem rohen Wert
        // weiter und emittiert ihn als "@type".
        foreach(['./LocalBusiness', 'foo/LocalBusiness', '../LocalBusiness', 'LocalBusiness/'] as $type) {
            $this->assertNull($repository->loadSchema($this->pluginSelfDir(), $type),
                'Type-Name mit Pfadanteil darf kein Schema laden: '.$type);
        }
    }

    // resolveSchemaRef() ------------------------------------------------------

    function testResolveSchemaRefOhneRefBleibtUnveraendert(): void {
        $fieldSchema = ['type' => 'string', 'ui:widget' => 'text'];

        $result = $this->repository()->resolveSchemaRef($fieldSchema, ['definitions' => []]);

        $this->assertSame($fieldSchema, $result);
    }

    function testResolveSchemaRefLoestVorhandenePostalAddressAuf(): void {
        $rootSchema = $this->repository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
        $fieldSchema = $rootSchema['properties']['address'];

        $result = $this->repository()->resolveSchemaRef($fieldSchema, $rootSchema);

        $this->assertArrayNotHasKey('$ref', $result);
        $this->assertSame('PostalAddress', $result['title']);
        $this->assertArrayHasKey('addressLocality', $result['properties']);
    }

    function testResolveSchemaRefLokaleFelderUeberschreibenReferenzierteFelder(): void {
        $rootSchema = [
            'definitions' => [
                'Foo' => ['ui:widget' => 'postal_address', 'ui:required' => false],
            ],
        ];
        $fieldSchema = ['$ref' => '#/definitions/Foo', 'ui:required' => true];

        $result = $this->repository()->resolveSchemaRef($fieldSchema, $rootSchema);

        $this->assertArrayNotHasKey('$ref', $result);
        $this->assertSame('postal_address', $result['ui:widget']);
        $this->assertTrue($result['ui:required']);
    }

    function testResolveSchemaRefOhneFuehrendesHashSlashBleibtUnveraendert(): void {
        $fieldSchema = ['$ref' => 'definitions/Foo'];

        $result = $this->repository()->resolveSchemaRef($fieldSchema, ['definitions' => ['Foo' => ['a' => 1]]]);

        $this->assertSame($fieldSchema, $result);
    }

    function testResolveSchemaRefAufNichtExistierendenPfadBleibtUnveraendert(): void {
        $fieldSchema = ['$ref' => '#/definitions/DoesNotExist'];

        $result = $this->repository()->resolveSchemaRef($fieldSchema, ['definitions' => []]);

        $this->assertSame($fieldSchema, $result);
    }

    // getAvailableSchemaTypes() ------------------------------------------------

    function testGetAvailableSchemaTypesLiefertAlphabetischSortierteListe(): void {
        $expected = [];
        foreach(glob($this->pluginSelfDir().'schemas/*.json') as $file) {
            $expected[] = basename($file, '.json');
        }
        sort($expected);

        $result = $this->repository()->getAvailableSchemaTypes($this->pluginSelfDir());

        $this->assertSame($expected, $result);
    }

    // Caching (Backlog-Punkt "Optionales Caching in SchemaRepository") -------

    private function tempSchemaDir(): string {
        $dir = sys_get_temp_dir().'/schemaOrgData_repocache_'.uniqid().'/schemas/';
        mkdir($dir, 0777, true);
        return dirname($dir).'/';
    }

    private function removeDirectory(string $dir): void {
        foreach(glob(rtrim($dir, '/').'/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    function testLoadSchemaCachtErgebnisInnerhalbDerInstanz(): void {
        $pluginSelfDir = $this->tempSchemaDir();
        file_put_contents($pluginSelfDir.'schemas/Cached.json', json_encode(['title' => 'Original']));

        $repository = $this->repository();
        $first = $repository->loadSchema($pluginSelfDir, 'Cached');

        file_put_contents($pluginSelfDir.'schemas/Cached.json', json_encode(['title' => 'Geaendert']));
        $second = $repository->loadSchema($pluginSelfDir, 'Cached');

        $this->assertSame('Original', $first['title']);
        $this->assertSame('Original', $second['title'],
            'Zweiter Aufruf auf derselben Instanz muss das gecachte Ergebnis liefern, nicht den geänderten Disk-Zustand.');

        $this->removeDirectory($pluginSelfDir);
    }

    function testLoadSchemaCachtAuchNullErgebnis(): void {
        $pluginSelfDir = $this->tempSchemaDir();

        $repository = $this->repository();
        $first = $repository->loadSchema($pluginSelfDir, 'NochNichtVorhanden');
        $this->assertNull($first);

        file_put_contents($pluginSelfDir.'schemas/NochNichtVorhanden.json', json_encode(['title' => 'JetztDa']));
        $second = $repository->loadSchema($pluginSelfDir, 'NochNichtVorhanden');

        $this->assertNull($second,
            'Ein gecachtes null-Ergebnis darf durch nachträgliches Anlegen der Datei nicht überschrieben werden.');

        $this->removeDirectory($pluginSelfDir);
    }

    function testGetAvailableSchemaTypesCachtErgebnisInnerhalbDerInstanz(): void {
        $pluginSelfDir = $this->tempSchemaDir();
        file_put_contents($pluginSelfDir.'schemas/Alpha.json', json_encode(['title' => 'Alpha']));

        $repository = $this->repository();
        $first = $repository->getAvailableSchemaTypes($pluginSelfDir);

        file_put_contents($pluginSelfDir.'schemas/Beta.json', json_encode(['title' => 'Beta']));
        $second = $repository->getAvailableSchemaTypes($pluginSelfDir);

        $this->assertSame(['Alpha'], $first);
        $this->assertSame(['Alpha'], $second,
            'Zweiter Aufruf auf derselben Instanz muss die gecachte Liste liefern, nicht den erweiterten Disk-Zustand.');

        $this->removeDirectory($pluginSelfDir);
    }

    function testCacheGiltNichtInstanzUebergreifend(): void {
        $pluginSelfDir = $this->tempSchemaDir();
        file_put_contents($pluginSelfDir.'schemas/Cached.json', json_encode(['title' => 'Original']));

        $first = $this->repository()->loadSchema($pluginSelfDir, 'Cached');

        file_put_contents($pluginSelfDir.'schemas/Cached.json', json_encode(['title' => 'Geaendert']));
        $second = $this->repository()->loadSchema($pluginSelfDir, 'Cached');

        $this->assertSame('Original', $first['title']);
        $this->assertSame('Geaendert', $second['title'],
            'Eine neue Instanz darf keinen von einer anderen Instanz geteilten Cache-Zustand sehen.');

        $this->removeDirectory($pluginSelfDir);
    }

    // resolveActiveType() ------------

    function testResolveActiveTypeLiefertErstenTypeMitBekanntemSchema(): void {
        $config = ['AccountingService' => ['name' => 'Kanzlei Muster']];

        $result = $this->repository()->resolveActiveType($config, $this->pluginSelfDir());

        $this->assertSame('AccountingService', $result);
    }

    function testResolveActiveTypeUeberspringtReservierteSchluessel(): void {
        $config = [
            '_meta' => ['jsonld_mode' => 'keep'],
            'excluded_cats' => 'impressum',
            'debug_output' => true,
            'AccountingService' => ['name' => 'Kanzlei Muster'],
        ];

        $result = $this->repository()->resolveActiveType($config, $this->pluginSelfDir());

        $this->assertSame('AccountingService', $result);
    }

    function testResolveActiveTypeLiefertNullBeiLeeremConfig(): void {
        $result = $this->repository()->resolveActiveType([], $this->pluginSelfDir());

        $this->assertNull($result);
    }

    function testResolveActiveTypeLiefertNullWennKeinSchluesselBekanntesSchemaReferenziert(): void {
        $config = ['_meta' => [], 'excluded_cats' => '', 'debug_output' => false, 'org_relations' => []];

        $result = $this->repository()->resolveActiveType($config, $this->pluginSelfDir());

        $this->assertNull($result);
    }

    function testResolveActiveTypeLiefertNullFuerSchluesselMitPfadanteil(): void {
        $config = ['./LocalBusiness' => ['name' => 'Kanzlei Muster']];

        $result = $this->repository()->resolveActiveType($config, $this->pluginSelfDir());

        $this->assertNull($result);
    }
}
