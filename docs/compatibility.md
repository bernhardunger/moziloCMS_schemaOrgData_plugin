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

## Siehe auch

- [../README.md](../README.md#voraussetzungen-und-kompatibilitaet) — Kurzübersicht
- [dependencies.md](dependencies.md) — AJV.js und Composer-/Jest-Abhängigkeiten im Detail
- [configuration.md](configuration.md) — Settings-API, `plugin.conf.php`-Speicherformat
- [security.md](security.md) — warum kein CDN und keine externen Laufzeit-Abhängigkeiten
