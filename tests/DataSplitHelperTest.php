<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_DataSplitHelper.
* Zustandslose Instanz ohne Abhängigkeiten - reine
* Array-Transformation, geteilter Mapper für FormRenderer und
* ImportService (siehe auch importJsonLd()-Delegation in index.php).
*
***************************************************************/
final class DataSplitHelperTest extends TestCase {

    private function schema(): array {
        return [
            'properties' => [
                'name' => ['type' => 'string'],
                'url'  => ['type' => 'string'],
            ],
        ];
    }

    function testBekanntePropertiesLandenInFormUnbekannteInExtension(): void {
        $helper = new \SchemaOrgData_DataSplitHelper();

        $result = $helper->splitDataForRendering(
            ['name' => 'Muster GmbH', 'hasMap' => 'https://maps.example.org'],
            $this->schema()
        );

        $this->assertSame(['name' => 'Muster GmbH'], $result['form']);
        $this->assertSame(['hasMap' => 'https://maps.example.org'], $result['extension']);
    }

    function testNullSchemaLegtAllesInExtension(): void {
        $helper = new \SchemaOrgData_DataSplitHelper();

        $result = $helper->splitDataForRendering(
            ['name' => 'Muster GmbH', 'url' => 'https://example.org'],
            null
        );

        $this->assertSame([], $result['form']);
        $this->assertSame(['name' => 'Muster GmbH', 'url' => 'https://example.org'], $result['extension']);
    }

    function testLeeresDataArrayErgibtLeereFormUndExtension(): void {
        $helper = new \SchemaOrgData_DataSplitHelper();

        $result = $helper->splitDataForRendering([], $this->schema());

        $this->assertSame([], $result['form']);
        $this->assertSame([], $result['extension']);
    }
}
