<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für detectExistingJsonLd() - Erkennung bereits
* vorhandener <script type="application/ld+json">-Blöcke.
*
***************************************************************/
final class CollisionDetectorTest extends TestCase {

    private function extract(string $html): array {
        return (new \SchemaOrgData_CollisionDetector())->extractExistingJsonLdBlocks($html);
    }

    private function detect(string $html): bool {
        return (new \SchemaOrgData_CollisionDetector())->detectExistingJsonLd(
            $html, (string) ($GLOBALS['TEMPLATE_FILE'] ?? '')
        );
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

    // -----------------------------------------------------------
    // detectExistingJsonLd() — bestehende Tests bleiben grün
    // -----------------------------------------------------------

    function testExistingJsonLdBlockIsDetected(): void {
        $html = '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>';

        $this->assertTrue($this->detect($html));
    }

    function testHtmlWithoutJsonLdReturnsFalse(): void {
        $html = '<head><title>Muster GmbH</title></head>';

        $this->assertFalse($this->detect($html));
    }

    function testMultipleBlocksAreDetected(): void {
        $html = '<head>'
            .'<script type="application/ld+json">{"@type":"Organization"}</script>'
            .'<script type="application/ld+json">{"@type":"WebSite"}</script>'
            .'</head>';

        $this->assertTrue($this->detect($html));
    }

    function testSingleQuotedAttributeIsDetected(): void {
        $html = "<script type='application/ld+json'>{}</script>";

        $this->assertTrue($this->detect($html));
    }

    private ?string $templateFile = null;

    /** Temporäre Layout-Verzeichnisse, die in tearDown() entfernt werden */
    private array $layoutDirsToCleanup = [];

    protected function tearDown(): void {
        // Temporäre Template-Datei entfernen und globale Variable zurücksetzen
        if($this->templateFile !== null && file_exists($this->templateFile)) {
            unlink($this->templateFile);
        }
        $this->templateFile = null;
        $GLOBALS['TEMPLATE_FILE'] = null;

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

    private function detectAdmin(): bool {
        return (new \SchemaOrgData_CollisionDetector())->detectExistingJsonLdInTemplateAdmin(
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

    function testJsonLdInTemplateIsDetected(): void {
        // Temporäre Template-Datei mit JSON-LD-Block anlegen
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_template_'.uniqid().'.html';
        file_put_contents($this->templateFile, '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>');
        $GLOBALS['TEMPLATE_FILE'] = $this->templateFile;

        $this->assertTrue($this->detect(''));
    }

    function testTemplateWithoutJsonLdReturnsFalse(): void {
        // Temporäre Template-Datei ohne JSON-LD-Block anlegen
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_template_'.uniqid().'.html';
        file_put_contents($this->templateFile, '<head><title>Muster GmbH</title></head>');
        $GLOBALS['TEMPLATE_FILE'] = $this->templateFile;

        $this->assertFalse($this->detect(''));
    }

    function testJsonLdInContentIsDetectedWithoutTemplate(): void {
        // Kein Template gesetzt - bestehende Content-Prüfung muss weiterhin greifen
        $GLOBALS['TEMPLATE_FILE'] = '';

        $this->assertTrue($this->detect('<script type="application/ld+json">{"@type":"LocalBusiness"}</script>'));
    }

    function testNonExistentTemplateFileReturnsFalse(): void {
        // Template-Pfad existiert nicht - keine Fehlermeldung, sondern false
        $GLOBALS['TEMPLATE_FILE'] = '/tmp/does-not-exist-schemaorgdata.html';

        $this->assertFalse($this->detect(''));
    }

    // ---------------------------------------------------------------------------
    // Tests für detectExistingJsonLdInTemplateAdmin()
    // ---------------------------------------------------------------------------

    function testAdminDetectsJsonLdInActiveTemplateHtml(): void {
        // Block in template.html des aktiven Layouts, $TEMPLATE_FILE NICHT gesetzt
        // → existing_jsonld wird true, lässt sich über saveScopeMeta persistieren.
        $layout = 'schemaOrgData_test_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );
        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);
        $GLOBALS['TEMPLATE_FILE'] = '';

        $plugin = new \schemaOrgData();
        $refObj = new \ReflectionObject($plugin);
        $settingsProp = $refObj->getProperty('settings');
        $settingsProp->setAccessible(true);
        $settings = new \InMemorySettings();
        $settingsProp->setValue($plugin, $settings);

        $detected = (new \SchemaOrgData_CollisionDetector())->detectExistingJsonLdInTemplateAdmin($GLOBALS['CMS_CONF'] ?? null);
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

        $this->assertFalse($this->detectAdmin());
    }

    function testAdminDetectsJsonLdInGalleryTemplate(): void {
        // Block nur in gallerytemplate.html des aktiven Layouts → true
        $layout = 'schemaOrgData_gallery_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );
        $this->createLayoutTemplate($layout, 'gallerytemplate.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );

        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $this->assertTrue($this->detectAdmin());
    }

    function testAdminDetectsDraftlayoutWhenDraftmodeActive(): void {
        // Block nur im draftlayout, draftmode aktiv → true
        $draftLayout = 'schemaOrgData_draft_' . uniqid();
        $this->createLayoutTemplate($draftLayout, 'template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );

        $activeLayout = 'schemaOrgData_main_' . uniqid();
        $this->createLayoutTemplate($activeLayout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );

        $GLOBALS['CMS_CONF'] = new \MockConf([
            'cmslanguage' => 'de',
            'cmslayout'   => $activeLayout,
            'draftmode'   => 'true',
            'draftlayout' => $draftLayout,
        ]);

        $this->assertTrue($this->detectAdmin());
    }

    function testAdminIgnoresDraftlayoutWhenDraftmodeInactive(): void {
        // Block nur im draftlayout, draftmode INAKTIV → false
        $draftLayout = 'schemaOrgData_draft2_' . uniqid();
        $this->createLayoutTemplate($draftLayout, 'template.html',
            '<head><script type="application/ld+json">{"@context":"https://schema.org"}</script></head>'
        );

        $activeLayout = 'schemaOrgData_main2_' . uniqid();
        $this->createLayoutTemplate($activeLayout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );

        $GLOBALS['CMS_CONF'] = new \MockConf([
            'cmslanguage' => 'de',
            'cmslayout'   => $activeLayout,
            'draftmode'   => 'false',
            'draftlayout' => $draftLayout,
        ]);

        $this->assertFalse($this->detectAdmin());
    }

    function testAdminReturnsFalseWhenNoJsonLdInAnyActiveLayout(): void {
        // Kein Block → false
        $layout = 'schemaOrgData_nojsonld_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><title>Kein JSON-LD</title></head>'
        );

        $GLOBALS['CMS_CONF'] = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $this->assertFalse($this->detectAdmin());
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

    function testComponentDetectExistingJsonLdInTemplateDelegatesToExtract(): void {
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_component_template_'.uniqid().'.html';
        file_put_contents($this->templateFile, '<head><script type="application/ld+json">{}</script></head>');

        $detector = new \SchemaOrgData_CollisionDetector();

        $this->assertTrue($detector->detectExistingJsonLdInTemplate($this->templateFile));
        $this->assertFalse($detector->detectExistingJsonLdInTemplate(''));
    }

    function testComponentDetectExistingJsonLdInTemplateAdminDelegatesToExtract(): void {
        $layout = 'schemaOrgData_component_delegate_' . uniqid();
        $this->createLayoutTemplate($layout, 'template.html',
            '<head><script type="application/ld+json">{}</script></head>'
        );

        $detector = new \SchemaOrgData_CollisionDetector();
        $cmsConf = new \MockConf(['cmslanguage' => 'de', 'cmslayout' => $layout]);

        $this->assertTrue($detector->detectExistingJsonLdInTemplateAdmin($cmsConf));
        $this->assertFalse($detector->detectExistingJsonLdInTemplateAdmin(null));
    }

    function testComponentDetectExistingJsonLdCombinesContentAndTemplate(): void {
        $this->templateFile = sys_get_temp_dir().'/schemaOrgData_component_template_'.uniqid().'.html';
        file_put_contents($this->templateFile, '<head><title>Kein JSON-LD</title></head>');

        $detector = new \SchemaOrgData_CollisionDetector();

        // Weder Content noch Template enthalten einen Block → false.
        $this->assertFalse($detector->detectExistingJsonLd('<title>x</title>', $this->templateFile));

        // Block im Content, Template ohne Block → true (Content-Pfad greift).
        $this->assertTrue($detector->detectExistingJsonLd(
            '<script type="application/ld+json">{}</script>', $this->templateFile
        ));

        // Kein Block im Content, aber im Template → true (Template-Pfad greift).
        file_put_contents($this->templateFile, '<head><script type="application/ld+json">{}</script></head>');
        $this->assertTrue($detector->detectExistingJsonLd('<title>x</title>', $this->templateFile));
    }
}
