# Sicherheit

Sechs Härtungsmechanismen greifen ineinander — jeder adressiert eine
eigene Angriffsfläche. Die konkrete Fundstelle steht jeweils dabei.

## Kein Direktzugriff (`IS_CMS`-Guard)

Jede PHP-Datei des Plugins — `index.php` ebenso wie jede einzelne Datei
unter `lib/` — beginnt mit derselben Zeile:

```php
<?php if(!defined('IS_CMS')) die();
```

Ruft jemand eine dieser Dateien direkt per URL auf (statt über den
moziloCMS-Bootstrap, der `IS_CMS` definiert), bricht die Ausführung sofort
ab. Verhindert, dass interne Klassen außerhalb des CMS-Kontexts geladen
werden und dabei z. B. auf nicht initialisierte globale Zustände
(`$CMS_CONF`, `$ADMIN_CONF`) zugreifen.

## Settings-Key-Härtung (`sanitizeScopeIdentifier()`)

`SchemaOrgData_ScopeResolver::sanitizeScopeIdentifier()` bereinigt jeden
Kategorie-/Seiten-Bezeichner, bevor er Teil eines Settings-Keys wird:

```php
preg_replace('/[^a-zA-Z0-9_\-%]/', '', $value)
```

Erlaubt bleiben Buchstaben, Ziffern, Bindestrich, Unterstrich und `%`
(moziloCMS URL-kodiert Bezeichner mit Sonderzeichen); Path-Traversal-Zeichen
(`.`, `/`, `\`, NUL) werden entfernt. Die Methode wird sowohl beim
Schreiben (`SchemaOrgData_AdminRequestHandler`/`SchemaOrgData_ConfigSaveService`)
als auch beim Lesen (`SchemaOrgData_FrontendRenderer::renderFrontend()`)
auf `CAT_REQUEST`/`PAGE_REQUEST` bzw. deren Admin-Fallback angewendet —
identische Sanitierung auf beiden Pfaden verhindert zusätzlich, dass
Lese- und Schreib-Settings-Key für dieselbe Kategorie/Seite auseinanderlaufen
(siehe [configuration.md](configuration.md)).

## Eingabe-Sanitizing (`sanitizePostData()`)

`SchemaOrgData_ConfigSaveService::sanitizePostData()` verarbeitet **jeden**
Formularwert vor dem Speichern mit `trim(strip_tags((string) $wert))` —
umschließt alle Feldtypen (Text, Textarea, verschachtelte Adress-/
Place-/Geo-/FAQ-Felder). HTML-Tags werden dadurch bereits vor der
Persistierung entfernt, nicht erst bei der Ausgabe. Telefonnummern
durchlaufen zusätzlich eine eigene Normalisierung
(`preg_replace('/[^0-9+]/', '', $stringValue)`), bevor die
E.164-Validierung greift (siehe [validation.md](validation.md)).

## Script-Breakout-Schutz (`JSON_HEX_TAG`)

Jede Stelle, an der das Plugin JSON in einen `<script>`-Block einbettet,
kodiert mit dem Flag `JSON_HEX_TAG`:

- `SchemaOrgData_JsonLdBuilder::buildJsonLdScript()` — der eigentliche
  JSON-LD-Block im Frontend.
- `SchemaOrgData_FrontendRenderer::buildDebugWidget()` — die
  Debug-Vorschau-Nutzlast (`schemaOrgDataDebugData`).
- `SchemaOrgData_AdminController::renderAdminPage()` — die an
  `window.schemaOrgDataMessages` übergebenen, aus den Sprachdateien
  stammenden Meldungstexte fürs Admin-JavaScript.

`JSON_HEX_TAG` kodiert `<`/`>` in Feldwerten als Unicode-Escapes
(`<`/`>`). Ein Feldwert wie `</script><script>alert(1)</script>`
kann den umgebenden `<script>`-Block dadurch nicht aufbrechen — das
schließende `</script>`-Muster taucht im ausgelieferten HTML gar nicht
mehr als solches auf. Betrifft strukturell jeden Formularwert, der ins
JSON-LD wandert, nicht nur offensichtlich gefährliche Felder.

## Server-seitige Validierung ist maßgeblich

Die client-seitige AJV-Validierung (siehe
[compatibility.md](compatibility.md#abhängigkeiten)) ist reiner Komfort — sie kann durch deaktiviertes JavaScript oder
manipulierte Requests umgangen werden. Gespeichert wird ausschließlich,
was die PHP-seitige Prüfung besteht:

- Formularfelder: `SchemaOrgData_Validator::validateFormData()`, aufgerufen
  aus `SchemaOrgData_ConfigSaveService::saveConfig()`.
- Erweiterungsfeld: `SchemaOrgData_ConfigSaveService::validateExtensionField()`
  — `json_decode()` gegen ungültiges JSON (`json_last_error() !==
  JSON_ERROR_NONE`), danach `SchemaOrgData_Validator::validateExtensionGeo()`
  gegen inhaltlich ungültige `geo`-Werte.

Beide Prüfungen laufen unabhängig von jeglichem Client-seitigen Zustand —
ein Request ohne JavaScript oder mit manipuliertem POST-Body durchläuft
dieselbe Validierung wie ein regulärer Formular-Submit.

## Kein CDN

`js/ajv.min.js` wird als Datei im Plugin-Ordner ausgeliefert, nicht von
einem externen Content-Delivery-Network geladen — keine externe
Skript-Quelle, die bei einer restriktiven Content-Security-Policy blockiert
werden könnte oder ein zusätzliches Supply-Chain-Risiko durch einen
Drittanbieter darstellt. Details und Versionshinweise siehe
[compatibility.md](compatibility.md#abhängigkeiten).

## Siehe auch

- [../README.md](../README.md#sicherheit) — Kurzübersicht
- [compatibility.md](compatibility.md#abhängigkeiten) — AJV.js im Detail, warum lokal statt CDN
- [validation.md](validation.md) — Feld-für-Feld-Validierungsregeln (client- und serverseitig)
- [configuration.md](configuration.md) — Settings-Key-Bildung und -Sanitizing im Kontext
- [rendering.md](rendering.md) — `buildJsonLdScript()`-Transformationsreihenfolge inkl. `JSON_HEX_TAG`
