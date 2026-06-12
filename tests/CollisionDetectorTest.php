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

    private function detect(string $html): bool {
        $plugin = new \schemaOrgData();
        return callPluginMethod($plugin, 'detectExistingJsonLd', [$html]);
    }

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

    protected function tearDown(): void {
        // Temporäre Template-Datei entfernen und globale Variable zurücksetzen
        if($this->templateFile !== null && file_exists($this->templateFile)) {
            unlink($this->templateFile);
        }
        $this->templateFile = null;
        $GLOBALS['TEMPLATE_FILE'] = null;
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

        $this->assertTrue($this->detect('<script type="application/ld+json">'));
    }

    function testNonExistentTemplateFileReturnsFalse(): void {
        // Template-Pfad existiert nicht - keine Fehlermeldung, sondern false
        $GLOBALS['TEMPLATE_FILE'] = '/tmp/does-not-exist-schemaorgdata.html';

        $this->assertFalse($this->detect(''));
    }
}
