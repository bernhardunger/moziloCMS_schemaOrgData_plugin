<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für loadScopeConfig() und mergeConfigs() - Vererbungslogik
* der Geltungsbereiche Global -> Kategorie -> Seite.
*
***************************************************************/
final class ScopeConfigTest extends TestCase {

    private function merge(array ...$configs): array {
        $plugin = new \schemaOrgData();
        return callPluginMethod($plugin, 'mergeConfigs', $configs);
    }

    function testGlobalConfigAlone(): void {
        $global = ['LocalBusiness' => ['name' => 'Muster GmbH', 'url' => 'https://example.com']];

        $this->assertSame($global, $this->merge($global));
    }

    function testCategoryOverridesGlobal(): void {
        $global = ['LocalBusiness' => ['name' => 'Muster GmbH', 'telephone' => '+49 89 11111111']];
        $category = ['LocalBusiness' => ['name' => 'Muster GmbH - Filiale Nord']];

        $result = $this->merge($global, $category);

        $this->assertSame('Muster GmbH - Filiale Nord', $result['LocalBusiness']['name']);
        $this->assertSame('+49 89 11111111', $result['LocalBusiness']['telephone']);
    }

    function testPageOverridesCategory(): void {
        $global = ['LocalBusiness' => ['name' => 'Global', 'email' => 'global@example.com']];
        $category = ['LocalBusiness' => ['name' => 'Kategorie', 'telephone' => '+49 89 11111111']];
        $page = ['LocalBusiness' => ['name' => 'Seite']];

        $result = $this->merge($global, $category, $page);

        $this->assertSame('Seite', $result['LocalBusiness']['name']);
        $this->assertSame('global@example.com', $result['LocalBusiness']['email']);
        $this->assertSame('+49 89 11111111', $result['LocalBusiness']['telephone']);
    }

    function testEmptyScopesAreSkipped(): void {
        $global = ['LocalBusiness' => ['name' => 'Muster GmbH']];

        $result = $this->merge($global, [], []);

        $this->assertSame($global, $result);
    }

    function testLoadScopeConfigReturnsEmptyArrayForMissingFiles(): void {
        $plugin = new \schemaOrgData();

        $this->assertSame([], callPluginMethod($plugin, 'loadScopeConfig', ['global']));
        $this->assertSame([], callPluginMethod($plugin, 'loadScopeConfig', ['category', 'nicht-vorhanden']));
        $this->assertSame([], callPluginMethod($plugin, 'loadScopeConfig', ['page', 'nicht-vorhanden', 'auch-nicht']));
    }
}
