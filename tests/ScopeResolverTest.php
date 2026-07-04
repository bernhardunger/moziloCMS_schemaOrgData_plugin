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

    // Encodierte und dekodierte Form eines Bezeichners liefern zwei
    // unterschiedliche, voneinander unabhängige Ergebnisse (Leerzeichen wird
    // entfernt, % bleibt erhalten) - keine Symmetrie-Aussage zwischen beiden
    // Formen, siehe F2-Diagnose in CLAUDE.md/doc/TODO.md.
    function testSanitizeScopeIdentifierEncodierteUndDekodierteFormUnterscheidenSich(): void {
        $this->assertSame('UnsereLeistungen', $this->resolver()->sanitizeScopeIdentifier('Unsere Leistungen'));
        $this->assertSame('Unsere%20Leistungen', $this->resolver()->sanitizeScopeIdentifier('Unsere%20Leistungen'));
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

    /***************************************************************
    *
    * Dreistufige Kaskade Global -> Kategorie -> Seite: die
    * spezifischere Ebene überschreibt feldweise, nicht ausgefüllte
    * Felder werden von der jeweils allgemeineren Ebene übernommen.
    *
    ***************************************************************/
    function testMergeConfigsDreistufigeKaskadeSeiteUeberschreibtKategorieUndGlobal(): void {
        $global = ['LocalBusiness' => ['name' => 'Global', 'email' => 'global@example.com']];
        $category = ['LocalBusiness' => ['name' => 'Kategorie', 'telephone' => '+49 89 11111111']];
        $page = ['LocalBusiness' => ['name' => 'Seite']];

        $result = $this->resolver()->mergeConfigs($global, $category, $page);

        $this->assertSame('Seite', $result['LocalBusiness']['name']);
        $this->assertSame('global@example.com', $result['LocalBusiness']['email']);
        $this->assertSame('+49 89 11111111', $result['LocalBusiness']['telephone']);
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

    /***************************************************************
    *
    * "name" ist auf Seiten-Ebene leer und damit (siehe
    * sanitizePostData()) gar nicht im gespeicherten Array enthalten.
    *
    ***************************************************************/
    function testResolveTypeInheritanceGleicherTypeAufKategorieUndSeiteMergtFelderAufSeite(): void {
        $scopeConfigs = [
            'global' => [],
            'category' => [
                'LocalBusiness' => [
                    'name' => 'Steuerkanzlei',
                    'telephone' => '+49891234567',
                ],
            ],
            'page' => [
                'LocalBusiness' => [
                    'telephone' => '+498987654321',
                ],
            ],
        ];

        $result = $this->resolver()->resolveTypeInheritance($scopeConfigs);

        $this->assertSame([], $result['category']);
        $this->assertSame('Steuerkanzlei', $result['page']['LocalBusiness']['name']);
        $this->assertSame('+498987654321', $result['page']['LocalBusiness']['telephone']);
    }

    /***************************************************************
    *
    * Wichtigster Fall: Verhalten bei verschachtelten Objekten
    * (address) — die spezifischere Ebene überschreibt das gesamte
    * Sub-Objekt, es findet kein Feld-Merge innerhalb von address
    * statt (streetAddress aus global bleibt daher nicht erhalten).
    *
    ***************************************************************/
    function testResolveTypeInheritanceVerschachtelteFelderUeberschreibenOhneFeldMerge(): void {
        $scopeConfigs = [
            'global' => [
                'LocalBusiness' => [
                    'name' => 'Beispiel GmbH',
                    'address' => [
                        'streetAddress' => 'Globalweg 1',
                        'addressLocality' => 'Globalstadt',
                        'addressCountry' => 'DE',
                    ],
                ],
            ],
            'category' => [
                'LocalBusiness' => [
                    'address' => [
                        'addressLocality' => 'Filialstadt',
                        'addressCountry' => 'DE',
                    ],
                ],
            ],
        ];

        $result = $this->resolver()->resolveTypeInheritance($scopeConfigs);
        $address = $result['category']['LocalBusiness']['address'];

        $this->assertSame('Beispiel GmbH', $result['category']['LocalBusiness']['name']);
        $this->assertSame('Filialstadt', $address['addressLocality']);
        $this->assertArrayNotHasKey('streetAddress', $address);
    }

    // loadScopeConfig() ---------------------------------------------------------

    function testLoadScopeConfigLiefertLeeresArrayFuerFehlendenKey(): void {
        $settings = new \InMemorySettings();

        $this->assertSame([], $this->resolver()->loadScopeConfig($settings, 'global'));
    }

    /***************************************************************
    *
    * Nur der category-Fall — der global-Fall ist bereits durch
    * testLoadScopeConfigLiefertLeeresArrayFuerFehlendenKey() abgedeckt.
    *
    ***************************************************************/
    function testLoadScopeConfigLiefertLeeresArrayFuerFehlendenCategoryKey(): void {
        $settings = new \InMemorySettings();

        $this->assertSame([], $this->resolver()->loadScopeConfig($settings, 'category', 'nicht-vorhanden'));
    }

    function testLoadScopeConfigLiefertLeeresArrayFuerFehlendenPageKey(): void {
        $settings = new \InMemorySettings();

        $this->assertSame([], $this->resolver()->loadScopeConfig($settings, 'page', 'nicht-vorhanden', 'auch-nicht'));
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

    /***************************************************************
    *
    * existing_jsonld_content wird bislang nur implizit über die
    * komplette Default-Struktur mitgetestet (siehe
    * testLoadScopeMetaLiefertDefaultsFuerFehlendenKey()) — hier
    * gezielt der Round-Trip über saveScopeMeta()/loadScopeMeta().
    *
    ***************************************************************/
    function testExistingJsonLdContentWirdGespeichertUndGeladen(): void {
        $settings = new \InMemorySettings();
        $content = '{"@context":"https://schema.org","@type":"LocalBusiness"}';
        $this->resolver()->saveScopeMeta($settings, 'global', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => $content,
        ]);
        $meta = $this->resolver()->loadScopeMeta($settings, 'global');
        $this->assertSame($content, $meta['existing_jsonld_content']);
    }

    /***************************************************************
    *
    * Fehlt der Key existing_jsonld_content in einem bereits
    * gespeicherten (älteren) _meta-Array, muss loadScopeMeta() ihn
    * als Leerstring nachliefern statt einen fehlenden Array-Key zu
    * werfen.
    *
    ***************************************************************/
    function testExistingJsonLdContentInLegacyMetaFaelltAufDefault(): void {
        $settings = new \InMemorySettings();
        $resolver = $this->resolver();
        $resolver->saveScopeMeta($settings, 'global', [
            'existing_jsonld' => true,
            'jsonld_mode' => 'keep',
        ]);
        $key = $resolver->getScopeSettingsKey('global');
        $stored = $settings->get($key);
        unset($stored['_meta']['existing_jsonld_content']);
        $settings->set($key, $stored);

        $meta = $resolver->loadScopeMeta($settings, 'global');
        $this->assertSame('', $meta['existing_jsonld_content'],
            'Fehlender Key in Legacy-Meta muss als Leerstring zurückgegeben werden');
    }

    /***************************************************************
    *
    * Global und Seite speichern existing_jsonld_content unabhängig
    * voneinander unter ihrem jeweiligen Settings-Key.
    *
    ***************************************************************/
    function testExistingJsonLdContentWirdProScopeGespeichert(): void {
        $settings = new \InMemorySettings();
        $resolver = $this->resolver();

        $globalContent = '{"@type":"WebSite"}';
        $resolver->saveScopeMeta($settings, 'global', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => $globalContent,
        ]);
        $pageContent = '{"@type":"Article"}';
        $resolver->saveScopeMeta($settings, 'page', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => $pageContent,
        ], 'blog', 'mein-artikel');

        $metaGlobal = $resolver->loadScopeMeta($settings, 'global');
        $metaPage = $resolver->loadScopeMeta($settings, 'page', 'blog', 'mein-artikel');

        $this->assertSame($globalContent, $metaGlobal['existing_jsonld_content']);
        $this->assertSame($pageContent, $metaPage['existing_jsonld_content']);
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

}
