<?php

namespace SchemaOrgData\Tests;

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Klasse CatPage, ausschließlich
* für renderAdminPage()/renderScopeSelector() (get_CatArray() und
* get_PageArray()).
*
***************************************************************/
final class FakeCatPageWithPages {

    function __construct(private array $cats, private array $pages = []) {
    }

    function get_CatArray(bool $all = false, $showlink = false, $containspage = null): array {
        return $this->cats;
    }

    function get_PageArray(string $cat, $extensions = null, $showlink = true): array {
        return $this->pages[$cat] ?? [];
    }
}
