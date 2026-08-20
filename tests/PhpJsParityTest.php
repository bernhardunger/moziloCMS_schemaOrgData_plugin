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
    private const PHP_ADMIN_PAGE_RENDERER = 'lib/SchemaOrgData_AdminPageRenderer.php';

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
     * Sammelt die Zuweisungen `var NAME = '...';` einer JS-Quelle.
     *
     * @return array<string, string> Bezeichner => Wert
     */
    private function jsZuweisungen(string $js): array {
        preg_match_all(
            '#var\s+([A-Z][A-Z0-9_]*)\s*=\s*\'([^\']*)\'\s*;#',
            $this->nurCode($js),
            $matches
        );

        return array_combine($matches[1], $matches[2]);
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

    /**
     * Zerlegt ein PHP-Muster in seinen Rumpf, ohne Delimiter und Flags.
     *
     * Verglichen wird nur der Rumpf. Die Flags bleiben bewusst draussen:
     * JavaScript braucht `g`, wo `preg_replace()` ohnehin global ersetzt,
     * und ein Vergleich meldete dort eine Abweichung, die keine ist. Der
     * Preis ist benannt statt verschwiegen - eine Flag-Drift, etwa ein
     * verlorenes `i` am URL-Schema, faengt dieser Waechter nicht.
     */
    private function regexRumpf(string $muster): string {
        $delimiter = $muster[0];
        $ende = strrpos($muster, $delimiter);

        $this->assertGreaterThan(
            0,
            $ende,
            'Muster ohne schliessenden Delimiter: '.$muster
        );

        return substr($muster, 1, $ende - 1);
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

    // -----------------------------------------------------------
    // Validierungsmuster
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwaechter ueber die Validierungsmuster, die PHP und
    * JavaScript wortgleich fuehren muessen. Beide Seiten pruefen
    * denselben Wert, keine kennt die andere: Bleibt eine bei einer
    * Anforderungsaenderung zurueck, meldet die Client-Pruefung gruen,
    * waehrend der Server ablehnt - oder umgekehrt. Der Nutzer sieht ein
    * Formular, das sich selbst widerspricht.
    *
    * Das Praefix SHARED_PATTERN_ ist die Zusage, dass eine Gegenstelle
    * existiert; der Waechter sammelt genau darueber und deckt damit auch
    * jede kuenftige Konstante ab, ohne dass ihn jemand nachzieht. Nach
    * Wert entdoppelt, weil eine Klasse die Konstante einer anderen binden
    * darf.
    *
    * Geprueft wird das Vorkommen des Rumpfes, nicht seine Bindung an
    * eine bestimmte Stelle: Sechs der Muster stehen inline und haben
    * keine Deklaration, an der sich ankern liesse. Wer den Rumpf in eine
    * unbenutzte Variable verschoebe und der bewachten Stelle einen
    * anderen Wert gaebe, kaeme durch. Eine gewoehnliche Drift - jemand
    * aendert das Muster - entfernt den alten Rumpf und faellt auf.
    *
    * Gefordert ist **genau ein** Vorkommen je Rumpf, nicht mindestens
    * eines. Damit haelt der Waechter zugleich die seitengleiche
    * Doppelung fern: Eine zweite Kopie in derselben Datei wuerde sonst
    * neben der bewachten stehen und unbemerkt driften.
    *
    ***************************************************************/
    function testValidierungsmusterStehenInBeidenSprachenGleich(): void {
        $datei = self::JS_VALIDATOR;
        $code  = $this->nurCode($this->jsQuelle($datei));

        $muster = array_unique(array_merge(
            $this->konstantenMitPraefix('SchemaOrgData_Validator', 'SHARED_PATTERN_'),
            $this->konstantenMitPraefix(
                'SchemaOrgData_PersonsRegistryService',
                'SHARED_PATTERN_'
            )
        ));
        sort($muster);

        $this->assertNotEmpty(
            $muster,
            'Keine SHARED_PATTERN_-Konstante gefunden - Praefix umbenannt?'
        );

        foreach($muster as $phpMuster) {
            $rumpf = $this->regexRumpf($phpMuster);
            $this->assertSame(
                1,
                substr_count($code, $rumpf),
                'PHP-Muster '.$phpMuster.' (Rumpf: '.$rumpf.') steht '
                    .substr_count($code, $rumpf).'-mal im Code von '.$datei
                    .', erwartet genau einmal'
            );
        }
    }

    /**
     * Liest eine einzelne Klassenkonstante ueber Reflection.
     *
     * Auch private Konstanten sind so lesbar. Das ist Absicht: Ob ein
     * Wert oeffentlich ist, entscheidet, wer ihn im Produktivcode bindet -
     * nicht, wer ihn hier prueft.
     */
    private function konstante(string $klasse, string $name): string {
        $konstanten = (new \ReflectionClass($klasse))->getConstants();

        $this->assertArrayHasKey(
            $name,
            $konstanten,
            $klasse.' hat keine Konstante '.$name
        );

        return (string) $konstanten[$name];
    }

    // -----------------------------------------------------------
    // Statuswerte und Klassenstamm der Feld-Rueckmeldung
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwaechter ueber die Statuswerte einer Feldpruefung. Sie
    * landen ungeprueft in einem CSS-Klassennamen: Wer in PHP oder JS
    * einen Wert vertippt, erzeugt eine Klasse ohne Regel, und die
    * Meldung erscheint in Standardfarbe statt rot - sichtbar nur im
    * Browser, von keinem Fachtest erfasst.
    *
    * `info` ist die dokumentierte Ausnahme: Diesen vierten Wert erzeugt
    * ausschliesslich die AJV-Meldung in `js/validator.js`. PHP kann ihn
    * nicht ausgeben, weil `renderValidationFeedback()` fuer jeden Wert
    * ausserhalb seiner Icon-Liste Leerstring liefert. Er ist deshalb
    * kein geteilter Wert und traegt kein SHARED_-Praefix.
    *
    ***************************************************************/
    function testFeedbackStatuswerteDeckenSichMitDerJsSeite(): void {
        $datei = self::JS_VALIDATOR;
        $code  = $this->nurCode($this->jsQuelle($datei));

        $php = $this->konstantenMitPraefix(
            'SchemaOrgData_Validator',
            'SHARED_STATUS_'
        );
        $this->assertSame(
            ['error', 'ok', 'warning'],
            $php,
            'Erwartet: genau drei geteilte Statuswerte in PHP'
        );

        preg_match_all('#status:\s*\'(\w+)\'#', $code, $matches);
        $js = array_values(array_unique($matches[1]));
        sort($js);

        $this->assertSame(
            $php,
            $js,
            'PHP (SchemaOrgData_Validator::SHARED_STATUS_*): '.implode(', ', $php)
                .' | JS ('.$datei.', erzeugte status-Werte): '.implode(', ', $js)
        );

        // Der vierte JS-Wert reist nicht ueber die Grenze, muss aber eine
        // CSS-Regel haben wie die drei geteilten - sonst erschiene die
        // AJV-Meldung farblos.
        $css = $this->pluginQuelle(self::PHP_ADMIN_PAGE_RENDERER);
        foreach(array_merge($php, ['info']) as $status) {
            $this->assertStringContainsString(
                'schemaOrgData-feedback--'.$status,
                $css,
                'Statuswert "'.$status.'" ohne CSS-Regel in '
                    .self::PHP_ADMIN_PAGE_RENDERER
            );
        }
    }

    /***************************************************************
    *
    * Wertwaechter ueber den Klassenstamm, an den beide Seiten den
    * Statuswert anhaengen. Er stand bis zu diesem Schritt zweimal im
    * Code von `js/validator.js`; die Zuweisung ist die
    * Zusammenfuehrung, dieser Waechter haelt sie fest.
    *
    ***************************************************************/
    function testFeedbackKlassenstammStehtInBeidenSprachenGleich(): void {
        $datei = self::JS_VALIDATOR;
        $js    = $this->jsQuelle($datei);
        $zuweisungen = $this->jsZuweisungen($js);

        $this->assertArrayHasKey(
            'FEEDBACK_CLASS',
            $zuweisungen,
            'Erwartet: eine Zuweisung var FEEDBACK_CLASS in '.$datei
        );
        $this->assertSame(
            \SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK,
            $zuweisungen['FEEDBACK_CLASS'],
            'PHP (SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK): "'
                .\SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK
                .'" gegen JS ('.$datei.', var FEEDBACK_CLASS): "'
                .$zuweisungen['FEEDBACK_CLASS'].'"'
        );

        // Genau einmal, nicht mindestens einmal: Die Zuweisung IST die
        // G5-Zusammenfuehrung. Eine wieder eingeschmuggelte zweite Kopie
        // stuende neben der bewachten und driftete unbemerkt.
        $this->assertSame(
            1,
            substr_count(
                $this->nurCode($js),
                \SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK
            ),
            'Der Klassenstamm steht mehr als einmal im Code von '.$datei
                .' - die Zusammenfuehrung ist rueckgaengig gemacht.'
        );
    }

    // -----------------------------------------------------------
    // Suffixe der Element-IDs
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Mengenwaechter ueber die Suffixe, die JavaScript an eine
    * Element-ID anhaengt. PHP baut die ID des Rueckmeldungs-Elements
    * als Feld-ID plus Suffix, JavaScript bildet dieselbe Ableitung -
    * laufen sie auseinander, sucht der Browser ein Element, das es
    * nicht gibt, und die Rueckmeldung bleibt aus. Kein Fehler, nur
    * Stille.
    *
    * Geprueft wird die ganze Menge, nicht nur der eine Wert: Ein
    * zweites angehaengtes Suffix ohne PHP-Gegenstelle faellt damit auf,
    * bevor es sich einbuergert.
    *
    ***************************************************************/
    function testAngehaengteIdSuffixeDeckenSichMitDerPhpSeite(): void {
        $datei = self::JS_VALIDATOR;
        $code  = $this->nurCode($this->jsQuelle($datei));

        $php = $this->konstantenMitPraefix(
            'SchemaOrgData_FormRenderer',
            'SHARED_ID_SUFFIX_'
        );

        preg_match_all('#\\+\\s*\'(_[a-z0-9]+)\'#', $code, $matches);
        $js = array_values(array_unique($matches[1]));
        sort($js);

        $this->assertSame(
            $php,
            $js,
            'PHP (SchemaOrgData_FormRenderer::SHARED_ID_SUFFIX_*): '
                .implode(', ', $php).' | JS ('.$datei
                .', an eine ID angehaengte Suffixe): '.implode(', ', $js)
        );
    }

    /***************************************************************
    *
    * Waechter ueber die Suffixe der Zeitfenster im
    * Oeffnungszeiten-Widget. JavaScript liest aus der fertigen
    * Element-ID zurueck, welches Feld zu welchem gehoert - teils als
    * Literal, teils als regulaerer Ausdruck ueber das Ende der ID.
    *
    * Die Alternation wird aus den PHP-Konstanten zusammengesetzt statt
    * auf Teilketten geprueft: '_from' steht in der Quelldatei nur
    * innerhalb von '_from2', ein Enthaltensein-Test liefe deshalb auch
    * dann gruen, wenn PHP das erste Von-Feld umbenannt haette.
    *
    ***************************************************************/
    function testZeitfensterSuffixeStehenInBeidenSprachenGleich(): void {
        $datei = self::JS_VALIDATOR;
        $code  = $this->nurCode($this->jsQuelle($datei));

        $von   = $this->konstante('SchemaOrgData_FormRenderer', 'SHARED_ID_SLOT_FROM');
        $bis   = $this->konstante('SchemaOrgData_FormRenderer', 'SHARED_ID_SLOT_TO');
        $von2  = $this->konstante('SchemaOrgData_FormRenderer', 'SHARED_ID_SLOT_FROM2');

        // Rueckwaerts vom zweiten Von-Feld auf das erste Bis-Feld.
        $this->assertStringContainsString(
            $von2.'$',
            $code,
            'Erwartet: ein Muster auf "'.$von2.'" am ID-Ende in '.$datei
        );
        $this->assertStringContainsString(
            '\''.$bis.'\'',
            $code,
            'Erwartet: das Literal "'.$bis.'" in '.$datei
        );

        // Vorwaerts vom ersten Zeitfenster auf das zweite Von-Feld.
        $alternation = '_('.ltrim($von, '_').'|'.ltrim($bis, '_').')$';
        $this->assertStringContainsString(
            $alternation,
            $code,
            'Erwartet: die Alternation "'.$alternation.'" in '.$datei
                .' - sie verbindet beide Zeitfenster'
        );
        $this->assertStringContainsString(
            '\''.$von2.'\'',
            $code,
            'Erwartet: das Literal "'.$von2.'" in '.$datei
        );
    }
}
