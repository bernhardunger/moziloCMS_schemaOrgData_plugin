<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_ImportService.
* Einziger Kollaborator ist SchemaOrgData_DataSplitHelper, zustandslos.
*
***************************************************************/
final class ImportServiceTest extends TestCase {

    private function schema(): array {
        return [
            'properties' => [
                'name' => ['type' => 'string'],
                'url'  => ['type' => 'string'],
            ],
        ];
    }

    function testGueltigesJsonLdWirdInFormUndExtensionAufgeteilt(): void {
        $service = new \SchemaOrgData_ImportService();
        $dataSplitHelper = new \SchemaOrgData_DataSplitHelper();

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'hasMap' => 'https://maps.example.org',
        ]);

        $result = $service->importJsonLd($json, $this->schema(), $dataSplitHelper);

        $this->assertTrue($result['success']);
        $this->assertSame('LocalBusiness', $result['type']);
        $this->assertSame(['name' => 'Muster GmbH'], $result['formData']);
        $this->assertSame(['hasMap' => 'https://maps.example.org'], $result['extensionData']);
    }

    /***************************************************************
    *
    * Nebeneffekt der geo-Schema-Ergänzung: "geo" ist eine bekannte
    * Property des Haupt-Schemas (siehe schemas/
    * LocalBusiness.json etc.) - splitDataForRendering() arbeitet rein
    * property-namens-basiert (siehe DataSplitHelperTest), ein
    * importiertes "geo"-Objekt landet daher automatisch in formData
    * statt extensionData, ohne dass importJsonLd() selbst geo-spezifisch
    * wissen muss.
    *
    ***************************************************************/
    function testImportiertesGeoLandetInFormDataStattExtensionData(): void {
        $service = new \SchemaOrgData_ImportService();
        $dataSplitHelper = new \SchemaOrgData_DataSplitHelper();
        $schema = $this->schema();
        $schema['properties']['geo'] = ['type' => 'object'];

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Muster GmbH',
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 48.12567, 'longitude' => 11.64278],
        ]);

        $result = $service->importJsonLd($json, $schema, $dataSplitHelper);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('geo', $result['formData']);
        $this->assertArrayNotHasKey('geo', $result['extensionData']);
    }

    function testUngueltigesJsonWirdAlsFehlerGemeldet(): void {
        $service = new \SchemaOrgData_ImportService();
        $dataSplitHelper = new \SchemaOrgData_DataSplitHelper();

        $result = $service->importJsonLd('{ungültiges json', $this->schema(), $dataSplitHelper);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertNull($result['type']);
        $this->assertSame([], $result['formData']);
        $this->assertSame([], $result['extensionData']);
    }

    function testLeererJsonLdBlockWirdKorrektBehandelt(): void {
        $service = new \SchemaOrgData_ImportService();
        $dataSplitHelper = new \SchemaOrgData_DataSplitHelper();

        $result = $service->importJsonLd('{}', $this->schema(), $dataSplitHelper);

        $this->assertTrue($result['success']);
        $this->assertNull($result['type']);
        $this->assertSame([], $result['formData']);
        $this->assertSame([], $result['extensionData']);
    }
}
