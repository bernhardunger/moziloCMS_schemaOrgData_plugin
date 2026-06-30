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
        $plugin = new \schemaOrgData();
        return callPluginMethod($plugin, 'extractExistingJsonLdBlocks', [$html]);
    }

    private function detect(string $html): bool {
        $plugin = new \schemaOrgData();
        return callPluginMethod($plugin, 'detectExistingJsonLd', [$html]);
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
        $plugin = new \schemaOrgData();
        return callPluginMethod($plugin, 'detectExistingJsonLdInTemplateAdmin', []);
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
        $settingsProp->setValue($plugin, new \InMemorySettings());

        $detected = callPluginMethod($plugin, 'detectExistingJsonLdInTemplateAdmin', []);
        $this->assertTrue($detected);

        // Persistenz prüfen: saveScopeMeta() speichert, loadScopeMeta() liest korrekt
        callPluginMethod($plugin, 'saveScopeMeta', ['global', ['existing_jsonld' => $detected]]);
        $meta = callPluginMethod($plugin, 'loadScopeMeta', ['global']);
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
}
