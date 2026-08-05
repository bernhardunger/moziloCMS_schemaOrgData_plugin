<?php

namespace SchemaOrgData\Tests;

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Klasse CatPage, ausschließlich
* für renderAdminPage()/renderScopeSelector() (get_CatArray() und
* get_PageArray()) sowie für die Seiteninhalts-Kollisionserkennung
* (get_PageContent()).
*
***************************************************************/
final class FakeCatPageWithPages {

    function __construct(
        private array $cats,
        private array $pages = [],
        private array $contents = []
    ) {
    }

    function get_CatArray(bool $all = false, $showlink = false, $containspage = null): array {
        return $this->cats;
    }

    function get_PageArray(string $cat, $extensions = null, $showlink = true): array {
        return $this->pages[$cat] ?? [];
    }

    /***************************************************************
    *
    * Bildet CatPageClass::get_PageContent() nach: Rohinhalt der Seite
    * oder false. Der Kern liefert false für geschützte Seiten und
    * Link-Typen sowie für jeden Schlüssel, der nicht im CatPageArray
    * steht - eine im $contents-Array fehlende Seite bildet genau das ab.
    *
    ***************************************************************/
    function get_PageContent(string $cat, string $page, bool $for_syntax = false, bool $convert_content = false) {
        return $this->contents[$cat][$page] ?? false;
    }
}
