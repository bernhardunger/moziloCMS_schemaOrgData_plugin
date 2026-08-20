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
* Zwei Sorten Wächter stehen hier nebeneinander. Der Wertwächter hält
* eine einzelne Zeichenkette gegen ihr Gegenstück. Der Mengenwächter
* hält zwei Aufzählungen gegeneinander und meldet beide Richtungen: ein
* Wert ohne Verbraucher ebenso wie ein Verbrauch ohne Quelle. Die zweite
* Richtung ist die gefährlichere - sie liefert zur Laufzeit undefined
* und damit eine leere Meldung statt eines Fehlers.
*
***************************************************************/
final class PhpJsParityTest extends TestCase {

    private const JS_VALIDATOR = 'js/validator.js';
    private const JS_DEBUG_WIDGET = 'js/debug-widget.js';
    private const PHP_ADMIN_CONTROLLER = 'lib/SchemaOrgData_AdminController.php';

    /**
     * Liest eine Quelldatei des Plugins als Text.
     */
    private function pluginQuelle(string $relativerPfad): string {
        $pfad = \BASE_DIR.'plugins/schemaOrgData/'.$relativerPfad;

        // Ohne diese Zusicherung liefert file_get_contents() false, und
        // der Wächter meldete eine Wertabweichung, wo in Wahrheit die
        // Datei fehlt oder verschoben wurde.
        $this->assertFileIsReadable($pfad, 'Quelle nicht lesbar: '.$relativerPfad);

        return (string) file_get_contents($pfad);
    }

    /**
     * Liest eine JS-Quelldatei und macht sie vergleichbar.
     *
     * Die Normalisierung von `\/` zu `/` ist der einzige Eingriff:
     * JS-Regexe stehen als Literal zwischen Schrägstrichen und escapen
     * den Delimiter, PHP-Muster mit `#` als Delimiter tun das nicht.
     * Ohne sie schlüge jeder Musterwächter an einer Escape-Sequenz fehl,
     * die beide Seiten gleich meinen.
     */
    private function jsQuelle(string $relativerPfad): string {
        return str_replace('\\/', '/', $this->pluginQuelle($relativerPfad));
    }

    /**
     * Wirft alle reinen Kommentarzeilen aus einem Quelltext.
     *
     * Jeder Wächter, der ein Vorkommen statt einer Deklaration prüft,
     * braucht das: Dieselben Werte stehen in denselben Dateien auch in
     * der Prosa der Docblocks, und ein Treffer dort belegt nichts über
     * den Code. Zeilennummern gehen dabei verloren - keiner der Wächter
     * hier meldet welche.
     */
    private function nurCode(string $quelle): string {
        $behalten = [];
        foreach(preg_split('/\r\n|\r|\n/', $quelle) as $zeile) {
            $trimmed = ltrim($zeile);
            if($trimmed === '' or str_starts_with($trimmed, '*')
                or str_starts_with($trimmed, '//') or str_starts_with($trimmed, '/*')
                or str_starts_with($trimmed, '#')) {
                continue;
            }
            $behalten[] = $zeile;
        }

        return implode("\n", $behalten);
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

    /**
     * Zieht die Elemente einer JS-Array-Deklaration
     * `var NAME = ['a', 'b'];` heraus.
     *
     * Wie jsDeklaration() auf genau ein Vorkommen abgesichert. Ein
     * mehrzeiliges Array fällt bewusst durch: Es käme hier als
     * "keine Deklaration gefunden" an, und das ist die richtige Meldung
     * - der Wächter soll nicht raten, sondern angepasst werden.
     *
     * @return string[]
     */
    private function jsArrayDeklaration(string $js, string $name, string $datei): array {
        $treffer = preg_match_all(
            '#var\s+'.preg_quote($name, '#').'\s*=\s*\[([^\]]*)\]\s*;#',
            $js,
            $matches
        );

        $this->assertSame(
            1,
            $treffer,
            'Erwartet: genau eine Array-Deklaration von '.$name.' in '.$datei
                .', gefunden: '.$treffer
        );

        preg_match_all('#\'([^\']*)\'#', $matches[1][0], $elemente);

        return $elemente[1];
    }

    /**
     * Sammelt die Fall-Literale eines `switch` aus einem Quelltext.
     *
     * Setzt genau eine `switch`-Anweisung in der Datei voraus und prüft
     * das ausdrücklich. Bei einer zweiten wären die gesammelten Labels
     * eine Mischung aus zwei Vokabularen, und der Mengenvergleich
     * meldete eine Abweichung, die keine ist.
     *
     * @return string[]
     */
    private function jsFallLabels(string $js, string $datei): array {
        $code = $this->nurCode($js);

        $this->assertSame(
            1,
            preg_match_all('#\bswitch\s*\(#', $code),
            'Erwartet: genau eine switch-Anweisung in '.$datei
                .' - sonst mischt der Wächter zwei Vokabulare'
        );

        preg_match_all('#\bcase\s+\'([^\']*)\'\s*:#', $code, $matches);

        return $matches[1];
    }

    /**
     * Zählt Vergleiche der Form `=== 'wert'` auf Nicht-Kommentarzeilen.
     *
     * Schwächster der drei Wächtertypen und nur dort eingesetzt, wo es
     * nichts Stärkeres gibt: ein einzelner Wert ohne Deklaration und
     * ohne Aufzählung, gegen die sich eine Menge halten ließe.
     */
    private function jsVergleiche(string $js, string $wert): int {
        return preg_match_all(
            '#===\s*\''.preg_quote($wert, '#').'\'#',
            $this->nurCode($js)
        );
    }

    /**
     * Sammelt die Eigenschaftsnamen hinter einem Ausdruck, also etwa
     * `foo` und `bar` zu `getMessages().foo` / `getMessages().bar`.
     *
     * Kommentarzeilen fallen vorher heraus, und zwar nicht vorsorglich:
     * In `validator.js` endet ein Kommentarsatz auf „…von
     * getMessages()." - ein nachlässiges Muster erzeugte daraus einen
     * Schlüssel, den es nicht gibt.
     *
     * @return string[] eindeutig, aufsteigend sortiert
     */
    private function jsEigenschaftszugriffe(string $js, string $ausdruck): array {
        preg_match_all(
            '#'.preg_quote($ausdruck, '#').'\.(\w+)#',
            $this->nurCode($js),
            $matches
        );

        $namen = array_values(array_unique($matches[1]));
        sort($namen);

        return $namen;
    }

    /**
     * Sammelt die Schlüssel eines PHP-Array-Literals, das an einer
     * benannten Zeile beginnt und mit `];` endet.
     *
     * Gegenstück zu jsEigenschaftszugriffe() für die erzeugende Seite:
     * Das Meldungswörterbuch ist kein Konstantensatz, sondern ein
     * Array-Literal in einer Methode - es lässt sich nicht per
     * Reflection lesen, nur als Quelltext.
     *
     * @return string[] eindeutig, aufsteigend sortiert
     */
    private function phpArraySchluessel(string $php, string $anker, string $datei): array {
        $zeilen = preg_split('/\r\n|\r|\n/', $php);
        $start = null;
        foreach($zeilen as $i => $zeile) {
            if(str_contains($zeile, $anker)) {
                $this->assertNull($start, 'Anker '.$anker.' steht mehrfach in '.$datei);
                $start = $i;
            }
        }
        $this->assertNotNull($start, 'Anker '.$anker.' fehlt in '.$datei);

        $namen = [];
        for($i = $start + 1; $i < count($zeilen); $i++) {
            if(trim($zeilen[$i]) === '];') {
                $namen = array_values(array_unique($namen));
                sort($namen);

                return $namen;
            }
            if(preg_match('#^\s*\'(\w+)\'\s*=>#', $zeilen[$i], $m)) {
                $namen[] = $m[1];
            }
        }

        $this->fail('Kein abschliessendes "];" nach '.$anker.' in '.$datei);
    }

    /**
     * Liest die Werte aller Klassenkonstanten mit einem Namenspräfix.
     *
     * Über Reflection statt über eine Aufzählung im Test: Eine zwölfte
     * `VALIDATE_`-Konstante nimmt der Wächter damit von selbst auf,
     * statt still an zehn von elf Werten weiterzuprüfen.
     *
     * @return string[] aufsteigend sortiert
     */
    private function konstantenMitPraefix(string $klasse, string $praefix): array {
        $werte = [];
        foreach((new \ReflectionClass($klasse))->getConstants() as $name => $wert) {
            if(str_starts_with($name, $praefix)) {
                $werte[] = (string) $wert;
            }
        }
        sort($werte);

        return $werte;
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
        $datei = self::JS_DEBUG_WIDGET;
        $wert  = $this->jsDeklaration($this->jsQuelle($datei), 'DATA_ELEMENT_ID', $datei);

        $this->assertSame(
            \SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID,
            $wert,
            'PHP (SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID): "'
                .\SchemaOrgData_FrontendRenderer::DEBUG_DATA_ELEMENT_ID
                .'" gegen JS ('.$datei.', var DATA_ELEMENT_ID): "'.$wert.'"'
        );
    }

    // -----------------------------------------------------------
    // Dispatch-Vokabular des data-validate-Attributs
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwächter über die Werte, die PHP in `data-validate` schreibt
    * und `validator.js` in `runFieldValidation()` auswertet.
    *
    * Das `switch` endet mit `default: return;` - ein unbekannter Wert
    * wird still ignoriert. Wer in `FormRenderer` einen Typnamen
    * umbenennt oder vertippt, bekommt deshalb keinen Fehler, sondern ein
    * Feld ohne Live-Validierung, und das fällt erst auf, wenn ein Nutzer
    * ungültige Daten speichert.
    *
    * Eine Asymmetrie ist beabsichtigt und deshalb hier festgeschrieben:
    * `address_required` hat kein `case`. Der Wert wird vor dem `switch`
    * abgefangen, weil er sonst über `case 'required'` liefe und doppelt
    * einen Fehler erzeugte. Der Wächter erwartet ihn daher als Vergleich
    * statt als Fall-Label - eine Prüfung, die stumpf gleichsetzte, wäre
    * vom ersten Tag an rot.
    *
    ***************************************************************/
    function testDataValidateVokabularDecktSichMitDenSwitchFaellen(): void {
        $datei = self::JS_VALIDATOR;
        $js    = $this->jsQuelle($datei);

        $konstanten = $this->konstantenMitPraefix('SchemaOrgData_FormRenderer', 'VALIDATE_');
        $labels     = $this->jsFallLabels($js, $datei);

        $sonderfall = \SchemaOrgData_FormRenderer::VALIDATE_ADDRESS_REQUIRED;
        $this->assertNotContains(
            $sonderfall,
            $labels,
            'Erwartet: "'.$sonderfall.'" hat kein case-Label, weil es vor dem switch'
                .' abgefangen wird. Steht es jetzt im switch, ist die Doppelmeldung'
                .' zurück und dieser Wächter überholt.'
        );
        $this->assertSame(
            1,
            $this->jsVergleiche($js, $sonderfall),
            'Erwartet: genau ein Vergleich === "'.$sonderfall.'" vor dem switch in '.$datei
        );

        $jsSeite = array_merge($labels, [$sonderfall]);
        sort($jsSeite);

        $this->assertSame(
            $konstanten,
            $jsSeite,
            'PHP (SchemaOrgData_FormRenderer::VALIDATE_*): '.implode(', ', $konstanten)
                .' | JS ('.$datei.', switch-Fälle plus Sonderfall): '.implode(', ', $jsSeite)
        );
    }

    // -----------------------------------------------------------
    // Rollennamen der Organisations-Relationen
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwächter über die drei Rollennamen. Sie dienen auf PHP-Seite
    * als Relationsrolle und auf JS-Seite als Name der schema.org-
    * Property am Organisations-Knoten - zwei Rollen für dieselbe
    * Zeichenkette, die anders als bei getrennten Vokabularen gerade
    * deshalb gleich bleiben muss: Die Property heißt so, weil die
    * Relation so heißt.
    *
    * Der abweichende Bezeichner auf der JS-Seite
    * (PERSON_SUGGESTION_PROPERTIES gegen roles()) ist damit kein
    * Hinweis auf zwei Vokabulare, sondern auf zwei Blickwinkel.
    *
    ***************************************************************/
    function testRollennamenDeckenSichMitDerJsEigenschaftsliste(): void {
        $datei = self::JS_VALIDATOR;

        $php = \SchemaOrgData_OrgRelationsService::roles();
        sort($php);

        $js = $this->jsArrayDeklaration(
            $this->jsQuelle($datei),
            'PERSON_SUGGESTION_PROPERTIES',
            $datei
        );
        sort($js);

        $this->assertSame(
            $php,
            $js,
            'PHP (SchemaOrgData_OrgRelationsService::roles()): '.implode(', ', $php)
                .' | JS ('.$datei.', var PERSON_SUGGESTION_PROPERTIES): '.implode(', ', $js)
        );
    }

    // -----------------------------------------------------------
    // @type-Wert der Personen-Erkennung
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Wertwächter über den `@type`-Wert, an dem beide Seiten ein
    * Personen-Literal erkennen.
    *
    * Bewusst der schwächste Wächter dieser Suite: ein einzelner Wert,
    * der in `validator.js` als Vergleichsliteral steht, ohne Deklaration
    * und ohne Aufzählung. Geprüft wird deshalb nur, dass genau ein
    * Vergleich gegen den PHP-Wert existiert. Was er nicht fängt: eine
    * zweite Erkennungsstelle, die auf einen anderen Type prüft, ohne den
    * hiesigen Vergleich anzurühren.
    *
    ***************************************************************/
    function testPersonenTypeWertStehtInBeidenSprachenGleich(): void {
        $datei = self::JS_VALIDATOR;
        $wert  = \SchemaOrgData_PersonsRegistryService::SCHEMA_TYPE_PERSON;

        $this->assertSame(
            1,
            $this->jsVergleiche($this->jsQuelle($datei), $wert),
            'Erwartet: genau ein Vergleich === "'.$wert.'" in '.$datei
                .' (PHP: SchemaOrgData_PersonsRegistryService::SCHEMA_TYPE_PERSON)'
        );
    }

    // -----------------------------------------------------------
    // Schlüssel des Meldungswörterbuchs
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwächter über die Schlüssel, unter denen PHP lokalisierte
    * Meldungstexte in das `data-messages`-Attribut schreibt und
    * `validator.js` sie wieder herausliest.
    *
    * Dieser Kontrakt ist der einzige der Suite, der sich nicht auflösen
    * lässt: Um die Schlüsselmenge zu übertragen, müsste man einen
    * Schlüssel kennen, unter dem sie steht. Er bleibt deshalb auf beiden
    * Seiten literal und wird nur bewacht.
    *
    * Beide Richtungen zählen, die zweite mehr: Ein Schlüssel ohne
    * Verbraucher ist toter Ballast, ein Zugriff ohne Erzeuger liefert
    * `undefined` und damit eine leere Meldung - eine Prüfung, die
    * scheinbar durchläuft und nichts sagt.
    *
    * Die Namensgleichheit bei `telephone` mit
    * SchemaOrgData_FormRenderer::VALIDATE_TELEPHONE ist Zufall über zwei
    * Vokabulare hinweg. Der Wächter hängt sich deshalb nicht an jene
    * Konstante: Ein Meldungsschlüssel und ein `data-validate`-Wert haben
    * verschiedene Lebenszyklen und dürfen unabhängig voneinander
    * wandern.
    *
    ***************************************************************/
    function testMeldungsschluesselDeckenSichInBeidenRichtungen(): void {
        $erzeugt = $this->phpArraySchluessel(
            $this->pluginQuelle(self::PHP_ADMIN_CONTROLLER),
            '$messages = [',
            self::PHP_ADMIN_CONTROLLER
        );
        $gelesen = $this->jsEigenschaftszugriffe(
            $this->jsQuelle(self::JS_VALIDATOR),
            'getMessages()'
        );

        $ohneVerbraucher = array_values(array_diff($erzeugt, $gelesen));
        $ohneErzeuger    = array_values(array_diff($gelesen, $erzeugt));

        $this->assertSame(
            [],
            $ohneErzeuger,
            'Zugriff ohne Erzeuger - liefert zur Laufzeit undefined und eine leere'
                .' Meldung: '.implode(', ', $ohneErzeuger)
        );
        $this->assertSame(
            [],
            $ohneVerbraucher,
            'Schlüssel ohne Verbraucher in '.self::PHP_ADMIN_CONTROLLER.': '
                .implode(', ', $ohneVerbraucher)
        );
    }
}
