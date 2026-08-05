<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_CollisionDetector - Erkennung bereits
* vorhandener <script type="application/ld+json">-Blöcke.
*
***************************************************************/
final class CollisionDetectorTest extends TestCase {

    private function extract(string $html): array {
        return (new \SchemaOrgData_CollisionDetector())->extractExistingJsonLdBlocks($html);
    }

    // -----------------------------------------------------------
    // extractExistingJsonLdBlocks()
    // -----------------------------------------------------------

    function testExtractReturnsInnerJsonTextOfSingleBlock(): void {
        $json = '{"@context":"https://schema.org","@type":"LocalBusiness"}';
        $html = '<head><script type="application/ld+json">'.$json.'</script></head>';

        $blocks = $this->extract($html);

        $this->assertCount(1, $blocks);
        $this->assertSame($json, $blocks[0]);
    }

    function testExtractReturnsAllInnerTextsForMultipleBlocks(): void {
        $json1 = '{"@type":"Organization"}';
        $json2 = '{"@type":"WebSite"}';
        $html = '<head>'
            .'<script type="application/ld+json">'.$json1.'</script>'
            .'<script type="application/ld+json">'.$json2.'</script>'
            .'</head>';

        $blocks = $this->extract($html);

        $this->assertCount(2, $blocks);
        $this->assertSame($json1, $blocks[0]);
        $this->assertSame($json2, $blocks[1]);
    }

    function testExtractReturnsEmptyArrayWhenNoBlock(): void {
        $html = '<head><title>Muster GmbH</title></head>';

        $this->assertSame([], $this->extract($html));
    }

    function testExtractDoesNotCrashOnMalformedHtml(): void {
        // Kein </script>-Schließtag — preg_match_all kein Absturz
        $html = '<script type="application/ld+json">{"broken": true';

        $blocks = $this->extract($html);

        $this->assertIsArray($blocks);
        $this->assertCount(0, $blocks);
    }

    function testExtractWorksWithSingleQuotedAttribute(): void {
        $json = '{"@type":"Person"}';
        $html = "<script type='application/ld+json'>".$json.'</script>';

        $blocks = $this->extract($html);

        $this->assertCount(1, $blocks);
        $this->assertSame($json, $blocks[0]);
    }

    private ?string $templateFile = null;

    /** Temporäre Layout-Verzeichnisse, die in tearDown() entfernt werden */
    private array $layoutDirsToCleanup = [];

    protected function tearDown(): void {
        // Temporäre Template-Datei entfernen
        if($this->templateFile !== null && file_exists($this->templateFile)) {
            unlink($this->templateFile);
        }
        $this->templateFile = null;

        // Test-Layout-Verzeichnisse entfernen
        foreach($this->layoutDirsToCleanup as $dir) {
            if(is_dir($dir)) {
                foreach(glob($dir . '/*') ?: [] as $file) {
                    if(is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($dir);
            }
        }
        $this->layoutDirsToCleanup = [];

        // Elternverzeichnis (BASE_DIR . LAYOUT_DIR_NAME) entfernen wenn leer
        $layoutsDir = BASE_DIR . LAYOUT_DIR_NAME;
        if(is_dir($layoutsDir)) {
            $entries = array_diff(scandir($layoutsDir) ?: [], ['.', '..']);
            if($entries === []) {
                rmdir($layoutsDir);
            }
        }

        // CMS_CONF auf Teststandard zurücksetzen
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de']);
    }

    // ---------------------------------------------------------------------------
    // Hilfsmethoden für Admin-Template-Erkennung
    // ---------------------------------------------------------------------------

    private function extractAdmin(): array {
        return (new \SchemaOrgData_CollisionDetector())->extractExistingJsonLdBlocksFromTemplateAdmin(
            $GLOBALS['CMS_CONF'] ?? null
        );
    }

    /**
     * Legt eine temporäre Layout-Template-Datei unter
     * BASE_DIR/LAYOUT_DIR_NAME/$layoutName/$filename an und merkt
     * das Verzeichnis für tearDown() vor.
     */
    private function createLayoutTemplate(string $layoutName, string $filename, string $content): void {
        $dir = BASE_DIR . LAYOUT_DIR_NAME . '/' . $layoutName;
        if(!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
        if(!in_array($dir, $this->layoutDirsToCleanup, true)) {
            $this->layoutDirsToCleanup[] = $dir;
        }
    }

    // ---------------------------------------------------------------------------
    // Admin-Layout-Erkennung: Persistenz der Meta und Layout-Auswahl
    // ---------------------------------------------------------------------------

    function testAdminDetectsJsonLdInActiveTemplateHtml(): void {
        // Block in template.html des aktiven Layouts → existing_jsonld wird
        // true und lässt sich über saveScopeMeta persistieren.
        $layout = 'schemaOrgData_test_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $plugin = new \schemaOrgData();
        $refObj = new \ReflectionObject($plugin);
        $settingsProp = $refObj->getProperty('settings');
        $settingsProp->setAccessible(true);
        $settings = new \InMemorySettings();
        $settingsProp->setValue($plugin, $settings);

        $detected = $this->extractAdmin() !== [];
        $this->assertTrue($detected);

        // Persistenz prüfen: saveScopeMeta() speichert, loadScopeMeta() liest korrekt
        // (direkter Komponenten-Aufruf statt callPluginMethod()-Reflection)
        $scopeResolver = new \SchemaOrgData_ScopeResolver();
        $scopeResolver->saveScopeMeta($settings, 'global', ['existing_jsonld' => $detected]);
        $meta = $scopeResolver->loadScopeMeta($settings, 'global');
        $this->assertTrue($meta['existing_jsonld']);
    }

    function testAdminInactiveLayoutIsNotChecked(): void {
        // Block ausschließlich in einem INAKTIVEN Layout → false (kein Fehlalarm)
        $inactiveLayout = 'schemaOrgData_inactive_' . uniqid();
        $this->createLayoutTemplate($inactiveLayout, 'template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );

        $activeLayout = 'schemaOrgData_empty_' . uniqid();
        $this->createLayoutTemplate($activeLayout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );

        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $activeLayout]);

        $this->assertSame([], $this->extractAdmin());
    }

    // ---------------------------------------------------------------------------
    // Direkt-Tests der Komponente SchemaOrgData_CollisionDetector.
    // $TEMPLATE_FILE/$CMS_CONF werden hier als explizite Parameter übergeben
    // statt aus globalem Scope gelesen.
    // ---------------------------------------------------------------------------

    function testComponentExtractFromTemplateReturnsEmptyArrayForEmptyPath(): void {
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplate(''));
    }

    function testComponentExtractFromTemplateReturnsEmptyArrayForNonExistentFile(): void {
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplate(
            '/tmp/does-not-exist-schemaorgdata-component.html'
        ));
    }

    function testComponentExtractFromTemplateReturnsBlockOfExistingFile(): void {
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_component_template_'.uniqid().'.html';
        $json = '{"@context":"https://schema.org"}';
        file_put_contents($this->templateFile, '<head><script type="application/ld+json">'.$json.'</script></head>');

        $detector = new \SchemaOrgData_CollisionDetector();
        $blocks = $detector->extractExistingJsonLdBlocksFromTemplate($this->templateFile);

        $this->assertCount(1, $blocks);
        $this->assertSame($json, $blocks[0]);
    }

    function testComponentExtractFromTemplateReturnsEmptyArrayWithoutBlock(): void {
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_component_template_'.uniqid().'.html';
        file_put_contents($this->templateFile, '<head><title>Kein JSON-LD</title></head>');

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplate($this->templateFile));
    }

    function testComponentExtractFromTemplateAdminReturnsEmptyArrayForNull(): void {
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplateAdmin(null));
    }

    function testComponentExtractFromTemplateAdminReturnsEmptyArrayWithoutCmslayout(): void {
        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf(['cmslanguage' => 'de']);

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf));
    }

    function testComponentExtractFromTemplateAdminReturnsEmptyArrayForFalseCmslayout(): void {
        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => 'false']);

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf));
    }

    function testComponentExtractFromTemplateAdminChecksBothLayoutsWhenDraftmodeActive(): void {
        $draftLayout = 'schemaOrgData_component_draft_' . uniqid();
        $this->createLayoutTemplate($draftLayout, 'template.html',
            '<head><script type="application/ld+json">{"@type":"WebSite"}</script></head>'
        );

        $activeLayout = 'schemaOrgData_component_active_' . uniqid();
        $this->createLayoutTemplate($activeLayout, 'template.html',
            '<head><script type="application/ld+json">{"@type":"Organization"}</script></head>'
        );

        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf([
            'cmslanguage' => 'de',
            'cmslayout'   => $activeLayout,
            'draftmode'   => 'true',
            'draftlayout' => $draftLayout,
        ]);

        $blocks = $detector->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf);

        $this->assertCount(2, $blocks);
    }

    function testComponentExtractFromTemplateAdminIgnoresGalleryTemplate(): void {
        $layout = 'schemaOrgData_component_gallery_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><script type="application/ld+json">{"@type":"Organization"}</script></head>'
        );
        $this->createLayoutTemplate($layout, 'gallerytemplate.html',
            '<head><script type="application/ld+json">{"@type":"WebSite"}</script></head>'
        );

        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $blocks = $detector->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf);

        $this->assertCount(1, $blocks);
        $this->assertSame('{"@type":"Organization"}', $blocks[0]);
    }

    function testComponentExtractFromTemplateAdminChecksOnlyActiveLayoutWhenDraftmodeInactive(): void {
        $draftLayout = 'schemaOrgData_component_draftoff_' . uniqid();
        $this->createLayoutTemplate($draftLayout, 'template.html',
            '<head><script type="application/ld+json">{"@type":"WebSite"}</script></head>'
        );

        $activeLayout = 'schemaOrgData_component_activeoff_' . uniqid();
        $this->createLayoutTemplate($activeLayout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );

        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf([
            'cmslanguage' => 'de',
            'cmslayout'   => $activeLayout,
            'draftmode'   => 'false',
            'draftlayout' => $draftLayout,
        ]);

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf));
    }

    // ---------------------------------------------------------------------------
    // Tests für extractExistingJsonLdBlocksFromPage()
    // ---------------------------------------------------------------------------

    private function ensureFakeCatPageWithPagesLoaded(): void {
        if (!class_exists(FakeCatPageWithPages::class)) {
            require_once __DIR__ . '/Fixtures/FakeCatPageWithPages.php';
        }
    }

    function testComponentExtractFromPageReturnsInnerJsonTextOfBlockInPageContent(): void {
        $this->ensureFakeCatPageWithPagesLoaded();

        $json = '{"@context":"https://schema.org","@type":"Article"}';
        $catPage = new FakeCatPageWithPages(
            ['kategorie'],
            ['kategorie' => ['seite']],
            ['kategorie' => ['seite' => '<p>Text</p><script type="application/ld+json">'.$json.'</script>']]
        );

        $blocks = (new \SchemaOrgData_CollisionDetector())
            ->extractExistingJsonLdBlocksFromPage($catPage, 'kategorie', 'seite');

        $this->assertCount(1, $blocks);
        $this->assertSame($json, $blocks[0]);
    }

    function testComponentExtractFromPageReturnsEmptyArrayForPageContentWithoutBlock(): void {
        $this->ensureFakeCatPageWithPagesLoaded();

        $catPage = new FakeCatPageWithPages(
            ['kategorie'],
            ['kategorie' => ['seite']],
            ['kategorie' => ['seite' => '<p>Nur Fließtext ohne JSON-LD.</p>']]
        );

        $this->assertSame([], (new \SchemaOrgData_CollisionDetector())
            ->extractExistingJsonLdBlocksFromPage($catPage, 'kategorie', 'seite'));
    }

    function testComponentExtractFromPageReturnsEmptyArrayWhenCoreReturnsFalse(): void {
        $this->ensureFakeCatPageWithPagesLoaded();

        // Geschützte Seiten und Link-Typen liefern im Kern false statt
        // eines Strings - die Fixture bildet das über den fehlenden
        // $contents-Eintrag ab. Ohne den is_string()-Guard wäre das ein
        // TypeError in extractExistingJsonLdBlocks().
        $catPage = new FakeCatPageWithPages(['kategorie'], ['kategorie' => ['geschuetzt']]);

        $this->assertSame([], (new \SchemaOrgData_CollisionDetector())
            ->extractExistingJsonLdBlocksFromPage($catPage, 'kategorie', 'geschuetzt'));
    }

    function testComponentExtractFromPageReturnsEmptyArrayWithoutUsableCatPageObject(): void {
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromPage(null, 'kategorie', 'seite'));

        $withoutMethod = new class {
            function get_CatArray(): array { return []; }
        };
        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromPage($withoutMethod, 'kategorie', 'seite'));
    }

    function testComponentExtractFromPageReturnsEmptyArrayForEmptyIdentifiersWithoutCoreCall(): void {
        // Zählt die Kernaufrufe mit: Bei leerem Bezeichner greift der Guard
        // vor dem Zugriff, get_PageContent() wird gar nicht erst gerufen.
        $catPage = new class {
            public int $calls = 0;
            function get_PageContent(string $cat, string $page) {
                $this->calls++;
                return '<script type="application/ld+json">{"@type":"Article"}</script>';
            }
        };

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromPage($catPage, '', 'seite'));
        $this->assertSame([], $detector->extractExistingJsonLdBlocksFromPage($catPage, 'kategorie', ''));
        $this->assertSame(0, $catPage->calls);
    }

    // ---------------------------------------------------------------------------
    // Tests für detectPluginPlaceholderInTemplateAdmin()
    //
    // Rückgabewert keine bool, sondern eine der
    // SchemaOrgData_CollisionDetector::PLACEHOLDER_*-Konstanten (OK/MISSING/
    // OUTSIDE_HEAD).
    // ---------------------------------------------------------------------------

    function testPlaceholderWithoutParamIsFound(): void {
        $layout = 'schemaOrgData_ph_ohne_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<head>{schemaOrgData}</head>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    function testPlaceholderWithParamIsFound(): void {
        $layout = 'schemaOrgData_ph_mit_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<head>{schemaOrgData|foo}</head>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    function testPlaceholderNotFoundReturnsMissing(): void {
        $layout = 'schemaOrgData_ph_fehlt_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<head><title>Kein Platzhalter</title></head>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_MISSING,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    /***************************************************************
    *
    * Regressionstest gegen den ursprünglich im RC-Lauf gefundenen Bug:
    * ein Platzhalter ausschließlich in gallerytemplate.html durfte die
    * "fehlt"-Warnung nicht mehr unterdrücken, da diese Datei nie
    * echten Seiteninhalt rendert (siehe
    * extractExistingJsonLdBlocksFromTemplateAdmin()).
    *
    ***************************************************************/
    function testPlaceholderAusschliesslichInGalleryTemplateGiltAlsFehlend(): void {
        $layout = 'schemaOrgData_ph_gallery_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<head><title>Kein Platzhalter</title></head>');
        $this->createLayoutTemplate($layout, 'gallerytemplate.html', '<head>{schemaOrgData}</head>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_MISSING,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    function testPlaceholderCheckIsFailSafeWithoutCmsConf(): void {
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin(null, 'schemaOrgData')
        );
    }

    function testPlaceholderCheckIsFailSafeWithEmptyCmslayout(): void {
        $cmsConf = new \MockConf(['cmslanguage' => 'de']);
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    function testPlaceholderCheckIsFailSafeWithFalseCmslayout(): void {
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => 'false']);
        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    // ---------------------------------------------------------------------------
    // Positions-Check (Platzhalter innerhalb vs. außerhalb <head>)
    // ---------------------------------------------------------------------------

    function testPlaceholderInnerhalbHeadGiltAlsOk(): void {
        $layout = 'schemaOrgData_ph_pos_ok_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<html><head>{schemaOrgData}</head><body></body></html>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }

    function testPlaceholderAusserhalbHeadGiltAlsOutsideHead(): void {
        $layout = 'schemaOrgData_ph_pos_outside_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html', '<html><head></head><body>{schemaOrgData}</body></html>');
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertSame(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OUTSIDE_HEAD,
            $detector->detectPluginPlaceholderInTemplateAdmin($cmsConf, 'schemaOrgData')
        );
    }
}
