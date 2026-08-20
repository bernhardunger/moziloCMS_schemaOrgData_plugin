<?php

/***************************************************************
*
* PHPStan-Bootstrap für schemaOrgData
*
* Definiert ausschließlich die Konstanten, die der moziloCMS-Kern
* zur Laufzeit setzt. PHPStan führt Bootstrap-Dateien tatsächlich
* aus: keine Logik, keine Klassen, keine PHPUnit-Abhängigkeit.
*
* PLUGINADMIN fehlt bewusst. Der Kern setzt die Konstante nur im
* Admin-Kontext; wäre sie hier definiert, hielte PHPStan jeden
* defined('PLUGINADMIN')-Zweig für immer erfüllt.
*
* CAT_REQUEST und PAGE_REQUEST stehen zusätzlich unter
* dynamicConstantNames in phpstan.neon: die Belegung hier ist eine
* von mehreren, nicht die einzig mögliche.
*
***************************************************************/

define('IS_CMS', true);
define('CHARSET', 'utf-8');
define('PLUGIN_DIR_NAME', 'plugins');
define('URL_BASE', 'http://localhost/');
define('BASE_DIR', __DIR__.'/');
define('LAYOUT_DIR_NAME', 'layouts');
define('CONTENT_FILES_DIR_NAME', 'dateien');
define('CAT_REQUEST', false);
define('PAGE_REQUEST', false);
