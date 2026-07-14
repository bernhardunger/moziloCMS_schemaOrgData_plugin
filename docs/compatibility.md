# Kompatibilität

## Voraussetzungen

- **moziloCMS 3.0.4 oder höher.** Das Plugin nutzt die Settings-API des
  Cores (`$this->settings`, siehe [configuration.md](configuration.md))
  sowie die Standard-Plugin-Konventionen (`getContent()`, `getConfig()`,
  `getInfo()`), wie sie ab dieser Core-Version vorliegen.
- **PHP 8.1+.** Das Plugin macht u. a. von `readonly`-Properties
  (`SchemaOrgData_FrontendRequestContext`, `SchemaOrgData_AdminRequestContext`,
  `SchemaOrgData_ValidationResult`, siehe [architecture.md](architecture.md))
  und typisierten `match`-Ausdrücken Gebrauch — beides erfordert PHP 8.1
  oder neuer.
- **Schreibzugriff auf den Plugin-Ordner.** Die gesamte Live-Konfiguration
  liegt in `plugins/schemaOrgData/plugin.conf.php` (siehe
  [configuration.md](configuration.md)); der Webserver-Prozess muss diese
  Datei anlegen und beschreiben können.
- **Kein weiteres Plugin erforderlich.** `schemaOrgData` ist vollständig
  eigenständig lauffähig.

## `seo_urls`-Kompatibilität

Das Plugin ist mit dem optionalen `seo_urls`-Plugin kompatibel, ohne davon
abzuhängen oder es zu integrieren: Beide Plugins arbeiten unabhängig
voneinander im selben `<head>`-Bereich. „Kompatibel" bedeutet hier konkret:
Erzeugt `seo_urls` eine von der technischen Seiten-URL abweichende
kanonische URL, kann diese **manuell** als Wert des `url`-Felds in die
jeweilige Schema-Type-Konfiguration eingetragen werden (z. B.
`LocalBusiness.url`, `Organization.url`). Es gibt keine automatische
Übernahme — das Plugin liest `seo_urls`-Daten nicht aus, und `seo_urls`
wertet das erzeugte JSON-LD nicht aus. Die einzige echte Berührung mit
Core-URL-Logik ist `SchemaOrgData_UrlHelper::resolveBaseUrl()` für die
`@id`-Basis-URL (siehe [rendering.md](rendering.md#id-mechanismus)), die
unabhängig von `seo_urls` aus dem aktuellen Request abgeleitet wird.

<a id="abgrenzung-core"></a>
## Abgrenzung zu bestehenden Core-Implementierungen

moziloCMS 3.0 enthält bereits folgende Schema.org-Implementierungen als
**Microdata** (Attribute direkt im HTML-Markup, `itemprop`/`itemtype`):

| Bereich | Core-Implementierung | Dieses Plugin |
|---|---|---|
| Seiteninhalt | `Article` (Wrapper, minimal) | `Article` als JSON-LD im `<head>` |
| Bilder | `ImageObject` via `itemprop` | — |
| Breadcrumb | `BreadcrumbList` via Microdata | — |
| Kontakt | `LocalBusiness` via Microdata im Body | `LocalBusiness` als JSON-LD im `<head>` |

Dieses Plugin **ersetzt** die Core-Microdata nicht, sondern **ergänzt** sie
um JSON-LD im `<head>`. Beide Formate können nebeneinander bestehen — sie
beschreiben denselben Sachverhalt in unterschiedlicher Syntax an
unterschiedlicher Stelle im Dokument (Body-Attribute vs. `<script>`-Block
im Head). Google und die meisten anderen Suchmaschinen werten bei
Redundanz bevorzugt JSON-LD aus, weil es sich unabhängig vom
Seitenlayout parsen lässt; ein Konflikt zwischen beiden Formaten (z. B.
unterschiedliche Firmennamen) sollte trotzdem vermieden werden, da er die
Vertrauenswürdigkeit der strukturierten Daten insgesamt schwächt.

Bereiche, die der Core ausschließlich als Microdata abbildet (Bilder,
Breadcrumb), bildet dieses Plugin bewusst **nicht** zusätzlich als JSON-LD
ab — es gibt keinen unterstützten `ImageObject`- oder
`BreadcrumbList`-Schema-Type (siehe [../README.md](../README.md#unterstuetzte-schema-types)
für die vollständige Liste der unterstützten Types).

## Abhängigkeiten

### Laufzeit: AJV.js (client-seitig)

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

### Entwicklungszeit: Composer (PHP)

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

### Entwicklungszeit: npm/Jest (JavaScript)

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

### Zusammenfassung

Ein deployter `plugins/schemaOrgData/`-Ordner hat **keine** Abhängigkeit
außer der lokal mitgelieferten `js/ajv.min.js` — kein Composer-Autoload,
kein npm-Paket, kein CDN-Aufruf, kein weiteres moziloCMS-Plugin. PHPUnit
und Jest existieren ausschließlich im Entwicklungs-Repository
(`tests/`, Repo-Root-Ebene) und sind nicht Teil des Deployment-Pakets.

## Siehe auch

- [../README.md](../README.md#voraussetzungen-und-kompatibilitaet) — Kurzübersicht
- [configuration.md](configuration.md) — Settings-API, `plugin.conf.php`-Speicherformat
- [security.md](security.md) — warum kein CDN und keine externen Laufzeit-Abhängigkeiten
- [development.md](development.md) — `composer install`/`npm install` im Entwicklungsalltag
