<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_OpeningHoursHelper
*
* Reine Array-/String-Transformationen rund um das Öffnungszeiten-
* Widget (siehe README.md, Abschnitt "Öffnungszeiten"): Erkennung
* roher Pro-Tag-Werte, Parsen von openingHours-Arrays in
* schema.org-Notation zu Von/Bis-Zeiten je Wochentag sowie die
* Umkehrung davon. Zustandslos, keine CMS-Abhängigkeiten.
*
***************************************************************/
class SchemaOrgData_OpeningHoursHelper {

    /**
     * Wochentags-Kürzel des Widgets auf die kanonischen schema.org-URIs.
     * Eigenschaft des Vokabulars, nicht Konfiguration je Type - deshalb
     * Konstante statt ui:-Property im Schema.
     */
    private const DAY_OF_WEEK_URIS = [
        'Mo' => 'https://schema.org/Monday',
        'Tu' => 'https://schema.org/Tuesday',
        'We' => 'https://schema.org/Wednesday',
        'Th' => 'https://schema.org/Thursday',
        'Fr' => 'https://schema.org/Friday',
        'Sa' => 'https://schema.org/Saturday',
        'Su' => 'https://schema.org/Sunday',
    ];

    /**
     * Wochentagsvektor des Widgets in Anzeigereihenfolge, wenn ein
     * Feld-Schema keinen eigenen vorgibt. Die Kürzel sind exakt die
     * Schlüssel von DAY_OF_WEEK_URIS.
     */
    private const DEFAULT_DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    /***************************************************************
    *
    * Löst aus einem Feld-Schema den Wochentagsvektor auf. `ui:days`
    * stammt aus einer Schema-Datei und muss deshalb weder vorhanden
    * noch typrichtig sein: nur ein Array gilt, jeder andere Wert fällt
    * auf den Standardvektor zurück.
    *
    * Statisch, weil zustandslos - so erreichen auch Aufrufstellen ohne
    * Helper-Instanz (SchemaOrgData_Validator::validateFormData()) die
    * Auflösung, ohne dass deren Signatur sich ändern müsste.
    *
    * @param array<string, mixed> $fieldSchema Feld-Schema des Widgets (ui:days)
    * @return string[] Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    *
    ***************************************************************/
    public static function resolveDays(array $fieldSchema): array {
        $days = $fieldSchema[SchemaOrgData_SchemaRepository::UI_DAYS] ?? null;

        return is_array($days) ? $days : self::DEFAULT_DAYS;
    }

    /***************************************************************
    *
    * Erkennt, ob ein openingHours-Wert bereits als rohe Pro-Tag-Werte
    * vorliegt (["Mo" => ["from" => ..., "to" => ...], ...], aus dem
    * POST nach fehlgeschlagenem Save - siehe renderScopeSection) statt
    * als openingHours-Array in schema.org-Notation (["Mo-Fr 09:00-18:00"]).
    *
    ***************************************************************/
    public function isPerDayOpeningHoursValue(array $value): bool {
        foreach($value as $entry) {
            return is_array($entry);
        }

        return false;
    }

    /***************************************************************
    *
    * Zerlegt ein openingHours-Array (schema.org-Notation, z. B.
    * "Mo-Fr 09:00-18:00") in Von/Bis-Zeiten je Wochentag.
    * Mehrere Einträge für denselben Tag werden gesammelt und nach
    * from-Zeit sortiert: frühester Eintrag → Hauptzeitraum
    * (from/to), zweiter Eintrag (falls vorhanden) → zweiter
    * Zeitraum (from2/to2). Ein dritter Eintrag für denselben Tag
    * wird ignoriert (außerhalb des Widget-Scopes).
    *
    * Tageskürzel werden unabhängig von der Groß-/Kleinschreibung
    * erkannt, ein Bereich darf über das Wochenende hinweg laufen
    * ("Fr-Mo" ergibt Fr, Sa, Su, Mo).
    *
    * $dropped sammelt einen Marker je verworfenem Eintrag (Nicht-String,
    * unlesbare Notation, unauflösbares Tageskürzel) - der Rohwert selbst
    * fließt bewusst nicht hinein, da Aufrufer daraus ausschließlich die
    * Frage "ist etwas verlorengegangen" beantworten, nie den Wert
    * anzeigen. Wortgleich mit buildOpeningHoursSpecifications() bleiben
    * dort ausschließlich Notations-Regex und Tagesauflösung - der
    * Sammler ist reiner Re-Display-Bedarf dieser Methode und hat dort
    * keine Entsprechung.
    *
    * @param string[] $openingHours z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @param bool[] $dropped Referenzparameter - ein Eintrag je verworfener Notation
    * @return array<string,array{from:string,to:string,from2:string,to2:string}> je Tag (leer = geschlossen)
    *
    ***************************************************************/
    public function parseOpeningHours(array $openingHours, array $days, array &$dropped = []): array {
        $collected = [];
        foreach($days as $day) {
            $collected[$day] = [];
        }

        // Fremde JSON-LD-Blöcke schreiben Tageskürzel häufig klein
        // ("mo 09:00-17:00"). Der Nachschlag läuft deshalb über einen
        // einmal vorberechneten, case-insensitiven Index; das erste
        // Vorkommen gewinnt. Maßgeblich für alles Weitere bleibt die
        // kanonische Schreibweise aus $days.
        $dayIndex = [];
        foreach($days as $position => $day) {
            $key = strtolower((string) $day);
            if(!isset($dayIndex[$key])) {
                $dayIndex[$key] = $position;
            }
        }
        $dayCount = count($days);

        foreach($openingHours as $entry) {
            if(!is_string($entry)) {
                $dropped[] = true;
                continue;
            }

            if(!preg_match('/^([A-Za-z]{2})(?:-([A-Za-z]{2}))? ([0-9]{2}:[0-9]{2})-([0-9]{2}:[0-9]{2})$/', trim($entry), $matches)) {
                $dropped[] = true;
                continue;
            }

            [, $startDay, $endDay, $from, $to] = $matches;
            $endDay = $endDay !== '' ? $endDay : $startDay;

            $startIndex = $dayIndex[strtolower($startDay)] ?? false;
            $endIndex = $dayIndex[strtolower($endDay)] ?? false;
            if($startIndex === false or $endIndex === false) {
                $dropped[] = true;
                continue;
            }

            // Bereiche über das Wochenende hinweg ("Fr-Mo") sind in
            // fremden openingHours-Strings verbreitet und laufen zyklisch
            // weiter, statt leer auszugehen. Die Schrittzahl deckt höchstens
            // eine Woche ab, damit kein Umlauf entsteht; "Mo-Mo" bleibt ein
            // einzelner Tag.
            $steps = ($endIndex - $startIndex + $dayCount) % $dayCount;
            for($step = 0; $step <= $steps; $step++) {
                $collected[$days[($startIndex + $step) % $dayCount]][] = ['from' => $from, 'to' => $to];
            }
        }

        $result = [];
        foreach($days as $day) {
            $entries = $collected[$day];
            usort($entries, fn($a, $b) => strcmp($a['from'], $b['from']));
            $result[$day] = [
                'from'  => $entries[0]['from'] ?? '',
                'to'    => $entries[0]['to'] ?? '',
                'from2' => $entries[1]['from'] ?? '',
                'to2'   => $entries[1]['to'] ?? '',
            ];
        }

        return $result;
    }

    /***************************************************************
    *
    * Baut aus den Von/Bis-Zeiten je Wochentag ein openingHours-Array
    * in schema.org-Notation. Aufeinanderfolgende Tage mit identischen
    * Zeiten werden zu einem Bereich (z. B. "Mo-Fr 09:00-18:00")
    * zusammengefasst. Tage ohne Zeiten ("geschlossen") werden
    * ausgelassen. $fromKey/$toKey wählen das Felderpaar (Hauptzeitraum
    * "from"/"to" oder zweiter Zeitraum "from2"/"to2").
    *
    * @param array<string, array<string, string>> $perDay je Tag Zeitpaare
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @param string $fromKey Schlüssel für Von-Zeit im $perDay-Eintrag
    * @param string $toKey   Schlüssel für Bis-Zeit im $perDay-Eintrag
    * @return string[] z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    *
    ***************************************************************/
    public function buildOpeningHoursArray(array $perDay, array $days, string $fromKey = 'from', string $toKey = 'to'): array {
        $result = [];
        $rangeStart = null;
        $rangeEnd = null;
        $rangeFrom = '';
        $rangeTo = '';

        $flush = function () use (&$result, &$rangeStart, &$rangeEnd, &$rangeFrom, &$rangeTo) {
            if($rangeStart === null) {
                return;
            }
            $dayPart = ($rangeStart === $rangeEnd) ? $rangeStart : $rangeStart.'-'.$rangeEnd;
            $result[] = $dayPart.' '.$rangeFrom.'-'.$rangeTo;
            $rangeStart = null;
            $rangeEnd = null;
        };

        foreach($days as $day) {
            // Ein nicht-skalarer Teilwert gilt wie ein nicht gesendeter, der
            // Tag damit als geschlossen: der Cast trüge sonst das
            // Ersatzliteral "Array" als Uhrzeit in die Notation.
            $rawFrom = $perDay[$day][$fromKey] ?? '';
            $rawTo = $perDay[$day][$toKey] ?? '';
            $from = is_scalar($rawFrom) ? trim((string) $rawFrom) : '';
            $to = is_scalar($rawTo) ? trim((string) $rawTo) : '';

            if($from === '' or $to === '') {
                $flush();
                continue;
            }

            if($rangeStart !== null and $from === $rangeFrom and $to === $rangeTo) {
                $rangeEnd = $day;
                continue;
            }

            $flush();
            $rangeStart = $day;
            $rangeEnd = $day;
            $rangeFrom = $from;
            $rangeTo = $to;
        }
        $flush();

        return $result;
    }

    /***************************************************************
    *
    * Übersetzt ein openingHours-Array (schema.org-Notation) in eine
    * Liste von OpeningHoursSpecification-Objekten mit kanonischen
    * dayOfWeek-URIs. Gegenstück zu parseOpeningHours() für die
    * Ausgabeseite: Ein kompakter Eintrag ergibt genau ein Objekt,
    * dessen dayOfWeek alle Tage des Bereichs aufzählt. Anders als
    * parseOpeningHours() ist die Abbildung damit verlustfrei -
    * beliebig viele Zeiträume je Tag bleiben erhalten. dayOfWeek ist
    * auch bei einem einzelnen Tag ein Array, damit Konsumenten nicht
    * zwischen zwei Formen unterscheiden müssen.
    *
    * Die Reihenfolge der Eingabe bleibt erhalten; sie ist semantisch
    * bedeutungslos und wird deshalb nicht sortiert.
    *
    * Wie dort werden Tageskürzel unabhängig von der
    * Groß-/Kleinschreibung erkannt und ein Bereich darf über das
    * Wochenende hinweg laufen ("Fr-Mo" zählt Fr, Sa, Su, Mo auf).
    *
    * Die Notations-Regex und die Auflösung der Tagesbereiche sind
    * bewusst Kopien aus parseOpeningHours() statt einer gemeinsamen
    * Extraktion: jene Methode bedient den verlustbehafteten
    * Re-Display-Pfad des Widgets und bleibt davon unberührt. Beide
    * Stellen werden wortgleich gehalten und gemeinsam gepflegt - das
    * gilt ausdrücklich nur für Notations-Regex und Tagesauflösung.
    * Diese Methode kennt keinen Sammler verworfener Einträge: sie ist
    * der Ausgabepfad, für den es keinen Adressaten einer Meldung gibt.
    *
    * @param string[] $openingHours z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @return array<int, array{@type:string,dayOfWeek:string[],opens:string,closes:string}>
    *
    ***************************************************************/
    public function buildOpeningHoursSpecifications(array $openingHours, array $days): array {
        $result = [];

        // Fremde JSON-LD-Blöcke schreiben Tageskürzel häufig klein
        // ("mo 09:00-17:00"). Der Nachschlag läuft deshalb über einen
        // einmal vorberechneten, case-insensitiven Index; das erste
        // Vorkommen gewinnt. Maßgeblich für alles Weitere bleibt die
        // kanonische Schreibweise aus $days.
        $dayIndex = [];
        foreach($days as $position => $day) {
            $key = strtolower((string) $day);
            if(!isset($dayIndex[$key])) {
                $dayIndex[$key] = $position;
            }
        }
        $dayCount = count($days);

        foreach($openingHours as $entry) {
            if(!is_string($entry)) {
                continue;
            }

            if(!preg_match('/^([A-Za-z]{2})(?:-([A-Za-z]{2}))? ([0-9]{2}:[0-9]{2})-([0-9]{2}:[0-9]{2})$/', trim($entry), $matches)) {
                continue;
            }

            [, $startDay, $endDay, $from, $to] = $matches;
            $endDay = $endDay !== '' ? $endDay : $startDay;

            $startIndex = $dayIndex[strtolower($startDay)] ?? false;
            $endIndex = $dayIndex[strtolower($endDay)] ?? false;
            if($startIndex === false or $endIndex === false) {
                continue;
            }

            // Bereiche über das Wochenende hinweg ("Fr-Mo") sind in
            // fremden openingHours-Strings verbreitet und laufen zyklisch
            // weiter, statt leer auszugehen. Die Schrittzahl deckt höchstens
            // eine Woche ab, damit kein Umlauf entsteht; "Mo-Mo" bleibt ein
            // einzelner Tag.
            $steps = ($endIndex - $startIndex + $dayCount) % $dayCount;
            $dayOfWeek = [];
            for($step = 0; $step <= $steps; $step++) {
                $day = $days[($startIndex + $step) % $dayCount];
                // Ein Kürzel ohne kanonische URI lässt den Tag entfallen,
                // statt einen unauflösbaren Wert ins JSON-LD zu schreiben.
                if(isset(self::DAY_OF_WEEK_URIS[$day])) {
                    $dayOfWeek[] = self::DAY_OF_WEEK_URIS[$day];
                }
            }

            if($dayOfWeek === []) {
                continue;
            }

            $result[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => $dayOfWeek,
                'opens'     => $from,
                'closes'    => $to,
            ];
        }

        return $result;
    }
}
