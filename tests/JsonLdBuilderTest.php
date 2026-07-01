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

    /**
     * Ruft buildJsonLdScript() auf und liefert den dekodierten
     * JSON-LD-Inhalt als Array zurück.
     */
    private function build(string $type, array $data): array {
        $plugin = new \schemaOrgData();
        $script = callPluginMethod($plugin, 'buildJsonLdScript', [$type, $data]);

        $this->assertMatchesRegularExpression(
            '#^<script type="application/ld\+json">\n(.*)\n</script>\n$#s',
            $script
        );

        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    function testRequiredFieldsProduceValidJsonLd(): void {
        $decoded = $this->build('LocalBusiness', [
            'name' => 'Muster GmbH',
            'url' => 'https://example.com',
        ]);

        $this->assertSame('Muster GmbH', $decoded['name']);
        $this->assertSame('https://example.com', $decoded['url']);
    }

    function testContextAndTypeAreAlwaysPresent(): void {
        $decoded = $this->build('Organization', ['name' => 'Beispiel GmbH']);

        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertSame('Organization', $decoded['@type']);
    }

    function testPostalAddressIsNestedCorrectly(): void {
        $decoded = $this->build('LocalBusiness', [
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

    function testOpeningHoursArrayIsFormattedCorrectly(): void {
        $decoded = $this->build('LocalBusiness', [
            'name' => 'Muster GmbH',
            'openingHours' => ['Mo-Fr 09:00-18:00', 'Sa 10:00-14:00'],
        ]);

        $this->assertSame(['Mo-Fr 09:00-18:00', 'Sa 10:00-14:00'], $decoded['openingHours']);
    }

    function testOpeningHoursWithSecondRangeProducesTwoEntries(): void {
        $decoded = $this->build('LocalBusiness', [
            'name' => 'Muster GmbH',
            'openingHours' => ['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00'],
        ]);

        $this->assertContains('Mo-Fr 08:00-12:00', $decoded['openingHours']);
        $this->assertContains('Mo-Fr 13:00-17:00', $decoded['openingHours']);
        $this->assertCount(2, $decoded['openingHours']);
    }

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
}

