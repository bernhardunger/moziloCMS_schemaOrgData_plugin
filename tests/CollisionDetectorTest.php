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
        $html = '<head><title>Steuerkanzlei Hader</title></head>';

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
}
