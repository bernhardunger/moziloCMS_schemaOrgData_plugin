# Tests

Diese Seite beschreibt **welche Testarten es gibt und wofür sie stehen**.
Für lokales Setup, Testausführung und das Bootstrap-Mocking siehe
[development.md](development.md).

## Testarten im Überblick

| Testart | Was wird geprüft | Ort |
|---|---|---|
| PHPUnit-Unit-/Komponententests | Serverseitige Logik: Scope-Auflösung, JSON-LD-Erzeugung, Formular-Rendering, Validierung, Persistenz — je `lib/`-Klasse eigene Testdatei | `tests/*.php` |
| PHPUnit-Feldvalidator-Tests | Je ein Test pro serverseitigem Feldvalidator (E-Mail, URL, Telefon, PLZ, Öffnungszeiten, Geo-Koordinaten) | `tests/Validation/` |
| Jest-Tests | Clientseitige Live-Validierung (`js/validator.js`) in simulierter DOM-Umgebung | `tests/js/` |
| Playwright-Regressionstests | Browsergestützte End-to-End-Verifikation gegen eine reale moziloCMS-Installation | `tests/PLAYWRIGHT-VERIFICATION.md`, `tests/PLAYWRIGHT-VERIFICATION-accountingservice-usecase.md` |

Die ersten drei Testarten laufen automatisiert und ohne manuelles
Zutun; Playwright-Regressionstests sind anleitungsgestützte manuelle
bzw. per Playwright-MCP durchgeführte Browserläufe, kein Teil einer
CI-Pipeline.

## PHPUnit: Struktur und Benennung

Die Testdateien unter `tests/` folgen zwei Mustern (siehe
[development.md](development.md), Abschnitt „Testorganisation", für die
vollständige Aufschlüsselung):

- **Eine Testdatei je `lib/`-Klasse** (`ScopeResolverTest.php`,
  `JsonLdBuilderTest.php`, `FormRendererComponentTest.php`, …) — Direkt-Tests
  gegen die öffentlichen Methoden dieser Komponente, mit echten statt
  gemockten Kollaboratoren (siehe „Warum ein eigenes Test-Bootstrap nötig
  ist" in [development.md](development.md)).
- **Ein Type mit Sonderverhalten je eigener Testdatei**
  (`DonateActionTest.php`, `EventTest.php`, `PersonIdRefOrLiteralTest.php`)
  — deckt Schema-Struktur, Widget-Verhalten und den
  `saveConfig()`/`buildJsonLdScript()`-Roundtrip für die Besonderheiten
  dieses Types ab, statt generische Mechanismen erneut zu prüfen.

Ergänzend zwei querschnittliche Tests: `JsonLdOutputTest.php` prüft die
**Gesamtausgabe** (Scope-Merge, Type-Kollision, Ausschlussliste) über
mehrere Komponenten hinweg; `SchemaConsistencyTest.php` prüft
**Konsistenz über alle `schemas/*.json` hinweg** — u. a., dass der
`PostalAddress`-Definitionsblock strukturell identisch bleibt und
`required[]` mit `ui:required: true` bidirektional übereinstimmt (relevant
beim Anlegen eines neuen Schema-Types, siehe
[schema-extending.md](schema-extending.md)).

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
Mock-Bootstraps; die betroffenen Codepfade werden stattdessen über die
Playwright-Regressionstests (siehe unten) gegen eine echte Installation mit
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

## Playwright: Browser-Regressionstests

`tests/PLAYWRIGHT-VERIFICATION.md` ist die allgemeine Anleitung für einen
manuellen bzw. Playwright-MCP-gestützten Regressionslauf gegen eine echte
moziloCMS-Installation — u. a. für Verhalten, das PHPUnit strukturell nicht
erreichen kann (siehe „Was die dokumentierten Skips bedeuten" oben), sowie
für rein visuelle/interaktive Aspekte (Formular-Rendering im echten
Browser, Debug-Widget-Popup, Live-Validierungs-Feedback). Voraussetzung ist
laut dieser Anleitung eine per Copy/Junction verlinkte Installation, in der
`plugins/schemaOrgData/` 1:1 dem Deployment-Ordner entspricht.
`tests/PLAYWRIGHT-VERIFICATION-accountingservice-usecase.md` ist ein
zweites, enger gefasstes Anleitungsdokument speziell für den
`AccountingService`-Use-Case.

## Grundprinzip beim Erweitern

Ein neuer Schema-Type erfordert keine PHP-Änderung (siehe
[schema-extending.md](schema-extending.md)) — entsprechend sollte ein Test
für einen neuen Type in erster Linie das **Schema selbst** sowie den
`saveConfig()`/`buildJsonLdScript()`-Roundtrip für seine Besonderheiten
prüfen, nicht die generischen `lib/`-Mechanismen erneut, die bereits über
die vorhandenen Direkt-Tests abgedeckt sind.

## Siehe auch

- [../README.md](../README.md#tests) — Kurzübersicht und Ausführungsbefehle
- [development.md](development.md) — lokales Setup, Testausführung, Bootstrap-Mocking im Detail
- [validation.md](validation.md) — welche Feldregeln client- und serverseitig geprüft werden
- [schema-extending.md](schema-extending.md) — `SchemaConsistencyTest`-Anforderungen an neue Schema-Dateien
