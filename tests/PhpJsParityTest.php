<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Wächter über Werte, die in PHP und in JavaScript wortgleich stehen
* müssen. PHP hält je Wert die Konstante, die JS-Seite behält ihr
* Literal, und diese Suite hält beide gegeneinander. Transportiert wird
* nichts: Ein Wert, der zur Laufzeit konstant ist, gehört in keinen
* Laufzeitkanal - käme er nicht an, bliebe entweder ein hartcodierter
* Ersatzwert stehen (dann war der Transport umsonst) oder die Prüfung
* fiele wortlos aus.
*
* Geprüft wird Wortlaut, nicht Verhalten. Dass beide Seiten dasselbe
* tun, decken die Fachtests je Seite bereits ab; hier geht es allein
* darum, dass sie über denselben Wert reden.
*
* Die Wächter liegen bewusst nicht bei den Fachtests der jeweiligen
* Komponente: Sie teilen einen Mechanismus, gehören zu keiner
* Komponente, und im Fehlerfall steht die Diagnose ohne Umweg da.
*
* Leserichtung: PHPUnit liest die JS-Quelldatei als Text. Die
* PHP-Konstante ist die Quelle, weil PHP das Markup rendert und
* JavaScript es liest; der umgekehrte Weg zwänge Jest, PHP-Quelltext zu
* parsen.
*
***************************************************************/
final class PhpJsParityTest extends TestCase {

    /**
     * Liest eine JS-Quelldatei des Plugins als Text und macht sie
     * vergleichbar.
     *
     * Die Normalisierung von `\/` zu `/` ist der einzige Eingriff:
     * JS-Regexe stehen als Literal zwischen Schrägstrichen und escapen
     * den Delimiter, PHP-Muster mit `#` als Delimiter tun das nicht.
     * Ohne sie schlüge jeder Musterwächter an einer Escape-Sequenz fehl,
     * die beide Seiten gleich meinen.
     */
    private function jsQuelle(string $relativerPfad): string {
        $pfad = \BASE_DIR.'plugins/schemaOrgData/'.$relativerPfad;

        // Ohne diese Zusicherung liefert file_get_contents() false, und
        // der Wächter meldete eine Wertabweichung, wo in Wahrheit die
        // Datei fehlt oder verschoben wurde.
        $this->assertFileIsReadable($pfad, 'JS-Quelle nicht lesbar: '.$relativerPfad);

        return str_replace('\\/', '/', (string) file_get_contents($pfad));
    }

    /**
     * Zieht den Wert einer JS-Deklaration der Form `var NAME = '...';`
     * aus einem Quelltext.
     *
     * Der Wächter ankert auf die Deklaration statt auf das bloße
     * Vorkommen der Zeichenkette: Dieselben Werte stehen in denselben
     * Dateien auch in Kommentaren, ein Vorkommens-Test liefe dort grün,
     * während die Deklaration längst einen anderen Wert trüge. Mehr als
     * eine Deklaration ist ebenfalls ein Fehlschlag - der Wächter
     * bewachte sonst eine von zwei Kopien und ließe die andere driften.
     */
    private function jsDeklaration(string $js, string $name, string $datei): string {
        $treffer = preg_match_all(
            '#var\s+'.preg_quote($name, '#').'\s*=\s*\'([^\']*)\'\s*;#',
            $js,
            $matches
        );

        $this->assertSame(
            1,
            $treffer,
            'Erwartet: genau eine Deklaration von '.$name.' in '.$datei.', gefunden: '.$treffer
        );

        return $matches[1][0];
    }

    // -----------------------------------------------------------
    // ID des Debug-Datenblocks
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Änderungsdetektor auf die ID des JSON-Datenblocks, über den die
    * Debug-Nutzlast das Widget erreicht. Der Wert kann driften, weil PHP
    * ihn in einen HTML-String hängt und JavaScript dasselbe Element über
    * document.getElementById() sucht - zwei Stellen ohne gemeinsamen
    * Compiler dazwischen.
    *
    * Laufen sie auseinander, findet das Widget seinen Datenblock nicht
    * und baut wortlos nichts auf. Keinem Fachtest der beiden Seiten
    * fällt das auf, weil beide für sich weiter stimmen; sichtbar würde
    * es erst im Betrieb, und dort als Ausbleiben statt als
    * Fehlermeldung.
    *
    ***************************************************************/
    function testDebugDatenblockIdStehtInBeidenSprachenGleich(): void {
        $datei = 'js/debug-widget.js';
        $wert  = $this->jsDeklaration($this->jsQuelle($datei), 'DATA_ELEMENT_ID', $datei);

        $this->assertSame(
            \SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID,
            $wert,
            'PHP (SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID): "'
                .\SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID
                .'" gegen JS ('.$datei.', var DATA_ELEMENT_ID): "'.$wert.'"'
        );
    }
}
