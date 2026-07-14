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

<a id="abhaengigkeiten"></a>
## Abhängigkeiten

### Laufzeit: AJV.js (client-seitig)

Das Plugin liefert den JSON-Schema-Validator **AJV** lokal mit aus:
`plugins/schemaOrgData/js/ajv.min.js`. Die mitgelieferte Datei
unterstützt **JSON-Schema Draft-07** — dasselbe Draft, das die
Schema-Dateien in `schemas/*.json` deklarieren (`"$schema":
"http://json-schema.org/draft-07/schema#"`), relevant beim Anlegen eines
neuen Schema-Types (siehe [schema-extending.md](schema-extending.md)).

Der lokale Bezug statt über ein CDN vermeidet eine externe
Laufzeit-Abhängigkeit und ein Supply-Chain-Risiko (siehe auch
[security.md](security.md), Abschnitt „Kein CDN"). Eingebunden wird die
Datei ausschließlich im **Admin-Formular**; im **Frontend** wird AJV
nicht geladen, da die dort ausgegebenen JSON-LD-`<script>`-Blöcke reine
Daten sind, keine Formulare, die client-seitige Validierung erfordern.

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
notwendig (siehe [tests.md](tests.md)), aber irrelevant für
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
(siehe [tests.md](tests.md)). Auch dieses Paket ist reine
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
- [tests.md](tests.md) — `composer install` im Entwicklungsalltag
- [tests.md](tests.md) — `npm install`/Jest-Testausführung im Detail
