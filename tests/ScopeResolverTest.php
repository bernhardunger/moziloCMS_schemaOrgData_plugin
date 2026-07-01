<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_ScopeResolver:
*
*   - getScopeSettingsKey(): Key-Ermittlung je Geltungsebene
*   - sanitizeScopeIdentifier(): Path-Traversal-Schutz
*   - mergeConfigs(): feldweises Überschreiben über mehrere Ebenen
*   - resolveTypeInheritance(): Merge auf spezifischster Ebene
*   - loadScopeConfig(): $settings wird als Parameter genutzt, kein
*     Objektzustand
*   - loadScopeMeta()/saveScopeMeta(): Defaults, Merge, Round-Trip
*   - deleteConfig(): existierender/fehlender Key, ungültiger Scope
*   - detectTypeCollision(): Kollisionen je Geltungsebene
*   - Delegator-Vertrag der Fassade (callPluginMethod) bleibt erhalten
*
***************************************************************/
final class ScopeResolverTest extends TestCase {

    private function resolver(): \SchemaOrgData_ScopeResolver {
        return new \SchemaOrgData_ScopeResolver();
    }

    // getScopeSettingsKey() ---------------------------------------------------

    function testGetScopeSettingsKeyGlobal(): void {
        $this->assertSame('config_global', $this->resolver()->getScopeSettingsKey('global'));
    }

    function testGetScopeSettingsKeyCategoryMitBezeichner(): void {
        $this->assertSame('config_cat_impressum', $this->resolver()->getScopeSettingsKey('category', 'impressum'));
    }

    function testGetScopeSettingsKeyCategoryOhneBezeichnerLiefertNull(): void {
        $this->assertNull($this->resolver()->getScopeSettingsKey('category'));
    }

    function testGetScopeSettingsKeyPageMitBeidenBezeichnern(): void {
        $this->assertSame('config_page_kat_seite', $this->resolver()->getScopeSettingsKey('page', 'kat', 'seite'));
    }

    function testGetScopeSettingsKeyPageOhneSeiteLiefertNull(): void {
        $this->assertNull($this->resolver()->getScopeSettingsKey('page', 'kat'));
    }

    function testGetScopeSettingsKeyUngueltigerScopeLiefertNull(): void {
        $this->assertNull($this->resolver()->getScopeSettingsKey('nicht-existent'));
    }

    // sanitizeScopeIdentifier() -----------------------------------------------

    function testSanitizeScopeIdentifierErlaubteZeichenBleibenErhalten(): void {
        $this->assertSame('Kat-Name_123%20', $this->resolver()->sanitizeScopeIdentifier('Kat-Name_123%20'));
    }

    function testSanitizeScopeIdentifierEntferntPathTraversalZeichen(): void {
        $this->assertSame('etcpasswd', $this->resolver()->sanitizeScopeIdentifier('../../etc/passwd'));
        $this->assertSame('windowssystem32', $this->resolver()->sanitizeScopeIdentifier('..\\windows\\system32'));
    }

    function testSanitizeScopeIdentifierBehaeltProzentzeichen(): void {
        $this->assertSame('a%2Fb', $this->resolver()->sanitizeScopeIdentifier('a%2Fb'));
    }

    // mergeConfigs() -----------------------------------------------------------

    function testMergeConfigsSpaetereUeberschreibtFruehereFelder(): void {
        $global = ['LocalBusiness' => ['name' => 'Global', 'telephone' => '+49 89 11111111']];
        $category = ['LocalBusiness' => ['name' => 'Kategorie']];

        $result = $this->resolver()->mergeConfigs($global, $category);

        $this->assertSame('Kategorie', $result['LocalBusiness']['name']);
        $this->assertSame('+49 89 11111111', $result['LocalBusiness']['telephone']);
    }

    function testMergeConfigsLeereArraysWerdenUebersprungen(): void {
        $global = ['LocalBusiness' => ['name' => 'Muster GmbH']];

        $this->assertSame($global, $this->resolver()->mergeConfigs($global, [], []));
    }

    // resolveTypeInheritance() --------------------------------------------------

    function testResolveTypeInheritanceMergtAufSpezifischsteEbene(): void {
        $scopeConfigs = [
            'global' => ['LocalBusiness' => ['name' => 'Global', 'email' => 'global@example.com']],
            'category' => ['LocalBusiness' => ['name' => 'Kategorie']],
            'page' => [],
        ];

        $result = $this->resolver()->resolveTypeInheritance($scopeConfigs);

        $this->assertSame([], $result['global']);
        $this->assertSame('Kategorie', $result['category']['LocalBusiness']['name']);
        $this->assertSame('global@example.com', $result['category']['LocalBusiness']['email']);
        $this->assertArrayNotHasKey('LocalBusiness', $result['page'] ?? []);
    }

    function testResolveTypeInheritanceUnabhaengigeTypesBleibenGetrennt(): void {
        $scopeConfigs = [
            'global' => ['Organization' => ['name' => 'Firma']],
            'category' => ['FAQPage' => ['mainEntity' => []]],
        ];

        $result = $this->resolver()->resolveTypeInheritance($scopeConfigs);

        $this->assertSame(['Organization' => ['name' => 'Firma']], $result['global']);
        $this->assertSame(['FAQPage' => ['mainEntity' => []]], $result['category']);
    }

    // loadScopeConfig() ---------------------------------------------------------

    function testLoadScopeConfigLiefertLeeresArrayFuerFehlendenKey(): void {
        $settings = new \InMemorySettings();

        $this->assertSame([], $this->resolver()->loadScopeConfig($settings, 'global'));
    }

    function testLoadScopeConfigLiefertGespeichertesArray(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);

        $result = $this->resolver()->loadScopeConfig($settings, 'global');

        $this->assertSame(['LocalBusiness' => ['name' => 'Muster GmbH']], $result);
    }

    function testLoadScopeConfigNutztUebergebenesSettingsObjektNichtObjektzustand(): void {
        $resolver = $this->resolver();

        $settingsA = new \InMemorySettings();
        $settingsA->set('config_global', ['LocalBusiness' => ['name' => 'A']]);

        $settingsB = new \InMemorySettings();
        $settingsB->set('config_global', ['LocalBusiness' => ['name' => 'B']]);

        $this->assertSame('A', $resolver->loadScopeConfig($settingsA, 'global')['LocalBusiness']['name']);
        $this->assertSame('B', $resolver->loadScopeConfig($settingsB, 'global')['LocalBusiness']['name']);
    }

    // loadScopeMeta() / saveScopeMeta() ------------------------------------------

    function testLoadScopeMetaLiefertDefaultsFuerFehlendenKey(): void {
        $settings = new \InMemorySettings();

        $result = $this->resolver()->loadScopeMeta($settings, 'global');

        $this->assertSame([
            'existing_jsonld' => false,
            'jsonld_mode' => 'keep',
            'existing_jsonld_content' => '',
        ], $result);
    }

    function testSaveScopeMetaUndLoadScopeMetaRoundTrip(): void {
        $settings = new \InMemorySettings();
        $resolver = $this->resolver();

        $resolver->saveScopeMeta($settings, 'global', ['existing_jsonld' => true, 'jsonld_mode' => 'override']);
        $result = $resolver->loadScopeMeta($settings, 'global');

        $this->assertTrue($result['existing_jsonld']);
        $this->assertSame('override', $result['jsonld_mode']);
    }

    function testSaveScopeMetaMergtMitBestehendenMetaOhneSchemaTypesZuVeraendern(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => ['name' => 'Muster GmbH'],
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => 'x'],
        ]);
        $resolver = $this->resolver();

        $resolver->saveScopeMeta($settings, 'global', ['jsonld_mode' => 'override']);

        $data = $settings->get('config_global');
        $this->assertSame(['name' => 'Muster GmbH'], $data['LocalBusiness']);
        $this->assertTrue($data['_meta']['existing_jsonld']);
        $this->assertSame('override', $data['_meta']['jsonld_mode']);
    }

    // deleteConfig() -------------------------------------------------------------

    function testDeleteConfigLoeschtExistierendenKey(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);

        $result = $this->resolver()->deleteConfig($settings, 'global');

        $this->assertSame(['success' => true, 'errors' => []], $result);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    function testDeleteConfigFehlenderKeyLiefertErfolgOhneFehler(): void {
        $settings = new \InMemorySettings();

        $result = $this->resolver()->deleteConfig($settings, 'global');

        $this->assertSame(['success' => true, 'errors' => []], $result);
    }

    function testDeleteConfigUngueltigerScopeLiefertMisserfolg(): void {
        $settings = new \InMemorySettings();

        $result = $this->resolver()->deleteConfig($settings, 'category');

        $this->assertSame(['success' => false, 'errors' => []], $result);
    }

    // detectTypeCollision() -------------------------------------------------------

    function testDetectTypeCollisionGlobalLiefertImmerLeeresArray(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);

        $this->assertSame([], $this->resolver()->detectTypeCollision($settings, 'global', null, null, 'LocalBusiness'));
    }

    function testDetectTypeCollisionCategoryMitGlobalTreffer(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);

        $result = $this->resolver()->detectTypeCollision($settings, 'category', 'kat', null, 'LocalBusiness');

        $this->assertSame(['global'], $result);
    }

    function testDetectTypeCollisionCategoryOhneTreffer(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['Organization' => ['name' => 'Firma']]);

        $result = $this->resolver()->detectTypeCollision($settings, 'category', 'kat', null, 'LocalBusiness');

        $this->assertSame([], $result);
    }

    function testDetectTypeCollisionPageMitGlobalUndCategoryTreffer(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global']]);
        $settings->set('config_cat_kat', ['LocalBusiness' => ['name' => 'Kategorie']]);

        $result = $this->resolver()->detectTypeCollision($settings, 'page', 'kat', 'seite', 'LocalBusiness');

        $this->assertSame(['global', 'category'], $result);
    }

    // Delegator-Vertrag der Fassade ------------------------------------------------

    function testFassadeDelegiertGetScopeSettingsKeyWeiterhinKorrekt(): void {
        $plugin = new \schemaOrgData();

        $result = callPluginMethod($plugin, 'getScopeSettingsKey', ['category', 'kat']);

        $this->assertSame('config_cat_kat', $result);
    }

    function testFassadeDelegiertLoadScopeConfigMitSettingsWeiterhinKorrekt(): void {
        $plugin = new \schemaOrgData();
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);
        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $settings);

        $result = callPluginMethod($plugin, 'loadScopeConfig', ['global']);

        $this->assertSame(['LocalBusiness' => ['name' => 'Muster GmbH']], $result);
    }
}
