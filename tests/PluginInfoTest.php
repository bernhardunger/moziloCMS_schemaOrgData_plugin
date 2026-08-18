<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für getInfo().
*
* Der zweite Rückgabewert ist gegenüber dem Kern kein Anzeigetext,
* sondern ein Gate: admin/plugins.php sucht darin per Teilstring
* eine Nadel seiner Kompatibilitätsliste und schreibt bei
* fehlendem Treffer selbsttätig active=false in die
* plugin.conf.php. Auf moziloCMS 3.0.4 greift allein die Ziffer 2.
*
* Der zweite Test hält deshalb die Klammer in '3.0 (nicht 2.x)'
* fest: Wer sie als redundante Prosa streicht, deaktiviert das
* Plugin bei der nächsten Installation, ohne dass eine
* Fehlermeldung darauf zeigt.
*
* Der erste Rückgabewert (Versionsstring) wird bewusst nicht
* geprüft — er ändert sich mit jedem Produktivcode-Commit und
* würde den Test zu einem Wartungsposten ohne Aussage machen.
*
***************************************************************/
final class PluginInfoTest extends TestCase {

    function testKompatibilitaetsangabeLautetWieVorgesehen(): void {
        $plugin = new \schemaOrgData();

        $info = $plugin->getInfo();

        $this->assertSame('3.0 (nicht 2.x)', $info[1]);
    }

    function testKompatibilitaetsangabeTraegtDieVomKernGesuchteNadel(): void {
        $plugin = new \schemaOrgData();

        $info = $plugin->getInfo();

        $this->assertStringContainsString(
            '2',
            $info[1],
            'admin/plugins.php sucht die Nadel 2 per Teilstring; ohne sie '
                .'schreibt der Kern active=false in die plugin.conf.php'
        );
    }
}
