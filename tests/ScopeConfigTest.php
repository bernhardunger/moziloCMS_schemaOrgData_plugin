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
        $scopeResolver = new \SchemaOrgData_ScopeResolver();
        return $scopeResolver->mergeConfigs(...$configs);
    }

    function testGlobalConfigAlone(): void {
        $global = ['LocalBusiness' => ['name' => 'Muster GmbH', 'url' => 'https://example.com']];

        $this->assertSame($global, $this->merge($global));
    }

}
