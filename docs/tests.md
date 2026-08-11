# Tests

Diese Seite beschreibt das lokale Setup, **welche Testarten es gibt**,
**wie sie ausgeführt werden** und **warum ein eigenes Test-Bootstrap nötig
ist**.

## Lokales Setup

```bash
composer install
./vendor/bin/phpunit
```

`composer.json` (Repo-Root) deklariert nur eine Dev-Abhängigkeit,
`phpunit/phpunit: ^11.0`, sowie ein PSR-4-Autoload für die Testklassen
(`SchemaOrgData\Tests\` → `tests/`). `vendor/` ist gitignored — PHPUnit
wird nicht ins Repository eingecheckt, `composer install` ist also vor
dem ersten Testlauf notwendig.

## Commit-Konventionen

Commit-Messages sind auf Deutsch verfasst, im Imperativ oder als knappe
Beschreibung der Änderung („Dokumentation: README.md nach
Strukturvorgabe umgebaut", „docs/ an den Repo-Root verschoben"). Inline-
Dokumentation im Code (PHPDoc, Kommentare) ist ebenfalls durchgehend auf
Deutsch gehalten.

## Testarten im Überblick

| Testart | Was wird geprüft | Ort |
|---|---|---|
| PHPUnit-Unit-/Komponententests | Serverseitige Logik: Scope-Auflösung, JSON-LD-Erzeugung, Formular-Rendering, Validierung, Persistenz — je `lib/`-Klasse eigene Testdatei | `tests/*.php` |
| PHPUnit-Feldvalidator-Tests | Je ein Test pro serverseitigem Feldvalidator (E-Mail, URL, Telefon, PLZ, Öffnungszeiten, Geo-Koordinaten) | `tests/Validation/` |
| Jest-Tests | Clientseitiges Verhalten: Live-Validierung (`js/validator.js`) und Debug-Widget (`js/debug-widget.js`) in simulierter DOM-Umgebung | `tests/js/` |
| Playwright-Regressionstests | Browsergestützte End-to-End-Verifikation gegen eine reale moziloCMS-Installation | intern dokumentiert |

Die ersten drei Testarten laufen automatisiert und ohne manuelles
Zutun; Playwright-Regressionstests sind anleitungsgestützte manuelle
bzw. per Playwright-MCP durchgeführte Browserläufe, kein Teil einer
CI-Pipeline.

## Testausführung

### PHPUnit

`phpunit.xml` definiert zwei Testsuites — die Hauptsuite und
`tests/Validation/` (organisatorische Trennung, kein Unterschied im
Aufruf) —, die `./vendor/bin/phpunit` ohne weitere Optionen zusammen
ausführt.

### Jest (clientseitige Validierung)

`js/validator.js` und `js/debug-widget.js` werden über `tests/js/` mit Jest
getestet:

```bash
cd tests/js
npm install
npm test
```

## PHPUnit: Testorganisation

`tests/` liegt eine Ebene über `plugins/schemaOrgData/` (Repo-Root), nicht
darunter — analog zu `docs/`. Grober Zuschnitt:

- **Eine Testdatei je `lib/`-Klasse** — Direkt-Tests gegen die
  öffentlichen Methoden dieser Komponente, mit echten statt gemockten
  Kollaboratoren (siehe „Warum ein eigenes Test-Bootstrap nötig ist"
  unten).
- **Feature-/Type-übergreifende Tests**: eine Testdatei prüft die
  **Gesamtausgabe** (Scope-Merge, Type-Kollision, Ausschlussliste) über
  mehrere Komponenten hinweg; eine weitere prüft **Konsistenz über alle
  `schemas/*.json` hinweg** — u. a., dass der `PostalAddress`-
  Definitionsblock strukturell identisch bleibt und `required[]` mit
  `ui:required: true` bidirektional übereinstimmt (relevant beim Anlegen
  eines neuen Schema-Types, siehe [schema-extending.md](schema-extending.md)).
- **Ein Type mit Sonderverhalten je eigener Testdatei** — deckt
  Schema-Struktur, Widget-Verhalten und den
  `saveConfig()`/`buildJsonLdScript()`-Roundtrip für die Besonderheiten
  dieses Types ab, statt generische Mechanismen erneut zu prüfen.
- **`tests/Validation/`** — ein Test je serverseitigem Feldvalidator.
- **`tests/Fixtures/`** — ausgelagerte Fixture-Klassen (kein eigener
  Autoloader-Eintrag; werden per `require_once` aus den nutzenden
  Testdateien eingebunden).

## Was die dokumentierten Skips bedeuten

Ein kleiner Teil der PHPUnit-Tests ist mit `markTestSkipped()` als
strukturell nicht erreichbar markiert (in `JsonLdOutputTest.php` und
`FrontendRendererTest.php`, mit Begründung direkt im jeweiligen
Docblock). Ursache ist in beiden Fällen dieselbe Grenze des
Mock-Bootstraps: `tests/bootstrap.php` setzt `CAT_REQUEST`/`PAGE_REQUEST`
fest auf `false`, einzelne Testfälle würden aber eine aktive
Kategorie/Seite voraussetzen. Die betroffenen Codepfade sind stattdessen
durch ergänzende Browser-Regressionstests gegen eine echte Installation
abgedeckt.

## Jest: clientseitige Validierung

`tests/js/` testet `js/validator.js` — die Logik, die im Formular
Live-Feedback erzeugt, bevor ein Request überhaupt abgeschickt wird (siehe
[validation.md](validation.md)) — sowie `js/debug-widget.js`, das die
Debug-Vorschau im Frontend aufbaut. Die Testdateien sind nach Widget bzw.
Funktionsgruppe benannt: eine Datei für die einzelnen
Validierungsfunktionen, je eine Datei für zusammengesetzte
Widget-Interaktionen und eine für das Debug-Widget. Diese Tests laufen unabhängig von PHPUnit und
prüfen ausschließlich Browser-seitiges Verhalten — die serverseitige
Gegenprüfung derselben Felder liegt in `tests/Validation/` bzw. den
entsprechenden PHPUnit-Direkt-Tests.

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
  die `saveConfig()` isoliert von einer echten
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
- [validation.md](validation.md) — welche Feldregeln client- und serverseitig geprüft werden
- [schema-extending.md](schema-extending.md) — `SchemaConsistencyTest`-Anforderungen an neue Schema-Dateien
- [architecture.md](architecture.md) — warum die `lib/`-Klassen zustandslos sind (Testbarkeit)
