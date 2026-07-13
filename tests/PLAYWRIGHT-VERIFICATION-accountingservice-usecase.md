# Playwright-Verifikation — AccountingService-Use-Case (stb-hader)

> **⚠️ VERALTET — nicht mehr aktuell gepflegt.** Seit 2026-07-13 ersetzt
> durch `RCLaufRueckmeldung_accountingserviceusecase.xlsx` als
> kanonische Quelle für den AccountingService-Use-Case. Diese Datei
> spiegelt den Stand `0.9.0-rc` (2026-07-09) wider und enthält
> mindestens eine seither falsch gewordene Aussage (Dev-Reset-Button,
> siehe Korrektur unten) — nicht mehr für Testläufe verwenden, nur noch
> als historische Referenz.

> Eigenständiger, use-case-spezifischer Testplan, getrennt von
> `tests/PLAYWRIGHT-VERIFICATION.md` (generische Phasenregression). Prüft
> ein reales End-to-End-Szenario (Steuerkanzlei-Website) statt isolierter
> Mechanik — deckt damit Integrationsprobleme ab, die die generischen
> Phasentests nicht zwingend finden (siehe Fahrplan-Schritte 6a–6f, aus
> denen mehrere hier re-verifizierte Bugfixes stammen).
>
> **Stand:** 2026-07-09 · aktualisiert für den Feature-Freeze-RC-Lauf
> (Fahrplan-Schritt 8) gegen `0.9.0-rc`, 627 Tests / 1311 Assertions /
> 4 Skips / 0 Failures (PHP) + 67 Jest-Tests. Ursprünglicher Lauf:
> 2026-07-07, Ausgangsversion `0.4.66-beta`.
>
> **Änderungen gegenüber dem 0.4.66-beta-Stand dieses Dokuments:** der
> temporäre `[TEMP]`-Dev-Reset-Button (vormals Fahrplan-Schritt 6f) wurde
> im Freeze-Fix-Batch vollständig entfernt — alle Verweise darauf sind
> unten gestrichen, Zurücksetzen erfolgt wie in `PLAYWRIGHT-VERIFICATION.md`
> Phase 0b ausschließlich über das Type-Dropdown „– kein Schema –" +
> Speichern. Phase 1 (`geo`), Phase 3 (`hiringOrganization`/`jobLocation`),
> Phase 5 (Speicherbarkeit ohne `recipient`-POST-Wert) und Phase 6
> (Debug-Widget-Event-Korrektur) wurden um die seither hinzugekommenen
> Fixes aus der Playwright-RC-Checkliste (`doc/TODO.md`) ergänzt bzw.
> korrigiert — siehe die jeweiligen Phasen-Vermerke.
>
> **Bekannte Lücken dieses Use-Case (nicht Teil des Steuerkanzlei-
> Szenarios):** `ProfessionalService` (anderer Schema-Type als
> `AccountingService`, hier nicht konfiguriert), NGO-/Organization-
> Feldsymmetrie (Global ist hier `AccountingService`, nicht NGO/
> Organization), generische UX-Sichtprüfungen (max-width, Pflichtfeld-
> Legende, Textarea-Breiten) — diese drei Punkte sind nicht
> szenario-spezifisch und sollten bei Bedarf separat/kurz nachgeprüft
> werden, nicht durch Verbiegen dieses Use-Case.
>
> **Vorbereitung (verbindlich, siehe CLAUDE.md „Playwright-Sessions"):**
> eigene, dedizierte Session — nicht mit einer Code-Änderungs-Session
> verschränken. MCP-Server frisch starten (`claude mcp list` muss
> `playwright: ... ✔ Connected` zeigen, nicht nur ein neuer Chat-Tab).

---

## Zielstruktur der Testinstallation

| Ebene | Kategorie/Seite | Schema-Type | Zweck |
|---|---|---|---|
| Global | — | `AccountingService` | Haupt-Identität der Kanzlei, `@id`-Anker `#organization` |
| Kategorie | „Unsere Leistungen" | `Article` | Leistungsbeschreibung, Familie-Einschränkung testen |
| Seite | „Unsere Leistungen" › „Steuerberatung" | `JobPosting` | Datumsfelder + `employmentType`-Label testen |
| Seite | neu, beliebige Kategorie (z. B. „Aktuelles") | `Event` | `organizer` → globale `@id`-Referenz testen |
| Seite | neu, beliebige Kategorie (z. B. „Spenden"/„Über uns") | `DonateAction` | `recipient` → globale `@id`-Referenz testen |

Die Global-Ebene ist noch **nicht** befüllt — sie wird in Phase 1 erstmals
aus dem echten, bestehenden Template-Block importiert (kein Neuanlegen
mit Testwerten). Kategorie/Seite („Unsere Leistungen"/„Steuerberatung")
sind aus vorherigen Sessions bereits mit Test-/Demo-Daten angelegt —
Formularfelder in Phase 2/3 final korrigieren statt neu anlegen.
Event/DonateAction sind neu für diesen Testplan.

---

## Phase 0 — Vorbereitung

- [ ] `claude mcp list` bestätigt aktive Playwright-Verbindung
- [ ] Browser öffnet die Admin-Seite des Plugins auf `stb-hader`
- [ ] Aktuelle Plugin-Version im Seitenfuß/Admin-Header zeigt `0.9.0-rc`
- [ ] Vorhandene Test-/Demo-Konfigurationen (Global, „Unsere Leistungen",
      „Steuerberatung") sichten — Screenshot als Ausgangszustand
- [ ] Falls Neuanlage gewünscht statt Korrektur: Type-Dropdown der
      betroffenen Ebene auf „– kein Schema –" umstellen + speichern
      (kein separater Löschen-Button, siehe `PLAYWRIGHT-VERIFICATION.md`
      Phase 0b — der frühere `[TEMP]`-Dev-Reset-Button wurde im
      Freeze-Fix-Batch entfernt), **nicht** einzeln Felder manuell leeren

---

## Phase 1 — Global: bestehenden JSON-LD-Block erkennen, importieren, `AccountingService` final konfigurieren

**Reale Ausgangsbedingung dieses Use-Case (kein Hypothese-Test):** Auf
`stb-hader` liegt im Layout-Template bereits ein händisch eingebundener
JSON-LD-Block. Dieser Block gilt für die gesamte Website und wird daher
ausschließlich im Geltungsbereich **Global** erkannt und angezeigt — nicht
auf Kategorie-/Seitenebene (siehe README.md).

- [ ] Global-Sektion öffnen — Hinweis „Auf dieser Seite wurde bereits ein
      JSON-LD-Block gefunden" (bzw. die Global-spezifische Formulierung
      „Im Layout-Template wurde…", Fix aus Schritt 3) erscheint
- [ ] „Vorhandenes beibehalten" ist als Standard aktiv markiert
- [ ] Keep-Konsequenz-Hinweis sichtbar („Solange 'Vorhandenes beibehalten'
      gewählt ist, gibt das Plugin … keine eigene JSON-LD-Ausgabe aus…")
- [ ] Button „Erkannten Block übernehmen" klicken — Import-Textarea wird
      mit dem tatsächlichen, bestehenden Block-Inhalt vorbefüllt
- [ ] **UX-Mini-Batch, RC-Checkliste (Import-Vorschau, seit diesem
      Dokument-Stand neu):** vor dem Klick auf „Importieren" erscheint
      eine read-only Vorschau des zu importierenden Inhalts als
      pretty-geprintetes, escaped `<pre>`-Element (nicht mehr die reine
      Roh-Textarea ohne Vorschau) — zusätzlich ein „Vollansicht"-Link/
      -Button analog zum Debug-Widget; Klick öffnet einen Dialog mit dem
      vollständigen Inhalt, Schließen funktioniert
- [ ] „Importieren" klicken:
  - [ ] Erfolgsmeldung „Import übernommen — bitte die befüllten Felder
        unten prüfen und anschließend speichern." erscheint (nicht die
        normale Speicher-Erfolgsmeldung)
  - [ ] Formularfelder sind mit den **echten Produktivwerten** aus dem
        Template-Block befüllt (Name, URL, Telefon, Adresse etc. der
        Kanzlei — nicht die bisherigen Test-/Demo-Werte)
  - [ ] Falls der Block `openingHours` in komprimierter Notation enthält
        (`"Mo-Fr 08:00-12:00"`): **Fix `ef9b67f`** — Öffnungszeiten-Widget
        zeigt die Werte korrekt in der Pro-Tag-Struktur, keine
        Fehlinterpretation
  - [ ] Unbekannte Properties aus dem Block landen im Erweiterungsfeld
        (nicht stillschweigend verworfen)
  - [ ] **Batch B, RC-Checkliste:** Falls der Block `geo`
        (`GeoCoordinates`) enthält — landet im regulären `geo`-Formularfeld
        (`latitude`/`longitude`), **nicht** im Erweiterungsfeld. Enthält
        der reale Template-Block kein `geo`, diesen Punkt stattdessen
        isoliert nachprüfen (`{"geo":{"@type":"GeoCoordinates","latitude":
        48.13,"longitude":11.57}}` ins Import-Feld einfügen, Import
        auslösen, Ergebnis prüfen, anschließend Import wieder verwerfen
        ohne zu speichern)
- [ ] Formular **noch nicht speichern** — erst die importierten Felder
      gegen das Original prüfen/korrigieren:
  - [ ] Alle Pflichtfelder plausibel befüllt
  - [ ] **Öffnungszeiten — Re-Verifikation mehrerer Fixes:**
    - [ ] **Fix `94ef495`:** Pause-Feld `Von` (`_from2`) korrekt als
          „Von" behandelt, nicht mit „Bis" vertauscht
    - [ ] **Fix `94ef495`/`808a5c2`:** Hauptzeitraum-„Bis" nachträglich so
          ändern, dass es mit der Pause überlappt — Live-Fehlermeldung an
          der Pause erscheint sofort, ohne dass das Pausenfeld selbst
          angefasst wird
  - [ ] **`geo`-Widget (Batch B, RC-Checkliste):** Feld sichtbar für
        `AccountingService` (Teil der LocalBusiness-Familie).
        `latitude`/`longitude` einzeln UND zusammen befüllen, speichern —
        beide Varianten erfolgreich
    - [ ] **Paar-Pflicht:** nur `latitude` ODER nur `longitude` ausfüllen,
          Blur → Live-Fehler; Speichern-Versuch → serverseitig blockiert
    - [ ] Beide Felder leer lassen → kein Fehler, `geo` bleibt in der
          JSON-LD-Ausgabe weg
- [ ] Radio-Auswahl von „Vorhandenes beibehalten" auf „Mit
      Plugin-Konfiguration überschreiben" umstellen
- [ ] Speichern — Erfolgsmeldung „Die Konfiguration wurde gespeichert."
      erscheint
- [ ] Seite neu laden — **Fix `9fb16f4`:** ein absichtlich stehen
      gelassener Öffnungszeiten-Überlappungsfehler wird auch im Redisplay
      (nach Reload) weiterhin angezeigt, nicht nur live vor dem Speichern
- [ ] Frontend-Ausgabe **noch nicht** prüfen (der alte Template-Block ist
      noch nicht entfernt — beide Blöcke koexistieren bewusst bis
      Phase 7, um sie in Phase 6 nebeneinander vergleichen zu können)

---

## Phase 2 — Kategorie „Unsere Leistungen": `Article` final konfigurieren

- [ ] Überschrift, Beschreibung, Autor mit echten (nicht Platzhalter-)
      Werten befüllen
- [ ] **Fix `bd65b99` (Datumsfelder):**
  - [ ] Veröffentlichungsdatum im deutschen Format eingeben (z. B.
        `07.07.2026`) — wird akzeptiert, kein Fehler
  - [ ] Änderungsdatum absichtlich mit ungültigem Wert befüllen (z. B.
        `rwe`) — Live-Fehlermeldung erscheint sofort (vorher: keine
        Reaktion)
  - [ ] Speichern mit dem ungültigen Wert versuchen — wird **serverseitig
        blockiert**, nicht gespeichert (vorher: wurde unvalidiert
        übernommen)
  - [ ] Ungültigen Wert korrigieren, erneut speichern, Seite neu laden —
        Datum erscheint im Formular wieder im deutschen Format
        `TT.MM.YYYY` (Redisplay-Rückformatierung), nicht als ISO
- [ ] **Fix `8e74ad3` (LocalBusiness-Familie):**
  - [ ] Schema-Type-Dropdown auf dieser Kategorie-Ebene öffnen — enthält
        **nur** `AccountingService` aus der LocalBusiness-Familie, **nicht**
        `LocalBusiness`/`ProfessionalService`/`LegalService`/
        `MedicalBusiness`
  - [ ] **Korrigiert (RC-Lauf, 2026-07-09 — ursprüngliche Formulierung war
        ungenau):** Content-Types zeigen auf Kategorie-Ebene **nur die für
        `ui:scopes: ["category", "page"]` zulässigen** Types — aktuell
        `Article`, `FAQPage`. `Event`/`JobPosting`/`DonateAction` sind
        laut Schema auf `ui:scopes: ["page"]` beschränkt und erscheinen
        auf Kategorie-Ebene **zu Recht nicht** — kein Bug, wenn sie
        fehlen. (Verifiziert per `grep -n "ui:scopes"
        plugins/schemaOrgData/schemas/*.json`.)
  - [ ] Hinweistext „Weitere Geschäftsklassifikationen sind
        ausgeblendet…" erscheint unterhalb des Dropdowns
- [ ] Speichern, Erfolgsmeldung prüfen

---

## Phase 3 — Seite „Steuerberatung": `JobPosting` final konfigurieren

- [ ] Stellentitel, Beschreibung mit echten Werten befüllen
- [ ] **Batch A, RC-Checkliste (`datePosted`-Pflichtfeld):** „Ausgeschrieben
      am" leer lassen, restliche Pflichtfelder befüllen, speichern —
      MUSS mit Pflichtfeld-Fehler blockiert werden (vorher optional)
- [ ] **Fix `bd65b99` (Datumsfelder):** „Ausgeschrieben am"/„Bewerbungsfrist"
      im deutschen Format eingeben, Format-Validierung wie in Phase 2
      gegenprüfen (Live + serverseitig)
- [ ] **Fix `bd65b99`+Nachzieh (`employmentType`):**
  - [ ] Beschäftigungsart-Dropdown öffnen — zeigt übersetzte Labels
        („Vollzeit", „Teilzeit", …), **nicht** rohe Werte wie `FULL_TIME`
        oder die volle URI
  - [ ] „Vollzeit" auswählen, speichern, Seite neu laden — Auswahl bleibt
        korrekt als „Vollzeit" markiert (kein Zurückfallen auf die erste
        Option, siehe Hintergrund der Enum-Validierungs-Lücke)
- [ ] **Freeze-Fix-Batch, RC-Checkliste (`hiringOrganization`/
      `jobLocation`, seit diesem Dokument-Stand neu):**
  - [ ] Abschnitt „Arbeitgeber" (`hiringOrganization`, `id_reference_or_literal`)
        öffnen — Option „Verknüpfen mit globalem Knoten" zeigt einen
        auswählbaren Eintrag für die globale `AccountingService`-Identität
        aus Phase 1 (analog zu `organizer` bei Event)
  - [ ] Eintrag auswählen, speichern
  - [ ] Abschnitt „Arbeitsort" (`jobLocation`, `place`-Widget) mit
        Name + Adresse befüllen, speichern — Formular akzeptiert die
        Eingabe ohne Fehler
  - [ ] Seite neu laden — beide Auswahlen/Werte bleiben nach Reload
        korrekt erhalten (Redisplay)
- [ ] Website-URL mit gültigem Wert befüllen, `https://`-Validierung grün
- [ ] Speichern, Erfolgsmeldung prüfen

---

## Phase 4 — Neu: Seite `Event` mit globaler Organizer-Referenz

- [ ] Neue Seite anlegen (beliebige passende Kategorie, z. B. „Aktuelles"),
      Schema-Type `Event` wählen
- [ ] Name, Beginn (Datum/Zeit), optional Ende befüllen — deutsche
      Datumseingabe (`TT.MM.YYYY HH:MM`) testen, Live-Validierung grün
- [ ] **Nachtest Fix `0f1f4ef` (Cache-Busting `js/validator.js`,
      `0.9.18-rc`):** vor diesem Test einen echten Hard-Refresh
      (`Strg+Shift+R`) durchführen, damit ein eventuell noch gecachtes
      `validator.js` sicher nicht die Prüfung verfälscht. „Beginn" auf ein
      eindeutig vergangenes Datum setzen (z. B. „01.01.2020"), Feld
      verlassen — MUSS jetzt den gelben Warnhinweis „Der eingetragene
      Termin liegt in der Vergangenheit." zeigen (vorher: blieb
      fälschlich `✅ ok`, siehe `doc/TODO_erledigt.md`). Anschließend auf
      ein gültiges zukünftiges Datum korrigieren, bevor mit der Phase
      fortgefahren wird
- [ ] Veranstaltungsort (Name + Adresse) befüllen
- [ ] **Fix `8f6a1e3` (globale `@id`-Fragmente), Kernprüfung dieser
      Phase:**
  - [ ] Abschnitt „Veranstalter" öffnen — Option „Verknüpfen mit globalem
        Knoten" auswählen
  - [ ] **Vorher (bekannter Zustand vor `8f6a1e3`):** hier hätte der
        Hinweistext „Noch keine globale Identität konfiguriert…"
        gestanden, obwohl Global bereits als `AccountingService`
        konfiguriert ist. **Jetzt:** ein echter Eintrag für die
        Steuerkanzlei muss im Dropdown erscheinen und auswählbar sein
  - [ ] Eintrag auswählen, speichern
- [ ] Speichern, Erfolgsmeldung prüfen

---

## Phase 5 — Neu: Seite `DonateAction` mit globaler Recipient-Referenz

- [ ] Neue Seite anlegen (beliebige passende Kategorie, z. B. „Über uns"),
      Schema-Type `DonateAction` wählen
- [ ] Beschreibung befüllen
- [ ] `recipient` ist ein `id_reference`-Widget (rein deklarativ, kein
      Eingabefeld) — die angezeigte Info-Zeile muss die aufgelöste
      Ziel-URI der globalen `AccountingService`-Identität zeigen
      (`.../#organization`), nicht leer oder fehlerhaft sein
- [ ] Speichern, Erfolgsmeldung prüfen — **Freeze-Fix-Batch, RC-Checkliste:**
      da `recipient` rein deklarativ ist, wird kein `recipient`-Wert im
      POST mitgeschickt; das Speichern MUSS trotzdem erfolgreich sein
      (kein fälschlicher Pflichtfeld-Fehler zu `recipient`)

---

## Phase 6 — Frontend-Ausgabe

- [ ] Global-Seite (beliebige Seite ohne eigene Konfiguration) im
      Frontend aufrufen, Seitenquelltext prüfen: `AccountingService`-Block
      vollständig und korrekt, `@id` gesetzt
- [ ] „Unsere Leistungen"-Kategorieseite: `AccountingService` (geerbt/
      überschrieben) + `Article`-Block beide vorhanden
- [ ] „Steuerberatung"-Seite: zusätzlich `JobPosting`-Block, `employmentType`
      im JSON-LD als volle URI (`https://schema.org/FULL_TIME`), **nicht**
      als deutsches Label
- [ ] `Event`-Seite: `organizer.@id` verweist auf dieselbe URI wie der
      globale `AccountingService`-Block
- [ ] `DonateAction`-Seite: `recipient.@id` ebenso
- [ ] Alle Blöcke mit dem offiziellen Schema.org-Validator
      (validator.schema.org) gegenprüfen — keine Fehler
- [ ] Debug-Widget (falls `debug_output` aktiv) öffnen:
  - [ ] **Freeze-Fix-Batch, RC-Checkliste (korrigiert gegenüber dem
        0.4.66-beta-Stand dieses Dokuments):** Debug-Widget zeigt jetzt
        byte-identisches JSON zu der tatsächlichen Frontend-Ausgabe.
        `Event.location`/`organizer` MÜSSEN daher im Debug-Popup mit der
        aufgelösten `@id`-URI erscheinen — **nicht mehr** die rohe
        `{"_mode":...}`-Rohrepräsentation. Die frühere „bekannte,
        akzeptierte Einschränkung" an dieser Stelle ist damit behoben;
        weicht das beobachtete Verhalten davon ab, als echte Regression
        (NOK) werten, nicht als Altbekanntes abtun

---

## Phase 7 — Template-Migration

- [ ] Falls auf einer der Seiten noch ein alter JSON-LD-Block direkt in
      `template.html` eingebunden ist: Hinweis „Vorhandenes JSON-LD
      gefunden" prüfen
- [ ] Block manuell aus `template.html` entfernen
- [ ] Im Formular von „Vorhandenes beibehalten" auf „Mit
      Plugin-Konfiguration überschreiben" umstellen
- [ ] Speichern, Frontend-Ausgabe erneut prüfen — jetzt ausschließlich
      der Plugin-generierte Block, kein Duplikat

---

## Phase 8 — entfällt (Dev-Reset-Button entfernt)

Der `[TEMP]`-Dev-Reset-Button, den diese Phase ursprünglich prüfte, wurde
im Freeze-Fix-Batch (vor Fahrplan-Schritt 7) vollständig aus dem Plugin
entfernt — die damalige „Reminder, kein Test-Schritt"-Notiz an dieser
Stelle ist damit erledigt. Phase 8 entfällt komplett, kein Ersatzcheck
nötig.

> **Korrektur 2026-07-13:** Diese Aussage stimmte für `0.9.0-rc`, ist
> aber seit der Wiedereinführung des Dev-Reset-Buttons bei `0.9.1-rc`
> (`6c60153`, für die laufende RC-Testphase) nicht mehr aktuell. Der
> Button existiert weiterhin und ist in `doc/TODO.md`, Abschnitt
> „Offen", als oberster Punkt vor v1.0 zur Entfernung vorgesehen.

---

## Abschluss

- [ ] Alle Phasen dokumentiert (OK/NOK je Prüfpunkt, Screenshots bei
      NOK)
- [ ] Echte Regressionen (falls gefunden) als neue `TODO.md`-Einträge
      dokumentiert, mit Verweis auf diesen Testplan
- [ ] Ergebnis-Zusammenfassung zurück in den Chat für die
      Befund-Triage (v1.0-Blocker vs. 1.x-Backlog)
