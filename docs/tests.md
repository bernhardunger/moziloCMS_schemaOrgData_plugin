# Tests

Diese Seite beschreibt **welche Testarten es gibt**, **wie sie lokal
ausgeführt werden** und **warum ein eigenes Test-Bootstrap nötig ist**.
Für den allgemeinen lokalen Einstiegspunkt (`composer install`) und
Commit-Konventionen siehe [development.md](development.md).

## Testarten im Überblick

| Testart | Was wird geprüft | Ort |
|---|---|---|
| PHPUnit-Unit-/Komponententests | Serverseitige Logik: Scope-Auflösung, JSON-LD-Erzeugung, Formular-Rendering, Validierung, Persistenz — je `lib/`-Klasse eigene Testdatei | `tests/*.php` |
| PHPUnit-Feldvalidator-Tests | Je ein Test pro serverseitigem Feldvalidator (E-Mail, URL, Telefon, PLZ, Öffnungszeiten, Geo-Koordinaten) | `tests/Validation/` |
| Jest-Tests | Clientseitige Live-Validierung (`js/validator.js`) in simulierter DOM-Umgebung | `tests/js/` |
| Playwright-Regressionstests | Browsergestützte End-to-End-Verifikation gegen eine reale moziloCMS-Installation | intern dokumentiert |

Die ersten drei Testarten laufen automatisiert und ohne manuelles
Zutun; Playwright-Regressionstests sind anleitungsgestützte manuelle
bzw. per Playwright-MCP durchgeführte Browserläufe, kein Teil einer
CI-Pipeline.

## Testausführung

### PHPUnit

Voraussetzung: `composer install` (siehe [development.md](development.md)).
`phpunit.xml` definiert zwei Testsuites, die `./vendor/bin/phpunit` ohne
weitere Optionen zusammen ausführt:

```xml
<testsuite name="schemaOrgData">
  <directory>tests</directory>
  <exclude>tests/Validation/</exclude>
</testsuite>
<testsuite name="schemaOrgData-Validation">
  <directory>tests/Validation</directory>
</testsuite>
```

`tests/Validation/` (je ein Test pro Feldvalidator) ist von der
Hauptsuite ausgeschlossen und läuft als eigene Suite — organisatorische
Trennung, kein Unterschied im Aufruf.

### Jest (clientseitige Validierung)

`js/validator.js` wird über `tests/js/` mit Jest getestet:

```bash
cd tests/js
npm install
npm test
```

`tests/js/package.json` konfiguriert `testEnvironment: "jsdom"` (DOM-APIs
für Formular-/Event-Tests ohne echten Browser) und referenziert
`jest`/`jest-environment-jsdom` (`^29`) als Dev-Dependency.

## PHPUnit: Testorganisation

`tests/` liegt eine Ebene über `plugins/schemaOrgData/` (Repo-Root), nicht
darunter — analog zu `docs/`. Grober Zuschnitt:

- **Eine Testdatei je `lib/`-Klasse** (`ScopeResolverTest.php`,
  `JsonLdBuilderTest.php`, `FormRendererComponentTest.php`, …) — Direkt-Tests
  gegen die öffentlichen Methoden dieser Komponente, mit echten statt
  gemockten Kollaboratoren (siehe „Warum ein eigenes Test-Bootstrap nötig
  ist" unten).
- **Feature-/Type-übergreifende Tests**: `JsonLdOutputTest.php` prüft die
  **Gesamtausgabe** (Scope-Merge, Type-Kollision, Ausschlussliste) über
  mehrere Komponenten hinweg; `SchemaConsistencyTest.php` prüft
  **Konsistenz über alle `schemas/*.json` hinweg** — u. a., dass der
  `PostalAddress`-Definitionsblock strukturell identisch bleibt und
  `required[]` mit `ui:required: true` bidirektional übereinstimmt (relevant
  beim Anlegen eines neuen Schema-Types, siehe
  [schema-extending.md](schema-extending.md)).
- **Ein Type mit Sonderverhalten je eigener Testdatei**
  (`DonateActionTest.php`, `EventTest.php`, `PersonIdRefOrLiteralTest.php`)
  — deckt Schema-Struktur, Widget-Verhalten und den
  `saveConfig()`/`buildJsonLdScript()`-Roundtrip für die Besonderheiten
  dieses Types ab, statt generische Mechanismen erneut zu prüfen.
- **`tests/Validation/`** — ein Test je serverseitigem Feldvalidator.
- **`tests/Fixtures/`** — ausgelagerte Fixture-Klassen (kein eigener
  Autoloader-Eintrag; werden per `require_once` aus den nutzenden
  Testdateien eingebunden).

## Was die dokumentierten Skips bedeuten

Ein kleiner Teil der PHPUnit-Tests ist mit `markTestSkipped()` als
strukturell nicht erreichbar markiert (in `JsonLdOutputTest.php` und
`FrontendRendererTest.php`). Ursache ist dieselbe in beiden Fällen:
`tests/bootstrap.php` setzt `CAT_REQUEST`/`PAGE_REQUEST` fest auf `false`
(entspricht dem Zustand ohne aktive Kategorie/Seite), einzelne Testfälle
würden aber einen aktiven `CAT_REQUEST`/`PAGE_REQUEST` voraussetzen, um
den betreffenden Codepfad zu erreichen. Die genaue Anzahl wird hier bewusst
nicht genannt — sie veraltet bei jeder Testerweiterung (siehe README-eigene
Konvention dazu, [../README.md](../README.md#tests)). Diese Fälle sind
kein Hinweis auf eine Lücke in der Testabdeckung, sondern eine Grenze des
Mock-Bootstraps; die betroffenen Codepfade werden stattdessen durch
ergänzende Browser-Regressionstests gegen eine echte Installation mit
aktivem `CAT_REQUEST`/`PAGE_REQUEST` abgedeckt.

## Jest: clientseitige Validierung

`tests/js/` testet ausschließlich `js/validator.js` — die Logik, die im
Formular Live-Feedback erzeugt, bevor ein Request überhaupt abgeschickt
wird (siehe [validation.md](validation.md)). Die Testdateien sind nach
Widget bzw. Funktionsgruppe benannt: `validator-functions.test.js` für die
einzelnen Validierungsfunktionen, sowie je eine Datei für zusammengesetzte
Widget-Interaktionen (`address-required-widget`, `event-date-range-widget`,
`extension-field-wiring`, `geo-widget`, `import-autofill-button`,
`opening-hours-widget`). Diese Tests laufen unabhängig von PHPUnit und
prüfen ausschließlich Browser-seitiges Verhalten — die serverseitige
Gegenprüfung derselben Felder liegt in `tests/Validation/` bzw. den
`ValidatorTest`-Methoden.

## Warum ein eigenes Test-Bootstrap nötig ist

moziloCMS bringt kein eigenes Test-Framework mit, und der Core selbst ist
nicht Teil dieses Repositories. `tests/bootstrap.php` (in `phpunit.xml`
als `bootstrap` referenziert) stellt deshalb minimale Ersatzimplementierungen
der CMS-Abhängigkeiten bereit, damit `plugins/schemaOrgData/index.php` und
alle `lib/`-Klassen außerhalb einer echten Installation geladen und
getestet werden können:

- **Konstanten** — `IS_CMS`, `CHARSET`, `PLUGIN_DIR_NAME`, `URL_BASE`,
  `BASE_DIR`, `LAYOUT_DIR_NAME`, sowie `CAT_REQUEST`/`PAGE_REQUEST`, fest
  auf `false` gesetzt (entspricht dem Zustand ohne aktive Kategorie/Seite
  — Grund für die dokumentierten Test-Skips in `JsonLdOutputTest` und
  `FrontendRendererTest`, die einen aktiven `CAT_REQUEST`/`PAGE_REQUEST`
  strukturell voraussetzen würden).
- **`class Properties`** — vereinfachter Ersatz für die moziloCMS-Klasse
  gleichen Namens. Liest sowohl Sprachdateien (`schluessel = wert` je
  Zeile) als auch das `conf`-Dateiformat (`<?php die(); ?>` gefolgt von
  einem `serialize()`-Array) — deckt damit sowohl `sprachen/*.txt` als
  auch das reale `plugin.conf.php`-Format ab (siehe
  [configuration.md](configuration.md)).
- **`class InMemorySettings`** — Alternative zu `Properties` für Tests,
  die `saveConfig()`/`deleteConfig()` isoliert von einer echten
  `plugin.conf.php`-Datei ausführen wollen; implementiert dieselben vier
  Methoden (`get()`, `set()`, `keyExists()`, `delete()`), ohne
  gemeinsames Interface — `$settings`-Parameter in den `lib/`-Klassen
  sind deshalb bewusst ohne Type-Hint deklariert (siehe
  [architecture.md](architecture.md)).
- **`class Language`** — vereinfachter Ersatz mit `getLanguageValue()`
  (Platzhalterersetzung `{PARAM1}`/`{PARAM2}`) und `getLanguageHtml()`
  (zusätzlich HTML-entity-encodiert).
- **`class Plugin`** — Basisklasse, die `schemaOrgData extends Plugin`
  erwartet: setzt `PLUGIN_SELF_DIR`/`PLUGIN_SELF_URL` und instanziiert
  `$this->settings` aus der realen `plugin.conf.php`, falls vorhanden.
- **`class MockConf`** — einfacher Ersatz für `$CMS_CONF`/`$ADMIN_CONF`
  (nur `get(string $key)`).
- **`getRequestValue()`** — vereinfachter Ersatz der moziloCMS-Core-Funktion,
  deckt nur den im Plugin tatsächlich genutzten Aufrufstil ab (skalarer
  Key, kein Array-Pfad).

Tests, die den echten Objektgraphen mit realen Kollaboratoren statt
gemockten Rückgabewerten prüfen wollen (die überwiegende Mehrheit,
Bezeichnung „Direkt-Tests" für einzelne `lib/`-Klassen), instanziieren
`Language`, `SchemaOrgData_SchemaRepository` & Co. aus diesem Bootstrap
ganz normal — es handelt sich um vollständige, wenn auch vereinfachte
Implementierungen, nicht um Mock-Objekte mit vorprogrammierten
Rückgabewerten.

## Grundprinzip beim Erweitern

Ein neuer Schema-Type erfordert im Regelfall keine PHP-Änderung (siehe
[schema-extending.md](schema-extending.md) für Ausnahmen) — entsprechend sollte ein Test
für einen neuen Type in erster Linie das **Schema selbst** sowie den
`saveConfig()`/`buildJsonLdScript()`-Roundtrip für seine Besonderheiten
prüfen, nicht die generischen `lib/`-Mechanismen erneut, die bereits über
die vorhandenen Direkt-Tests abgedeckt sind.

## Siehe auch

- [../README.md](../README.md#tests) — Kurzübersicht
- [development.md](development.md) — lokales Setup (`composer install`), Commit-Konventionen
- [validation.md](validation.md) — welche Feldregeln client- und serverseitig geprüft werden
- [schema-extending.md](schema-extending.md) — `SchemaConsistencyTest`-Anforderungen an neue Schema-Dateien
