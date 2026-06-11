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
}
