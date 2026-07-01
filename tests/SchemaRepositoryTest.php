<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_SchemaRepository:
*
*   - loadSchema(): existierender/nicht existierender Type,
*     $pluginSelfDir wird als Parameter durchgereicht
*   - resolveSchemaRef(): kein "$ref", aufgelöster "$ref" mit
*     lokalem Override, "$ref" ohne führendes "#/", "$ref" auf
*     nicht existierenden Pfad
*   - getAvailableSchemaTypes(): alphabetisch sortierte Liste
*     passend zu den echten Dateien in schemas/
*   - Delegator-Vertrag der Fassade (callPluginMethod) bleibt
*     erhalten
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

    // Delegator-Vertrag der Fassade ------------------------------------------

    function testFassadeDelegiertLoadSchemaWeiterhinKorrekt(): void {
        $plugin = new \schemaOrgData();

        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $this->assertIsArray($schema);
        $this->assertSame('LocalBusiness', $schema['title']);
    }
}
