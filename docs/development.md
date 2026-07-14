# Entwicklung

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

`phpunit.xml` definiert zwei Testsuites:

```xml
<testsuite name="schemaOrgData">
  <directory>tests</directory>
  <exclude>tests/Validation/</exclude>
</testsuite>
<testsuite name="schemaOrgData-Validation">
  <directory>tests/Validation</directory>
</testsuite>
```

`tests/Validation/` (je ein Test pro Feldvalidator: E-Mail, URL, Telefon,
PLZ, Öffnungszeiten, Geo-Koordinaten) ist von der Hauptsuite
ausgeschlossen und läuft als eigene Suite — organisatorische Trennung,
beide laufen bei `./vendor/bin/phpunit` ohne weitere Optionen zusammen.

## Jest-Setup (clientseitige Validierung)

`js/validator.js` wird über `tests/js/` mit Jest getestet:

```bash
cd tests/js
npm install
npm test
```

`tests/js/package.json` konfiguriert `testEnvironment: "jsdom"` (DOM-APIs
für Formular-/Event-Tests ohne echten Browser) und referenziert
`jest`/`jest-environment-jsdom` (`^29`) als Dev-Dependency. Die
Testdateien decken einzelne Validierungsfunktionen sowie zusammengesetzte
Widget-Interaktionen ab (`address-required-widget`,
`event-date-range-widget`, `extension-field-wiring`, `geo-widget`,
`import-autofill-button`, `opening-hours-widget`,
`validator-functions`).

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

## Testorganisation

`tests/` liegt eine Ebene über `plugins/schemaOrgData/` (Repo-Root), nicht
darunter — analog zu `docs/`. Grober Zuschnitt:

- **Eine Testdatei je `lib/`-Klasse** (`ScopeResolverTest.php`,
  `JsonLdBuilderTest.php`, `FormRendererComponentTest.php`, …) — Direkt-Tests
  gegen die öffentlichen Methoden dieser Klasse.
- **Feature-/Type-übergreifende Tests** (`JsonLdOutputTest.php` — Gesamt-
  ausgabe inkl. Scope-Merge und Type-Kollision; `SchemaConsistencyTest.php`
  — Konsistenzprüfung über alle `schemas/*.json` hinweg, u. a. dass
  `definitions.PostalAddress` strukturell identisch bleibt und
  `required[]` mit `ui:required: true` bidirektional übereinstimmt).
- **Ein Type mit besonderem Verhalten je eigener Testdatei**
  (`DonateActionTest.php`, `EventTest.php`, `PersonIdRefOrLiteralTest.php`)
  — decken das Zusammenspiel aus Schema-Struktur, Widget-Verhalten und
  `saveConfig()`-Roundtrip für diesen Type ab.
- **`tests/Validation/`** — ein Test je serverseitigem Feldvalidator.
- **`tests/Fixtures/`** — ausgelagerte Fixture-Klassen (kein eigener
  Autoloader-Eintrag; werden per `require_once` aus den nutzenden
  Testdateien eingebunden).

## Grundprinzip beim Erweitern

Ein neuer Schema-Type erfordert im Regelfall keine PHP-Änderung (siehe
[schema-extending.md](schema-extending.md) für Ausnahmen) — entsprechend sollte auch
ein Test für einen neuen Type in erster Linie das **Schema selbst** sowie
den `saveConfig()`/`buildJsonLdScript()`-Roundtrip für seine
Besonderheiten prüfen (neue Widget-Kombination, neues `ui:idFragment`,
…), nicht die generischen `lib/`-Mechanismen erneut — die sind bereits
über die vorhandenen Direkt-Tests abgedeckt.

## Commit-Konventionen

Commit-Messages sind auf Deutsch verfasst, im Imperativ oder als knappe
Beschreibung der Änderung („Dokumentation: README.md nach
Strukturvorgabe umgebaut", „docs/ an den Repo-Root verschoben"). Inline-
Dokumentation im Code (PHPDoc, Kommentare) ist ebenfalls durchgehend auf
Deutsch gehalten.

## Siehe auch

- [../README.md](../README.md) — Abschnitt „Tests" für den Nutzer-Blickwinkel
- [tests.md](tests.md) — Testarten, Skip-Bedeutung
- [architecture.md](architecture.md) — warum die `lib/`-Klassen zustandslos sind (Testbarkeit)
- [schema-extending.md](schema-extending.md) — neuen Schema-Type hinzufügen, ohne PHP anzufassen
- [file-structure.md](file-structure.md) — Lage von `tests/` relativ zum Deployment-Ordner
