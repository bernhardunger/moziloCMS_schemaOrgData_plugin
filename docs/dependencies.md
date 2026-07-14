# Abhängigkeiten

## Laufzeit: AJV.js (client-seitig)

Das Plugin liefert den JSON-Schema-Validator **AJV** lokal mit aus:
`plugins/schemaOrgData/js/ajv.min.js`. Es handelt sich um ein
UMD-Bundle (`!function(e){"object"==typeof exports...`), das sich als
globale Variable `window.ajv7` registriert — `js/validator.js` greift
genau darauf zu (`typeof window.ajv7 !== 'undefined'`) und instanziiert
daraus eine `Ajv`-Instanz mit `{ allErrors: true, strict: false }`. Die
mitgelieferte Datei unterstützt **JSON-Schema Draft-07** — dasselbe
Draft, das die Schema-Dateien in `schemas/*.json` deklarieren
(`"$schema": "http://json-schema.org/draft-07/schema#"`). Die minifizierte
Datei trägt keinen eingebetteten Versions- oder Lizenzkommentar; falls die
exakte Upstream-Version relevant wird, lässt sie sich nur durch Vergleich
mit einer offiziell veröffentlichten AJV-Build-Datei ermitteln.

**Warum lokal statt über ein CDN:**

- **Keine externe Laufzeit-Abhängigkeit.** Das Formular validiert auch
  dann live, wenn der Zugriff auf ein CDN durch eine restriktive
  Content-Security-Policy, einen Netzwerk-Ausfall oder eine
  Offline-/Intranet-Installation blockiert ist.
- **Kein Tracking-/Verfügbarkeitsrisiko eines Drittanbieters.** Ein
  CDN-Betreiber könnte Anfragen protokollieren oder — bei kompromittiertem
  CDN — eine manipulierte Datei ausliefern (Supply-Chain-Risiko). Siehe
  auch [security.md](security.md), Abschnitt „Kein CDN".
- **Versionsstabilität.** Die ausgelieferte Datei ändert sich nur, wenn
  ein Plugin-Update sie explizit ersetzt — kein unerwartetes Verhalten
  durch ein automatisches CDN-Versions-Update.

Eingebunden wird die Datei ausschließlich im **Admin-Formular**
(`SchemaOrgData_AdminPageRenderer`), mit einem `filemtime()`-basierten
Cache-Busting-Query-Parameter (`?v=<mtime>`,
`resolveAssetCacheBuster()` in `SchemaOrgData_AdminController`) — verhindert,
dass ein Browser nach einem Update von `ajv.min.js`/`validator.js` eine
veraltete, gecachte Fassung weiterverwendet. Im **Frontend** wird AJV
nicht geladen; die dort ausgegebenen JSON-LD-`<script>`-Blöcke sind reine
Daten, keine Formulare, die client-seitige Validierung erfordern.

## Entwicklungszeit: Composer (PHP)

`composer.json` (Repo-Root) deklariert genau **eine** Abhängigkeit, als
`require-dev`:

```json
"require-dev": {
    "phpunit/phpunit": "^11.0"
}
```

Zusätzlich ein PSR-4-Autoload-Mapping ausschließlich für die Testklassen
(`SchemaOrgData\Tests\` → `tests/`). Es gibt **keinen** `require`-Block —
das Plugin selbst hat zur Laufzeit keine Composer-Abhängigkeit und lädt
keinen Composer-Autoloader; alle produktiven Klassen werden über
`require_once`-Aufrufe in `index.php` eingebunden (siehe
[architecture.md](architecture.md)). `vendor/` (inkl. PHPUnit und dessen
transitiver Abhängigkeiten wie `sebastian/*`, `phar-io/*`,
`nikic/php-parser`) ist in `.gitignore` eingetragen und wird nicht ins
Repository eingecheckt — `composer install` ist vor dem ersten Testlauf
notwendig (siehe [development.md](development.md)), aber irrelevant für
eine reguläre Plugin-Installation.

## Entwicklungszeit: npm/Jest (JavaScript)

`tests/js/package.json` deklariert zwei `devDependencies`:

```json
"devDependencies": {
    "jest": "^29",
    "jest-environment-jsdom": "^29"
}
```

Testet ausschließlich `js/validator.js` in einer simulierten
DOM-Umgebung (`testEnvironment: "jsdom"`). `tests/js/node_modules/` wird
nicht eingecheckt; `npm install` ist vor dem ersten Jest-Lauf notwendig
(siehe [development.md](development.md)). Auch dieses Paket ist reine
Testinfrastruktur, keine Laufzeit-Abhängigkeit des Plugins.

## Zusammenfassung

Ein deployter `plugins/schemaOrgData/`-Ordner hat **keine** Abhängigkeit
außer der lokal mitgelieferten `js/ajv.min.js` — kein Composer-Autoload,
kein npm-Paket, kein CDN-Aufruf, kein weiteres moziloCMS-Plugin. PHPUnit
und Jest existieren ausschließlich im Entwicklungs-Repository
(`tests/`, Repo-Root-Ebene) und sind nicht Teil des Deployment-Pakets.

## Siehe auch

- [../README.md](../README.md#voraussetzungen-und-kompatibilitaet) — Kurzübersicht
- [compatibility.md](compatibility.md) — moziloCMS-/PHP-Versionsanforderung, `seo_urls`
- [security.md](security.md) — Begründung „Kein CDN" im Sicherheitskontext
- [development.md](development.md) — `composer install`/`npm install` im Entwicklungsalltag
