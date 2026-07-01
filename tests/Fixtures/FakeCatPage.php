<?php

namespace SchemaOrgData\Tests;

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Klasse CatPage, ausschließlich
* für renderExcludedCatsField() (get_CatArray()).
*
***************************************************************/
final class FakeCatPage {

    function __construct(private array $cats) {
    }

    function get_CatArray(bool $all = false): array {
        return $this->cats;
    }
}
