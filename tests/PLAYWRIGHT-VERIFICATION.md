# Playwright-Verifikation — schemaOrgData

> Stand: 2026-07-13 — Finale Komplett-Regression (Phasen 0–9)
> durchgeführt gegen `stb-hader`, `0.9.15-rc`: ~30 Einzelprüfungen, 1
> NOK (Phase 4e, reiner Doku-Drift seit `e81b8e1` — kein Produktivbug,
> Erwartung hier korrigiert). Phase-4a-Sonderfall (bekannter Doku-Gap,
> siehe `doc/TODO.md`) wie erwartet aufgetreten, nicht separat gewertet.
> Neue Erkenntnis zum Zusammenspiel Dangling-Reference-Guard/De-Dup-Guard
> bei geteiltem `ui:idFragment` (Phase 9b) — in `CLAUDE.md` unter
> „Dangling-Reference-Guard" ergänzt. Kein Code-Fix, kein Commit im
> Plugin-Repo, reine Verifikation.
>
> Stand: 2026-07-13 — Phase 4g an Import-UI-Stand 0.9.15-rc angepasst
> (Import-Ein-Klick `83fbb80`/0.9.13-rc, verschachtelter manueller
> Import-Pfad `30c6787`/0.9.14-rc): Punkt 2 um inneres `<details>`
> „JSON-LD manuell einfügen" ergänzt, Punkt 3 von Zwei-Klick- auf
> Ein-Klick-Erwartung korrigiert, neuer Gegenprobe-Punkt für den
> manuellen Pfad ergänzt. Reine Testplan-Korrektur, keine Code-Änderung.
>
> Stand: 2026-07-05 — Testplan-Erweiterung für Fahrplan-Schritt 5, gegen
> `0.4.57-beta` (Datumseingabe TT.MM.YYYY inkl. Redisplay vollständig
> abgeschlossen). Phase 9b/9c waren bereits im Rerun vom 2026-07-04
> korrigiert (siehe dortige „Korrektur"-Vermerke) — inhaltlich unverändert
> gültig, keine weitere Anpassung nötig. Neu hinzugekommen: 4f/4g
> (UX-Dreier aus Fahrplan-Schritt 3 — Keep-Konsequenz-Hinweis,
> scope-abhängiger Titel, Progressive Disclosure), 6b (Organization statt
> NGO als DonateAction-Empfänger, Fahrplan-Schritt 2), 7 Zusatzcheck 3
> ersetzt (deutsche Datumseingabe, Live-Validierung inkl.
> `endDate`-vor-`startDate`, Redisplay — vormalige Notiz „`js/validator.js`
> wurde NICHT angepasst" ist seit Fahrplan-Schritt 4b überholt).
>
> Stand: 2026-07-04 — aktualisiert auf `0.4.50-beta`. Phasen 0–4 unverändert
> gültig (additive Entwicklung seit `0.4.8-beta` hat an den geprüften
> Mechanismen nichts geändert). Phasen 5–9 decken die Schema-Types NGO
> (Global), DonateAction (Seite) und Event (Seite) inkl. `@id`-Mechanismus,
> `id_reference`/`id_reference_or_literal`/`place`-Widgets ab — UND
> schließen eine bisher bestehende Lücke: Phasen 0–4 prüfen ausschließlich
> die Admin-Oberfläche, nicht die tatsächliche Frontend-Ausgabe (den
> eigentlichen fachlichen Zweck des Plugins). Phasen 5–9 prüfen deshalb
> durchgehend zusätzlich den gerenderten Seitenquelltext, nicht nur das
> Admin-Formular.
>
> **Voraussetzung für einen aussagekräftigen Lauf jetzt erfüllt:** Der
> erste Lauf gegen `0.4.46-beta` ergab einen systemischen Befund (kein
> Plugin-Output im Frontend, bei keinem Scope) sowie zwei weitere Funde
> (DonateAction-Speicherbug, irreführende Admin-URI-Anzeige) — Details
> und Root-Cause-Analyse siehe `fix-liste-playwright-0.4.46.md`. Alle vier
> Punkte sind mittlerweile behoben und einzeln gegen den Code verifiziert:
> fehlender `{schemaOrgData}`-Platzhalter im Layout-Template (manuell
> ergänzt, zusätzlich neuer Admin-Hinweis bei künftig fehlendem
> Platzhalter, `0.4.48-beta`), DonateAction-Pflichtfeld-Bug
> (`0.4.47-beta`/`826811b`), Lese-/Schreibpfad-Symmetrie der
> Scope-Bezeichner (`0.4.49-beta`/`9679960`, als Härtung ohne bestätigten
> konkreten Ausfall), Admin-URI-Anzeige (`0.4.50-beta`/`1c0f4ef`). Phase 9c
> ist zusätzlich korrigiert (NGO → LocalBusiness, siehe dort).
>
> **Rerun durchgeführt (2026-07-04, gegen `0.4.50-beta`, `stb-hader`):**
> Phasen 0–9 vollständig durchlaufen, alle vier Fixes bestätigt,
> Kernpunkte durchgehend OK. Zwei neue, unabhängige Einzelbefunde dabei
> aufgetreten (siehe `doc/TODO.md`, Abschnitt „Offen"): ein reproduzierbarer
> Redisplay-Bug im `id_reference_or_literal`-Widget bei `Event.organizer`
> (Daten korrekt gespeichert/ausgegeben, nur die Radio-Auswahl beim
> Neuladen des Formulars fehlt), sowie eine Korrektur der Phase-9b-Erwartung
> selbst (siehe dort — war ein Fehler im Testplan, kein Code-Bug).

Automatisierter Browser-Regressionstest für scope-bezogene Funktionen,
die von PHPUnit strukturell nicht abgedeckt werden können (clientseitige
JS-Validierung, tatsächliches Rendering, echte Speichern-Roundtrips).
Ergänzt `SMOKE-TESTS.md` (manuell) um den automatisierbaren Teil.

Ausführung über Claude Code mit Playwright-MCP-Server
(`claude mcp add playwright npx @playwright/mcp@latest`), gegen die
lokale Laragon-Instanz. Entstanden aus der Verifikation von
0.4.2-beta/0.4.3-beta (Speichern-Button-Text bei Scope-Wechsel,
Pflichtfeld-Fehler bei geerbtem Wert) und um 0.4.7-beta/0.4.8-beta
erweitert (Template-JSON-LD-Detection im Admin-Kontext, ausschließlich
als Global-Signal). Um 0.4.46-beta erweitert (NGO/DonateAction/Event,
`@id`-Referenzauflösung, Frontend-Ausgabe) — als dauerhafter
Regressionsschutz für künftige Änderungen in diesem Bereich gedacht.

---

## Voraussetzungen

- ADMIN_URL / ADMIN_USER / ADMIN_PASSWORD **immer zu Beginn jedes Laufs
  abfragen**, unabhängig davon, ob sie aus einer früheren Session bereits
  bekannt scheinen — NICHT in dieses Dokument oder ins Repo eintragen
  (analog `conf/`-Konvention, siehe `.gitignore`), NICHT aus einer
  vorangegangenen Session heraus wiederverwenden ohne erneute Bestätigung.
- Playwright-MCP-Server in der aktuellen Claude-Code-Session verbunden
  (`claude mcp list` prüfen — neue Session starten falls nicht).
- Plugin-Ordner per Junction mit dem Dev-Repo verknüpft (kein manueller
  Sync-Schritt nötig; falls nicht eingerichtet, einmalig den
  tatsächlichen Deployment-Unterordner ermitteln und verlinken).
- **Reine Testinstanz, kein Produktivsystem:** Sämtliche in diesem
  Dokument beschriebenen Admin-Konfigurationsänderungen (Type-Auswahl,
  Ausschlussliste, Debug-Modus, `jsonld_mode`, Template-Dateien laut
  Phase 4) dürfen **ohne Rückfrage** vorgenommen werden — Voraussetzung
  ist ausschließlich, dass sie am Ende der jeweiligen Phase bzw. im
  abschließenden „Aufräumen"-Abschnitt zuverlässig zurückgesetzt werden.
  Das gilt für den gesamten Lauf, damit alle Phasen ohne Unterbrechung
  durchlaufen werden können. Ausgenommen bleibt die Abbruchregel unten:
  Rückfrage-Freiheit bezieht sich nur auf geplante, im Dokument
  vorgesehene Konfigurationsschritte — nicht auf eigenständige
  Code-Änderungen oder Abweichungen vom beschriebenen Ablauf.
- **Neu ab Phase 6:** TEST_CAT muss mindestens eine tatsächlich
  existierende Seite enthalten (im weiteren Text: PAGE_TEST) — anders als
  in Phasen 0–4 (dort optional) ist das jetzt zwingend, da DonateAction
  und Event ausschließlich im Geltungsbereich „Seite" wählbar sind. Fehlt
  eine Seite in TEST_CAT, vor Beginn über den CMS-Seiteneditor eine
  anlegen (nicht Teil dieses Tests, nur Voraussetzung).

---

## Abbruchregel

Weicht das beobachtete Verhalten von der hier beschriebenen Erwartung
ab: NICHT eigenständig in den PHP-/JS-Code eintauchen oder
Aufrufketten nachverfolgen. Stattdessen sofort stoppen, exakte
Fehlermeldung/Screenshot festhalten, den betroffenen Schritt im Report
als NOK markieren und mit dem nächsten Schritt fortfahren. Codeanalyse
und Fix erfolgen in einer separaten Folge-Session.

Klarstellung zur Rückfrage-Freiheit aus den Voraussetzungen: Sie deckt
ausschließlich die im Dokument selbst vorgesehenen, reversiblen
Konfigurationsschritte ab. Sie ändert nichts an dieser Abbruchregel —
ein NOK wird weiterhin dokumentiert und nicht eigenständig behoben,
auch wenn der Fix vermeintlich naheliegt.

---

## Phase 0 — Login & Navigation

1. ADMIN_URL öffnen, mit ADMIN_USER/ADMIN_PASSWORD einloggen.

   **UI-Besonderheit:** Der „Anmelden"-Button ist disabled, bis sowohl
   User- als auch Passwort-Feld einen Wert enthalten — erst dann wird er
   enabled. Beide Felder befüllen und den enabled-Zustand abwarten, bevor
   geklickt wird.

2. Zu Plugins → schemaOrgData navigieren: Button/Link
   "js-config-adminlogin" ist der Einsprungspunkt. Das iframe lädt
   anschließend unter einer eigenen, direkten URL — diese nach dem
   ersten Klick merken und in Folgeschritten/-läufen direkt anspringen.
3. Per Scope-Selektor eine bestehende Kategorie für die folgenden
   Tests merken (im weiteren Text: TEST_CAT) — nicht raten, aus der
   tatsächlich angezeigten Liste lesen. Zusätzlich eine Seite innerhalb
   TEST_CAT merken (PAGE_TEST, siehe „Voraussetzungen" — ab Phase 6
   zwingend erforderlich).

---

## Phase 0b — Reset (Altzustand ausschließen)

Es gibt keinen eigenständigen "Löschen"-Button als isoliert klickbares
Control. Eine Geltungsebene wird zurückgesetzt, indem im
Type-Dropdown "– kein Schema –" gewählt und gespeichert wird — das
entfernt die Type-spezifische Konfiguration dieser Ebene (_meta und
Ausschlussliste bleiben erhalten, das ist für den Reset unerheblich).
Für Global, TEST_CAT UND PAGE_TEST jeweils so zurücksetzen, bevor mit
Phase 1 begonnen wird — damit kein Altzustand aus früheren Läufen die
folgenden Annahmen unterläuft.

---

## Phase 0c — Prep: Aktuelle `ui:idFragment`-Registrierung (Dateisystem, nicht Browser)

Für Phase 9d (De-Dup-Guard) muss vorab bekannt sein, welche Schema-Types
aktuell überhaupt einen `@id`-Anker deklarieren und ob zwei davon
gleichzeitig auf einer Seite ausgegeben werden könnten (Voraussetzung für
einen Fragment-Konflikt). Claude Code direkt im Dateisystem:

```bash
grep -rn "ui:idFragment" plugins/schemaOrgData/schemas/*.json
```

Ergebnis (Type + Fragment-Wert + `ui:scopes` aus derselben Datei) notieren
— wird in Phase 9d ausgewertet.

---

## Phase 1 — Testdaten auf Globalebene anlegen

Geltungsbereich Global, Type "LocalBusiness" wählen:
  - name = "Playwright Test GmbH"
  - url  = "https://www.playwright-test.de"
  - Adresse NICHT ausfüllen (bewusst leer lassen — wird in Phase 3b
    als Negativtest benötigt)
Speichern. Erfolgsmeldung muss erscheinen, keine Pflichtfeld-Fehler
(name/url sind beide gefüllt, Adresse ist als Ganzes nicht required
und komplett unberührt).

---

## Phase 2 — Speichern-Button-Label bei Scope-Wechsel

Funktion: `buildSaveButtonLabel()` / `activateSection()` (validator.js)

1. Im Zustand "Global": Text beider Speichern-Buttons (oben + unten)
   auslesen → GLOBAL_LABEL.
2. Scope wechseln zu TEST_CAT: Text beider Buttons erneut auslesen →
   muss sich von GLOBAL_LABEL unterscheiden und den Namen von
   TEST_CAT enthalten. Beide Buttons synchron.
3. Hat TEST_CAT mindestens eine Seite: zusätzlich zu dieser Seite
   wechseln → Button-Text muss sich erneut ändern, Seitenname
   enthalten.
4. Zurück zu "Global" wechseln → Button-Text muss wieder exakt
   GLOBAL_LABEL entsprechen.

---

## Phase 3 — Pflichtfeld-Fehler bei geerbtem Wert

Funktion: `validateFormData()` / `validatePostalAddressData()` (Server),
`runFieldValidation()` (Client)

Scope = TEST_CAT, Type "LocalBusiness" im Dropdown wählen (nicht
speichern, nur Auswahl).

**3a) Positiv — Top-Level-Felder:**
  - "Name" leer, Placeholder "Playwright Test GmbH" + ü-Badge; Blur →
    kein Pflichtfeld-Fehler.
  - "URL" leer, Placeholder "https://www.playwright-test.de" + Blur →
    kein Fehler. Speichern mit beiden Feldern leer → Erfolgsmeldung.

**3b) Negativ — Adresse ohne Vererbung (Regressionsschutz):**
  - "Straße" füllen (z. B. "Teststraße 1") — Adresse zählt dadurch als
    ausgefüllt, ihre Pflichtfelder greifen.
  - "Ort" leer lassen, Blur → Pflichtfeld-Fehler MUSS erscheinen (kein
    geerbter Wert vorhanden). Speichern → MUSS fehlschlagen, Fehler zu
    "Ort".

**3c) Positiv — Adresse MIT Vererbung:**
  - Zu Global wechseln, zusätzlich address.addressLocality = "München"
    eintragen, speichern.
  - Zu TEST_CAT zurück, "Straße" erneut füllen, "Ort" leer lassen →
    Placeholder "München" + ü-Badge sichtbar, Blur → kein Fehler.
    Speichern → Erfolgsmeldung.

---

## Phase 4 — Template-JSON-LD-Kollisionserkennung (Variante A: Global-Signal, ab 0.4.8-beta)

Funktion: `detectExistingJsonLdInTemplateAdmin()` (Admin-Live-Detection),
`renderInfoBlock()` (Info-Hinweis), `renderExistingJsonLdNotice()`
(Hinweis-Ausgabe).

Festlegung Variante A: Ein im Layout-Template eingebundener JSON-LD-Block
ist layoutweit und wird ausschließlich dem **Global**-Scope zugeordnet.
Kategorie-/Seiten-Tabs zeigen für einen reinen Template-Block KEINEN
Hinweis.

Prep (Dateisystem, NICHT Browser — Claude Code direkt): aktives Layout aus
der Template-Verwaltung ablesen (ACTIVE_LAYOUT = CMS-Konfig `cmslayout`,
nicht raten). Den Block
`<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite"}</script>`
in `layouts/ACTIVE_LAYOUT/template.html` im `<head>` einfügen. Laragon-Sync
bestätigen (Junction → kein manueller Sync; sonst Sync-Stand prüfen, bevor
Firefox/Chrome-Unterschiede als Bug gewertet werden).

**4a) Positiv — Global-Tab (Hinweis + Info-Text):**
  - Plugin-Admin, Global-Scope öffnen. Hinweisblock
    (".schemaOrgData-jsonld-notice") MUSS sichtbar sein, mit beiden
    Radio-Buttons (keep/override) und Import-Textarea.
  - Im Info-Block (".schemaOrgData-info") des Global-Scopes MUSS der
    Template-Hinweistext (info_text_template_global) stehen — erkennbar
    am Inhalt "ausschließlich hier im Geltungsbereich „Global"".

**4b) Global-only — Kategorie/Seite ohne Hinweis (Kern von Variante A):**
  - Per Scope-Selektor zu TEST_CAT wechseln → Hinweisblock MUSS fehlen,
    obwohl der Template-Block weiterhin vorhanden ist.
  - Hat TEST_CAT eine Seite: zusätzlich dorthin wechseln → ebenfalls
    KEIN Hinweisblock.

**4c) Persistenz der Auswahl (Global):**
  - Zurück zu Global. "Mit Plugin-Konfiguration überschreiben" wählen,
    speichern. iframe neu laden → Auswahl "override" MUSS erhalten
    bleiben (Schreibvorgang im IS_ADMIN-Kontext, in dem
    `Properties::set()` auf die Platte schreibt).

**4d) Negativ — inaktives Template (False-Positive-Schutz):**
  - Block aus layouts/ACTIVE_LAYOUT/template.html entfernen, stattdessen
    in ein nachweislich INAKTIVES Layout
    (layouts/OTHER_LAYOUT/template.html) einfügen.
  - Global-Scope erneut öffnen → Hinweisblock MUSS fehlen (inaktive
    Layouts werden bewusst nicht geprüft).

**4e) Gallery-Template (korrigiert seit `e81b8e1`, Admin-Template-
Scanning-Fix — Negativtest, analog zu 4d):**
  - Block in `layouts/ACTIVE_LAYOUT/gallerytemplate.html` einfügen
    (`template.html` ohne Block) → im Global-Scope MUSS **kein**
    Hinweisblock erscheinen. `gallerytemplate.html` rendert
    strukturell nie echten Seiteninhalt und wird von der
    Admin-Kontext-Erkennung bewusst nicht gescannt (siehe `CLAUDE.md`/
    README.md, Abschnitt „JSON-LD-Ausgabe").

**4f) UX-Dreier, Teil 1 — Keep-Konsequenz-Hinweis und scope-abhängiger Titel (Fahrplan-Schritt 3)**

Block wie in 4a in `layouts/ACTIVE_LAYOUT/template.html` wieder aktiv
(bzw. aus 4e/4d wiederherstellen), Global-Scope geöffnet.

1. Direkt unter der Keep/Override-Radio-Gruppe MUSS ein erklärender
   Hinweistext stehen, der darauf hinweist, dass „Vorhandenes
   beibehalten" die Plugin-Ausgabe für diesen Geltungsbereich komplett
   unterdrückt (Sprachschlüssel `notice_keep_consequence_hint`).
2. Der Titel des Hinweisblocks MUSS im Global-Scope „Im Layout-Template
   wurde bereits ein JSON-LD-Block gefunden." lauten (Sprachschlüssel
   `notice_existing_jsonld_title_global`) — NICHT die generische
   Formulierung „Auf dieser Seite…" aus `notice_existing_jsonld_title`.
   **Hinweis:** Der generische Titel für Kategorie-/Seiten-Scope ist mit
   den aktuellen Testphasen nicht separat verifizierbar, da Variante A
   (4b) für Kategorie/Seite ohnehin keinen Hinweisblock zu einem
   Template-Block anzeigt — ein eigener Beleg-Block müsste dafür direkt
   im Seiteninhalt eingefügt werden, was mit dem offenen,
   niedrigpriorisierten Punkt „Bug (Re-Test, eingeengt): JSON-LD-Erkennung
   — Kategorie-/Seiten-Scope und gerenderter Seiteninhalt ungetestet"
   (`doc/TODO.md`) zusammenfällt. Kein eigener Prüfschritt hier, nur
   Vermerk der Abgrenzung.

**4g) UX-Dreier, Teil 2 — Progressive Disclosure (Fahrplan-Schritt 3)**

Gleicher Ausgangszustand wie 4f.

1. Der Info-Block (`.schemaOrgData-info`) MUSS den scope-spezifischen
   Absatz (hier: `info_text_global`) direkt und ohne weiteren Klick
   sichtbar zeigen. Der allgemeine Absatz (`info_text_general`,
   Validator-Link) sowie der Template-Hinweis
   (`info_text_template_global`, siehe 4a) MÜSSEN hinter einem
   `<details>`-Element mit `<summary>` (`label_info_more_details`,
   „Mehr Details") liegen — initial eingeklappt. Aufklappen bestätigen.
2. Der Import-Bereich („Vorhandenes JSON-LD importieren") MUSS
   ebenfalls in einem `<details>`-Element liegen. Da der Testblock aus
   4a erkannten Inhalt liefert (`existing_jsonld_content` nicht leer),
   MUSS dieses äußere `<details>` initial **aufgeklappt** sein
   (Autofill-Button sofort sichtbar, kein zusätzlicher Klick nötig).
   Innerhalb dieses äußeren Import-Bereichs MUSS zusätzlich ein eigenes,
   **verschachteltes** `<details>`-Element mit `<summary>`
   „JSON-LD manuell einfügen" (`label_import_manual`) liegen, das Label,
   Textarea und den „Importieren"-Button des manuellen Import-Pfads
   enthält. Im hier vorliegenden Einzelblock-Erkennungsfall (Autofill-Button
   wird angezeigt) MUSS dieses innere `<details>` initial **geschlossen**
   bleiben — der Ein-Klick-Autofill deckt den Happy Path ab, ohne dass es
   geöffnet werden muss.
3. **Autofill-Ein-Klick:** Button „Erkannten Block importieren" (aktueller
   Button-Text, `button_use_detected_jsonld`) anklicken → MUSS unmittelbar
   den bestehenden Import-Submit auslösen, ohne dass zusätzlich manuell auf
   „Importieren" geklickt wird: Formularfelder werden aus dem importierten
   JSON-LD befüllt, eine Import-Erfolgsmeldung (`notice_import_success`)
   erscheint. Regressionsschutz: Die `<details>`-Umstrukturierung
   (verschachteltes inneres `<details>` aus Punkt 2) darf diesen
   Ein-Klick-Ablauf (`initAutofillButton()` befüllt die Textarea im inneren
   `<details>` per DOM-Property-Zuweisung und klickt anschließend den dort
   liegenden Submit-Button programmatisch) nicht beeinträchtigen.
4. **Gegenprobe — manueller Pfad unabhängig vom Ein-Klick-Autofill:**
   Inneres `<details>` „JSON-LD manuell einfügen" von Hand aufklappen,
   Textarea-Inhalt von Hand ändern (z. B. abweichender/korrigierter
   JSON-LD-Block), über den Button „Importieren" abschicken → MUSS
   weiterhin unabhängig vom Autofill-Ein-Klick-Pfad funktionieren
   (Formularfelder werden aus dem manuell eingefügten Inhalt befüllt,
   Erfolgsmeldung erscheint). Deckt den Mehrblock-/Korrekturfall ab, für
   den der Autofill-Button bewusst unterdrückt bzw. das innere `<details>`
   automatisch geöffnet wird (siehe Kollisionserkennung).
5. **Visuelle Warnhervorhebung (informativ, kein OK/NOK):** Der
   Hinweisblock (`.schemaOrgData-jsonld-notice`) sollte sich farblich/optisch
   vom übrigen Formular abheben (CSS-Regel seit Fahrplan-Schritt 3). Rein
   visuelle Einschätzung, im Report als Ist-Zustand vermerken, nicht
   werten.

---

## Phase 5 — NGO (Global): `@id`-Anker und `nonprofitStatus` im Frontend

Funktion: `resolveNodeId()`/`buildJsonLdScript()` (`@id`-Vergabe via
`ui:idFragment`), `schemas/NGO.json` (`nonprofitStatus`-Enum)

1. Admin, Global-Scope, Type "NGO" wählen. Pflichtfelder aus dem
   tatsächlich angezeigten Formular ausfüllen (nicht raten), im
   `nonprofitStatus`-Dropdown einen Wert wählen und notieren
   (NGO_STATUS). Speichern → Erfolgsmeldung.
2. Eine Seite OHNE eigene Kategorie-/Seiten-Konfiguration im Frontend
   öffnen (damit ausschließlich der Global-Block greift), Seitenquelltext
   einsehen (Ansicht-Quelltext, nicht nur DevTools-Inspector — der
   `<script>`-Block liegt im initial ausgelieferten HTML).
3. Genau EIN `<script type="application/ld+json">`-Block MUSS vorhanden
   sein mit `"@type": "NGO"`, `"nonprofitStatus"` = NGO_STATUS, UND einer
   `"@id"`-Property, deren Wert auf `#organization` endet.

---

## Phase 6 — DonateAction (Seite): `id_reference`-Auflösung und Dangling-Guard im Frontend

Funktion: `id_reference`-Widget (`renderField()`), Build-Zeit-Emitter in
`buildJsonLdScript()`, `applyDanglingReferenceGuard()`

1. Admin, Scope = PAGE_TEST (Seite in TEST_CAT), Type "DonateAction"
   wählen. `description` ausfüllen. Das `recipient`-Feld zeigt eine
   schreibgeschützte Info-Anzeige mit der aufgelösten Ziel-URI (kein
   Eingabefeld) — Inhalt notieren, MUSS auf `#organization` enden.
   Speichern → Erfolgsmeldung.
2. PAGE_TEST im Frontend öffnen, Quelltext prüfen: ZWEI
   `<script>`-Blöcke MÜSSEN sichtbar sein — einer `@type: "NGO"` mit
   `@id` auf `#organization` (aus Phase 5), einer `@type: "DonateAction"`
   mit `"recipient": {"@id": "<URI>"}`. Beide URIs MÜSSEN identisch sein.
3. **Negativtest (Dangling-Reference-Guard, Stub-Fall):** Admin,
   Global-Scope, TEST_CAT zur Ausschlussliste (`excluded_cats`)
   hinzufügen, speichern. PAGE_TEST im Frontend neu laden:
   - NGO-Block MUSS weiterhin erscheinen, aber nur noch als **minimaler
     Stub** — ausschließlich `@type`, `@id` und `name`, KEINE weiteren
     Properties (kein `url`, kein `nonprofitStatus`).
   - DonateAction-Block MUSS unverändert vorhanden sein, `recipient.@id`
     MUSS weiterhin auf dieselbe URI wie der Stub verweisen.
4. Aufräumen: `excluded_cats`-Eintrag für TEST_CAT wieder entfernen,
   speichern.

**6b) Organization statt NGO als globale Identität (Fahrplan-Schritt 2)**

Prüft, dass `DonateAction.recipient` nicht nur mit NGO, sondern auch mit
`Organization` als globaler Identität auflöst (`ui:idFragment:
"organization"` seit Fahrplan-Schritt 2 auch in `Organization.json`
deklariert).

1. Admin, Global-Scope: Type von „NGO" auf „Organization" umstellen,
   `name`/`url` ausfüllen (z. B. „Playwright Test Org GmbH"/gleiche URL
   wie NGO), speichern. Das ersetzt die NGO-Konfiguration aus Phase 5
   vorübergehend.
2. PAGE_TEST im Frontend neu laden: Der `DonateAction`-Block MUSS
   weiterhin `"recipient": {"@id": "<URI>"}` zeigen, jetzt aber neben
   einem `@type: "Organization"`-Block (statt `NGO`) mit identischer
   `@id`-URI auf `#organization`.
3. Aufräumen: Global-Scope zurück auf Type „NGO" mit den Werten aus
   Phase 5 (inkl. `nonprofitStatus` = NGO_STATUS) umstellen, speichern —
   Phase 7/8/9 bauen auf NGO als globaler Identität auf.

---

## Phase 7 — Event (Seite): `place`-Widget und `id_reference_or_literal` im Frontend

Funktion: `renderPlaceWidget()`, `id_reference_or_literal`-Widget
(Referenz-Modus), `buildJsonLdScript()` (`location` → `Place` →
`PostalAddress`-Verschachtelung)

1. Admin, Scope = PAGE_TEST, Type "Event" wählen (ersetzt die
   DonateAction-Konfiguration dieser Seite aus Phase 6 — pro
   Geltungsebene ist nur ein Type gleichzeitig wählbar, das ist
   erwartetes Verhalten, kein Fehler). Pflichtfelder ausfüllen: `name`,
   `startDate` (ISO-8601, z. B. `2026-10-01T18:00:00+02:00`).
   `location.name` und `location.address` (mindestens
   `addressLocality` + `addressCountry`) ausfüllen. `organizer` im
   Referenz-Modus auf das `#organization`-Fragment setzen (Dropdown —
   NGO muss dafür aktiv sein, ggf. `excluded_cats`-Eintrag aus Phase 6
   vorher entfernt haben). Speichern → Erfolgsmeldung.
2. PAGE_TEST im Frontend neu laden, Quelltext prüfen: `<script>`-Block
   mit `"@type": "Event"`, darin `"location": {"@type": "Place", ...
   "address": {"@type": "PostalAddress", ...}}`, sowie
   `"organizer": {"@id": "<URI>"}` — dieselbe URI wie der NGO-Block.
3. **Deutsche Datumseingabe Ende-zu-Ende (Fahrplan-Schritt 4a/4b/Redisplay,
   ersetzt vormaligen Zusatzcheck „js/validator.js NICHT angepasst" —
   das ist seit Fahrplan-Schritt 4b überholt):**
   - `startDate` löschen und im deutschen Format neu eintragen (z. B.
     `01.10.2026 18:00`), Feld verlassen (Blur) → Live-Feedback MUSS
     „gültig"/kein Fehler zeigen (grünes Icon), KEIN Fehlerhinweis zu
     einem angeblich falschen Format.
   - `endDate` VOR `startDate` eintragen (z. B. `endDate` =
     `01.10.2026 17:00`, `startDate` bleibt `01.10.2026 18:00`), Feld
     verlassen → Live-Fehlerhinweis „Das Ende muss nach dem Beginn
     liegen." MUSS auf `endDate` erscheinen, OHNE dass zuvor
     „Speichern" geklickt wurde (clientseitige Prüfung, nicht erst
     serverseitig).
   - `startDate` korrigieren (z. B. auf `01.10.2026 16:00`, vor
     `endDate`), Feld verlassen → der zuvor auf `endDate` angezeigte
     Bereichsfehler MUSS sofort verschwinden, OHNE dass `endDate` selbst
     erneut angefasst/verlassen wird (bidirektionale Live-Prüfung).
   - Beide Felder auf einen gültigen, konsistenten Zustand zurücksetzen
     (`startDate` vor `endDate`, deutsches Format), speichern →
     Erfolgsmeldung.
   - PAGE_TEST im Frontend neu laden, Quelltext prüfen: `startDate`/
     `endDate` im JSON-LD MÜSSEN als ISO-8601 vorliegen (nicht als
     `TT.MM.YYYY`), unabhängig vom deutschen Eingabeformat.
   - **Redisplay:** Admin-Formular für PAGE_TEST neu laden (iframe
     reload) → `startDate`/`endDate` MÜSSEN im Formular wieder als
     `TT.MM.YYYY HH:MM` angezeigt werden (nicht als ISO), obwohl
     intern ISO gespeichert ist.

---

## Phase 8 — Mehrblock auf einer Seite (Global + Kategorie + Seite gleichzeitig)

Funktion: Rendering-Schleife über alle aktiven Geltungsebenen
(`getContent()`/`renderFrontend()`)

1. Sicherstellen, dass alle drei Ebenen gleichzeitig mit
   UNTERSCHIEDLICHEN Types konfiguriert sind: Global = NGO (aus Phase 5,
   `excluded_cats` zurückgesetzt), TEST_CAT (Kategorie) = LocalBusiness
   (aus Phase 1 — falls durch Phase 0b zurückgesetzt, erneut anlegen),
   PAGE_TEST (Seite) = Event (aus Phase 7).
2. PAGE_TEST im Frontend öffnen, Quelltext prüfen: GENAU DREI
   `<script type="application/ld+json">`-Blöcke MÜSSEN vorhanden sein —
   `@type: "NGO"` (Global), `@type: "LocalBusiness"` (Kategorie),
   `@type: "Event"` (Seite). Keine Unterdrückung durch Type-Kollision
   (die greift nur bei identischem Type auf mehreren Ebenen, hier sind
   alle drei Types unterschiedlich).

---

## Phase 9 — Debug-Ausgabe, Ausschlussliste, Type-Kollision und De-Dup-Guard im Frontend

**9a) Debug-Ausgabe** (Funktion: `buildDebugWidget()`, `debug_output`)

1. Admin, Global-Scope, Debug-Checkbox aktivieren, speichern.
2. PAGE_TEST im Frontend öffnen → zusätzlich zu den drei JSON-LD-Blöcken
   aus Phase 8 MUSS ein sichtbarer Debug-Bereich erscheinen: Trigger mit
   korrekter Plural-Form (3 Blöcke), pro Block ein eigenes `<pre>`-Element
   mit dem decodierten JSON-Inhalt, sowie ein funktionierender Link zum
   Schema.org-Validator.
3. Aufräumen: Debug-Checkbox wieder deaktivieren, speichern.

**9b) Ausschlussliste (`excluded_cats`) am Frontend**

**Korrektur (2026-07-04, nach Playwright-Rerun):** Die ursprüngliche
Erwartung in Schritt 2 war falsch formuliert — `excluded_cats`
unterdrückt laut `CLAUDE.md` ausschließlich die **globale** Konfiguration
auf der betroffenen Kategorie ("Auf den hier angehakten Kategorien wird
die globale Konfiguration nicht ausgegeben"), nicht die **eigene**
Konfiguration dieser Kategorie. Der `LocalBusiness`-Block der Kategorie
selbst bleibt daher unverändert bestehen — das ist beabsichtigtes
Verhalten (Anwendungsfall: Impressum/Datenschutz/Sitemap sollen nicht
die globale Geschäftsidentität erben, ohne dass deren eigene Konfiguration
betroffen wäre), kein Bug.

1. Admin, Global-Scope, TEST_CAT zur Ausschlussliste hinzufügen,
   speichern.
2. PAGE_TEST im Frontend neu laden. Da `Event.organizer` weiterhin per
   Referenz auf `#organization` zeigt, greift wieder der
   Dangling-Reference-Guard aus Phase 6 (Stub-Fall) — NICHT der
   vollständige Wegfall. Erwartung: NGO-Block erscheint als minimaler
   Stub (wie Phase 6), **LocalBusiness-Block (Kategorie) MUSS
   unverändert bestehen bleiben** (eigene Konfiguration der Kategorie,
   von `excluded_cats` nicht betroffen), Event-Block MUSS unverändert
   vorhanden sein. Weicht das beobachtete Verhalten hiervon ab, exakt
   dokumentieren statt zu werten.
3. Aufräumen: `excluded_cats`-Eintrag wieder entfernen, speichern.

**9c) Type-Kollision am Frontend**

Funktion: `detectTypeCollision()`/`resolveTypeInheritance()`
(`SchemaOrgData_ScopeResolver`).

**Korrektur (2026-07-04):** Der ursprüngliche Entwurf sah hier NGO auf
beiden Ebenen vor — NGO ist aber `ui:scopes: ["global"]` und auf
Kategorie-Ebene im Type-Dropdown gar nicht wählbar (siehe
`schemas/NGO.json`). Der Playwright-Befund dazu war korrekt (Phase 9c
war "nicht durchführbar" markiert). Test stattdessen mit
**LocalBusiness** (global + category wählbar):

1. Admin, Global-Scope: Type auf "LocalBusiness" umstellen (Werte aus
   Phase 1 — "Playwright Test GmbH"/"https://www.playwright-test.de" —
   erneut setzen, falls die Ebene inzwischen auf NGO stand), speichern.
   Das ersetzt vorübergehend die NGO-Konfiguration aus Phase 5/8 — wird
   am Ende dieser Phase nicht zwingend wiederhergestellt, da 9c die
   letzte Konfigurationsänderung vor dem allgemeinen Aufräumen ist.
2. TEST_CAT (Kategorie-Scope) auf Type "LocalBusiness" mit einem vom
   Global-Wert abweichenden `name` setzen (z. B. "Playwright Test
   Kategorie GmbH"), speichern. Admin-Hinweis auf Kollision mit der
   Global-Ebene MUSS erscheinen (`notice_type_collision`).
3. PAGE_TEST im Frontend neu laden → NUR EIN LocalBusiness-Block MUSS
   erscheinen (von der spezifischeren Kategorie-Ebene; leere Felder
   dieser Ebene feldweise von Global geerbt), nicht zwei.
4. Aufräumen dieser Teilphase: siehe allgemeines Aufräumen am Ende
   (Global/TEST_CAT werden dort ohnehin auf "– kein Schema –"
   zurückgesetzt) — keine gesonderte Wiederherstellung von NGO nötig,
   da 9c nach Phase 8/9a/9b die letzte inhaltliche Prüfung ist.

**9d) De-Dup-Guard (`@id`, bedingt)**

Nur durchführen, falls das Prep-Ergebnis aus Phase 0c zwei Types mit
identischem `ui:idFragment`-Wert enthält, die gleichzeitig auf einer
Seite ausgegeben werden könnten (z. B. beide im Geltungsbereich Global
wählbar wären — aktuell vermutlich nicht der Fall, da NGO und Person
beide `ui:scopes: ["global"]` sind und pro Ebene nur ein Type gleichzeitig
wählbar ist). Ist kein solches Szenario über die Admin-UI konstruierbar,
im Report vermerken: „De-Dup-Guard aktuell nicht über die Admin-UI
prüfbar" statt eine künstliche Prüfung zu erzwingen.

---

## Aufräumen

TEST_CAT und PAGE_TEST über Type-Dropdown "– kein Schema –" + Speichern
zurücksetzen (siehe Phase 0b — kein separater Löschen-Button). Globale
Testdaten (Phase 1/5) nach Ermessen stehen lassen oder ebenso
zurücksetzen. `excluded_cats` und Debug-Checkbox auf Ausgangszustand
prüfen (siehe Aufräumen-Hinweise in Phasen 6/9a/9b) — falls ein Lauf
vorzeitig abgebrochen wurde, diese beiden Einstellungen gezielt
gegenkontrollieren, da sie sonst stillschweigend in Folgeläufen bestehen
bleiben.

Phase 4: alle in Prep/Tests eingefügten
`<script type="application/ld+json">`-Blöcke aus sämtlichen berührten
template.html/gallerytemplate.html (aktives UND inaktives Layout) wieder
entfernen. jsonld_mode der Global-Ebene nach Ermessen auf "keep"
zurücksetzen, damit Folgeläufe nicht von einem persistierten "override"
ausgehen.

---

## Report

Tabellarisch OK/NOK je Teilschritt:
- Phase 2: 4 Punkte
- Phase 3: 3a/3b/3c je 2 Punkte = 6 Punkte
- Phase 4: 4a (zwei Prüfungen: Hinweis + Info-Text), 4b/4c/4d/4e = 6 Punkte,
  4f (zwei Prüfungen: Keep-Hinweis + scope-abhängiger Titel), 4g (fünf
  Prüfungen: Details-Struktur Info-Block, Import-Bereich-Struktur inkl.
  verschachteltem inneren „JSON-LD manuell einfügen"-`<details>`,
  Autofill-Ein-Klick, Gegenprobe manueller Pfad, plus 1 informativer
  Vermerk ohne OK/NOK für die visuelle Warnhervorhebung)
- Phase 5: 1 Punkt (NGO-Frontend-Ausgabe inkl. `@id` + `nonprofitStatus`)
- Phase 6: 2 Punkte (Referenz-Auflösung, Dangling-Guard-Stub-Fall), 6b:
  1 Punkt (Organization statt NGO als Empfänger)
- Phase 7: 1 Punkt (Event-Verschachtelung), Zusatzcheck 3 jetzt 6 Punkte
  (deutsche Live-Validierung, bidirektionale Bereichsprüfung, ISO-Speicherung,
  Redisplay)
- Phase 8: 1 Punkt (Drei-Block-Ausgabe ohne Fehlkollision)
- Phase 9: 9a (1), 9b (1), 9c (1), 9d (0 oder 1, bedingt — siehe oben)

Bei jedem NOK: Screenshot, Beschreibung der Abweichung, vermuteter
betroffener Funktionsname. Keine Code-Änderungen, kein Commit — reine
Verifikation.
