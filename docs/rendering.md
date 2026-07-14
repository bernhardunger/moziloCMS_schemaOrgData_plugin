# Formular-Rendering und JSON-LD-Erzeugung

Zwei Komponenten bilden die zwei Richtungen des schema-getriebenen
Formulars: `SchemaOrgData_FormRenderer` wandelt ein JSON-Schema plus
gespeicherte Daten in HTML-Formularfelder um (Admin-Kontext),
`SchemaOrgData_JsonLdBuilder` wandelt gespeicherte Daten (nach Vererbung
über die Geltungsbereiche) in den finalen `<script type="application/
ld+json">`-Block um (Frontend-Kontext). Beide lesen dasselbe Schema aus
`SchemaOrgData_SchemaRepository`, sind aber unabhängig voneinander
aufrufbar und kennen sich nicht gegenseitig.

## Formular-Rendering (`SchemaOrgData_FormRenderer`)

### Einstiegspunkt: `renderTypeFields()`

`renderTypeFields()` rendert alle Felder eines Types für eine
Geltungsebene:

1. `SchemaOrgData_DataSplitHelper::splitDataForRendering($data, $schema)`
   trennt die gespeicherten Properties in `form` (im Schema bekannte
   Properties) und `extension` (unbekannte Properties, landen im
   JSON-Textarea).
2. Existiert `$extensionJsonOverride` (Re-Display nach fehlgeschlagenem
   Speichern oder nach einem Import, siehe
   `SchemaOrgData_AdminController::renderScopeSection()`), wird dieser
   Rohtext statt der aus `$data` abgeleiteten Erweiterungs-Properties
   verwendet.
3. Ist mindestens ein Feld `ui:required`, wird eine Legende
   (`label_required_legend`) vorangestellt.
4. Für jede Property in `schema.properties` wird `renderField()`
   aufgerufen; am Ende `renderExtensionFieldWidget()` für das
   JSON-Textarea.

### `renderField()`: Dispatch je `ui:widget`

`renderField()` löst zuerst `$ref`-Verweise auf
(`SchemaOrgData_SchemaRepository::resolveSchemaRef()`) und verzweigt dann
anhand von `ui:widget`:

- **`id_reference`** — rein deklaratives Widget: kein `<input>`, keine
  `name`-Attribut-Erzeugung. Stattdessen eine schreibgeschützte
  Info-Zeile mit der aufgelösten `@id`-URI
  (`SchemaOrgData_UrlHelper::resolveFrontendBaseUrl()`, um ein
  admin-spezifisches `admin/`-Pfadsegment aus der Anzeige zu kürzen). Der
  eigentliche Wert entsteht erst zur Build-Zeit in
  `buildJsonLdScript()` (siehe unten) — es gibt keinen POST-Wert für
  dieses Feld.
- **`id_reference_or_literal`** — delegiert an
  `renderIdReferenceOrLiteralWidget()` (siehe unten).
- **`place`** — delegiert an `renderPlaceWidget()`: Namensfeld plus
  verschachtelte `postal_address`-Gruppe unter `<name>.address` (per
  `$groupPrefix`, siehe unten).
- **`postal_address` / `opening_hours` / `faq_list` / `geo`** — jeweils
  eigenes `<fieldset>` mit spezialisiertem Rendering
  (`renderPostalAddressWidget()`, `renderOpeningHoursWidget()`,
  `renderFaqListWidget()`, `renderGeoWidget()`).
- **alles andere** (`text`, `textarea`, `select`) — einfache Zeile
  (`renderTextWidget()`/`renderTextareaWidget()`/`renderSelectWidget()`)
  mit Live-Validierungs-Attributen aus `buildValidationAttrs()` und
  serverseitig vorberechnetem Feedback aus `renderFieldFeedback()`.

Alle Formularfeldnamen folgen dem Muster
`schemaOrgData[<scope>][data][<property>]`, verschachtelte Felder (z. B.
`postal_address` unter einem `place`-Widget) hängen ein zusätzliches
Prefix-Segment an: `schemaOrgData[<scope>][data][<groupPrefix>][<property>]
[<subProperty>]`.

### Vererbungsanzeige: Placeholder + „ü"-Badge

Für Kategorie-/Seiten-Sektionen ermittelt
`SchemaOrgData_ConfigSaveService::resolveInheritableFields()` vorab, welche
Feldwerte von einer allgemeineren Ebene geerbt würden (Global bzw.
Global→Kategorie, spezifischere Ebene gewinnt bei mehreren Treffern) —
rein zur **Anzeige**: Ist das Feld auf der aktuellen Ebene leer, aber ein
geerbter Wert vorhanden, zeigt `renderField()` diesen Wert als
`ui:placeholder` und hängt ein „ü"-Badge
(`renderInheritedBadge()`, Tooltip nennt die Ursprungsebene) an. Der
geerbte Wert wird **nicht** in das Eingabefeld übernommen und nicht
gespeichert — er greift zur Laufzeit ohnehin über
`resolveTypeInheritance()` (siehe [configuration.md](configuration.md)).

### `id_reference_or_literal`-Widget im Detail

`renderIdReferenceOrLiteralWidget()` rendert zwei Radio-Optionen
(„Verknüpfen" / „Manuell") mit je einem eingeblendeten Bereich:
Referenz-Modus zeigt ein Dropdown aller global konfigurierten `@id`-Träger
(`SchemaOrgData_IdReferenceService::resolveAvailableGlobalFragments()`,
einmal pro Sektion berechnet und an alle Felder durchgereicht),
Literal-Modus zeigt die in `ui:literalFields` deklarierten Textfelder.

Eine Besonderheit: Da alle Geltungsbereich-Sektionen (auch inaktive)
vorgerendert werden (siehe [architecture.md](architecture.md)), würde ein
über alle Sektionen hinweg identischer Radio-`name` dazu führen, dass der
Browser sämtliche gleichnamigen Radios zu einer einzigen Gruppe
zusammenfasst und bis auf das zuletzt im DOM stehende Exemplar entcheckt.
Der Radiogruppen-Name ist daher pro Widget-Instanz eindeutig
(`schemaOrgData_idrl_<idPrefix>_<name>_mode`), der tatsächlich zu
speichernde `_mode`-Wert läuft stattdessen über ein verstecktes Feld mit
dem regulären Feldnamen, das eine kleine, idempotente Inline-Funktion
(`schemaOrgDataIdRlToggle`) bei jeder Radio-Auswahl nachführt.

### Zusammengesetzte Widgets

- **`postal_address`** — fünf Sub-Felder (`streetAddress`, `postalCode`,
  `addressLocality`, `addressRegion`, `addressCountry`), `addressCountry`
  als `<select>` mit ISO-3166-alpha-2-Codes. PLZ und Ort stehen kompakt in
  einer Zeile (`renderAddressFieldGroup()`), ebenso wird die
  Pflichtfeld-Prüfung eines Sub-Felds bedingt ausgelöst
  (`data-validate="address_required"`): nur wenn irgendein Feld der
  Gruppe befüllt ist, gilt „Ort" als Pflichtfeld — eine komplett leere
  Adresse ist zulässig (siehe `SchemaOrgData_Validator::
  validatePostalAddressData()`/`isAddressProvided()`).
- **`place`** (z. B. `Event.location`, `JobPosting.jobLocation`) —
  Namensfeld außerhalb, `postal_address` darunter mit `$groupPrefix` =
  Property-Name (Feldnamen verschachteln sich dadurch als
  `schemaOrgData[scope][data][location][address][...]`). Ist das gesamte
  `place`-Widget `ui:required` (z. B. `JobPosting.jobLocation`), wird die
  Pflicht des „Ort"-Feldes unconditional erzwungen
  (`$forceRequired`), statt von einem befüllten Geschwisterfeld
  abzuhängen.
- **`geo`** — Breite/Länge als Feldpaar mit „beides oder nichts"-Pflicht:
  ist nur ein Feld befüllt, gilt am jeweils leeren Feld
  `error_geo_incomplete`; sind beide befüllt, wird jedes einzeln gegen
  seinen Wertebereich geprüft.
- **`opening_hours`** — Tabelle mit einer Zeile je Wochentag
  (`ui:days`), Von/Bis plus optionalem zweiten Zeitraum (Pause). Rohdaten
  können in zwei Formen vorliegen: bereits als `openingHours`-Array in
  schema.org-Notation (gespeicherte Konfiguration) oder als rohe
  Pro-Tag-Werte (`{Mo: {from, to, from2, to2}, …}`, Re-Display nach
  fehlgeschlagenem Speichern) — `SchemaOrgData_OpeningHoursHelper::
  isPerDayOpeningHoursValue()` unterscheidet beide Fälle.
- **`faq_list`** — bestehende Einträge plus stets eine zusätzliche leere
  Zeile zum Anlegen eines neuen Eintrags.

## JSON-LD-Erzeugung (`SchemaOrgData_JsonLdBuilder`)

### `buildJsonLdScript()`: Transformationsreihenfolge

`buildJsonLdScript(schemaRepo, urlHelper, pluginSelfDir, type, data,
nodeId, suppressedIdTargets)` durchläuft die zusammengeführten
Properties eines Types (bereits feldweise über die Geltungsebenen
vererbt, siehe [configuration.md](configuration.md)) in exakt dieser
Reihenfolge:

1. **`decodeJsonLdValues()`** — kehrt die beim Speichern angewandte
   `htmlspecialchars()`-Kodierung (`SchemaOrgData_ConfigSaveService::
   sanitizePostData()`) rekursiv um (`htmlspecialchars_decode()`), damit
   z. B. `&` nicht als `&amp;` im JSON-LD landet.
2. **`removeEmptyJsonLdProperties()`** — entfernt rekursiv Properties mit
   `null`, `''` oder `[]`, damit unvollständige verschachtelte Objekte
   (z. B. eine `PostalAddress` mit nur `addressCountry = "DE"`, siehe
   `sanitizeAddressData()`) nicht als leerer Rumpf im JSON-LD auftauchen.
3. **Verschachtelte Typisierung** — `address` → `PostalAddress`, `geo` →
   `GeoCoordinates`, `location`/`jobLocation` → `Place`, sowie eine Ebene
   tiefer `location.address`/`jobLocation.address` → `PostalAddress`. Ein
   eventuell im Erweiterungsfeld mitgeliefertes `@type` wird vorher
   entfernt, damit der schema-vorgegebene Type nicht überschreibbar ist.
4. **`id_reference`/`id_reference_or_literal`-Emission** — für jede
   Property mit diesem Widget wird der tatsächliche JSON-LD-Wert erst
   hier gebildet (siehe „`@id`-Referenzen zur Build-Zeit" unten). Diese
   Properties haben zu diesem Zeitpunkt entweder gar keinen gespeicherten
   Wert (`id_reference`) oder ein internes `{_mode, _fragment, …}`-Array
   (`id_reference_or_literal`), das hier in die endgültige JSON-LD-Form
   überführt wird.
5. **Reservierte Top-Level-Schlüssel entfernen** — `@context`, `@type`
   und `@id` werden aus `$data` entfernt, bevor das Erweiterungsfeld
   gemergt wird. Da das Erweiterungsfeld bereits mit den Formulardaten
   zusammengeführt in `$data` steckt, verhindert dieser Schritt, dass ein
   Nutzer über das Erweiterungsfeld z. B. `@type` überschreibt.
6. **Kopf zusammensetzen** — `{"@context": "https://schema.org", "@type":
   $type}`, `@id` (`$nodeId`) nur falls nicht-leer direkt danach.
   **Wichtig:** Das geschieht erst *nach* Schritt 2
   (Leerfeld-Bereinigung) — sonst würde ein Knoten, der nur aus `@type`
   und `@id` besteht (z. B. ein Dangling-Reference-Stub), fälschlich als
   „leer" gefiltert.
7. **`json_encode()`** mit `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
   | JSON_PRETTY_PRINT | JSON_HEX_TAG`. `JSON_HEX_TAG` kodiert `<`/`>` in
   Feldwerten als Unicode-Escapes und verhindert damit, dass ein
   Feldwert wie `</script><script>…` aus dem umgebenden `<script>`-Block
   ausbricht (Stored-XSS-Schutz, siehe auch
   [../README.md](../README.md), Abschnitt „Sicherheit").

Ausgabe: `<script type="application/ld+json">\n{...}\n</script>\n`.

<a id="id-mechanismus"></a>
### `@id`-Mechanismus im Detail

`ui:idFragment` in der Schema-Datei deklariert einen Type als
potenziellen `@id`-Träger (rein schema-getrieben — im PHP-Code steht kein
Type-Name). `resolveNodeId(schemaRepo, urlHelper, pluginSelfDir, $type,
&$assignedFragments)` entscheidet je auszugebendem Knoten:

1. Schema laden, `ui:idFragment` lesen. Fehlt es → kein `@id` (`''`).
2. **De-Dup-Guard:** Steht das Fragment bereits in `$assignedFragments`
   (per Referenz über die gesamte Ausgabeschleife eines Requests
   mitgeführt, siehe `SchemaOrgData_FrontendRenderer::renderFrontend()`)
   → kein `@id` für diesen (zweiten) Knoten.
3. **Basis-URL:** `SchemaOrgData_UrlHelper::resolveBaseUrl()` leitet
   Protokoll+Host+Pfad aus dem aktuellen Request ab (kein eigenes
   Domain-Setting). Lässt sich kein Host ermitteln → kein `@id`.
4. Fragment als vergeben markieren, `<Basis-URL>#<Fragment>` zurückgeben.

Weil `$assignedFragments` für die gesamte Seite (alle Geltungsebenen,
alle Types) durchgereicht wird, erhält bei geteiltem Fragment (z. B.
**LocalBusiness** und **Organization** teilen sich `"organization"`) nur der
in Ausgabereihenfolge erste Knoten tatsächlich eine `@id` — unabhängig
davon, auf welcher Geltungsebene er steht.

### `@id`-Referenzen zur Build-Zeit

`id_reference`- und `id_reference_or_literal`-Properties haben **keinen**
für sich stehenden gespeicherten Endwert — ihre JSON-LD-Repräsentation
entsteht ausschließlich in `buildJsonLdScript()`:

- **`id_reference`** — `ui:idTarget` benennt das Ziel-Fragment. Ist das
  Ziel nicht in `$suppressedIdTargets` und die Basis-URL auflösbar, wird
  `{"@id": "<Basis-URL>#<target>"}` eingesetzt.
- **`id_reference_or_literal`** — der gespeicherte Wert ist ein Array mit
  `_mode`. Im Modus `"reference"` wird analog zu `id_reference`
  `{"@id": "<Basis-URL>#<_fragment>"}` eingesetzt. Im Modus `"literal"`
  werden die restlichen Felder (nach Entfernen von `_mode` und einem
  eigenen Leerfeld-Filter-Durchlauf) als eingebettetes Objekt mit
  optionalem `@type` (aus `ui:literalType`) ausgegeben — **ohne** `@id`,
  ohne Dangling-Guard-Bezug.

### Dangling-Reference-Guard (`SchemaOrgData_IdReferenceService::applyDanglingReferenceGuard()`)

Wird in `SchemaOrgData_FrontendRenderer::renderFrontend()` nach
`resolveTypeInheritance()` und vor der Ausgabeschleife aufgerufen. Ablauf:

1. **Aktive Ziele sammeln** — über alle verbliebenen Scope-Konfigurationen
   alle `ui:idTarget`-Werte aktiver `id_reference`-Properties sowie alle
   `_fragment`-Werte aktiver `id_reference_or_literal`-Properties im
   Referenz-Modus.
2. **Vorhandene Fragmente sammeln** — `ui:idFragment` aller Types, die im
   finalen Graph tatsächlich ausgegeben werden.
3. Für jedes aktive Ziel **ohne** vorhandenes Fragment:
   - Ist die globale Ebene durch `jsonld_mode = 'keep'` unterdrückt
     (`$globalSuppressedByKeep`) → das Ziel landet in
     `$suppressedIdTargets`; die `id_reference`-Emission unterbleibt
     (kein Dangling-`@id` gegen den ausdrücklichen Nutzerwunsch).
   - Sonst → ein **Minimal-Stub** des Zielknotens wird synthetisch in
     `$scopeConfigs['global']` eingefügt: nur der `name`-Wert aus der
     tatsächlichen globalen Konfiguration (sofern vorhanden), `@type`
     und `@id` ergänzt die reguläre Ausgabeschleife
     (`resolveNodeId()`/`buildJsonLdScript()`) wie bei jedem anderen
     Knoten. Typischer Auslöser: Global ist per `excluded_cats` auf der
     aktuellen Kategorie ausgeblendet, aber eine Seite verweist per
     `id_reference` trotzdem auf den globalen Organisationsknoten.

Ist derselbe Fragment-Name bereits durch einen aktiv ausgegebenen Knoten
(z. B. `LocalBusiness` auf Kategorie-Ebene) gedeckt, bleibt der Guard ein
No-op — der Stub wird nur erzwungen, wenn *kein* aktiver Knoten mit
diesem Fragment übrig bleibt.

## Debug-Widget und Byte-Identität

`SchemaOrgData_FrontendRenderer::buildDebugWidget()` — das im Frontend
zuschaltbare Vorschau-Popup (siehe [../README.md](../README.md),
Abschnitt „Debug-Modus") — ruft für jeden Block intern erneut
`buildJsonLdScript()` mit denselben Parametern auf, statt die
Transformationen (Leerfeld-Filter, verschachtelte Typisierung,
`id_reference`-Auflösung) ein zweites Mal nachzubilden. Das garantiert
strukturell, dass die Vorschau byte-identisch zur echten Ausgabe ist,
statt potenziell abzuweichen.

## Ausgabebeispiele

Vollständige JSON-LD-Ausgabebeispiele, die über die Kurzbeispiele in
[use-cases/](use-cases/) hinausgehen — insbesondere das Zusammenspiel
mehrerer gleichzeitig aktiver Geltungsbereiche, wie es real im
`<head>`-Bereich einer einzelnen Frontend-Seite ausgegeben wird. Beide
Beispiele bilden das tatsächliche Verhalten von
`SchemaOrgData_FrontendRenderer::renderFrontend()` ab (siehe oben,
[configuration.md](configuration.md)).

### Beispiel 1: Drei gleichzeitig aktive Geltungsbereiche

Konfiguration:

- **Global:** `LocalBusiness` (Identität der gesamten Website)
- **Kategorie** `ratgeber`: `Article` (die Kategorie ist ein Blog-Bereich)
- **Seite** `ratgeber/steuererklaerung-2026`: `FAQPage` (die Seite beantwortet
  häufige Fragen zum Artikelthema)

Da alle drei Types unterschiedlich sind, greift hier **keine**
Type-Kollision (siehe Beispiel 2) — jede Ebene gibt ihren eigenen Block
aus. `renderFrontend()` baut `$scopeConfigs` in der Reihenfolge Global →
Kategorie → Seite auf und iteriert in exakt dieser Reihenfolge über die
Ausgabeschleife; die Blöcke erscheinen im `<head>` deshalb in derselben
Reihenfolge:

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "@id": "https://www.beispiel.de/#organization",
  "name": "Muster Beratung GmbH",
  "url": "https://www.beispiel.de",
  "telephone": "+498912345678",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Musterstraße 12",
    "postalCode": "12345",
    "addressLocality": "Musterstadt",
    "addressCountry": "DE"
  },
  "openingHours": ["Mo-Fr 09:00-18:00"]
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "Steuererklärung 2026: Was sich ändert",
  "author": {
    "@type": "Organization",
    "name": "Muster Beratung GmbH"
  },
  "datePublished": "2026-01-15"
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Bis wann muss ich meine Steuererklärung 2026 abgeben?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Der reguläre Abgabetermin ist der 31. Juli des Folgejahres, bei steuerlicher Vertretung verlängert sich die Frist."
      }
    }
  ]
}
</script>
```

Zwei Details, die sich nur im Zusammenspiel mehrerer Blöcke zeigen:

- Nur `LocalBusiness` trägt eine `@id` (`ui:idFragment: "organization"`,
  siehe [use-cases/organization-identity.md](use-cases/organization-identity.md))
  — **Article** und **FAQPage** haben in ihrer Schema-Datei kein
  `ui:idFragment` und werden deshalb ohne `@id` ausgegeben.
- Der `Article`-Block ist **nicht** von `LocalBusiness` abhängig, obwohl
  `author.name` inhaltlich denselben Namen trägt — es gibt keine
  automatische Verknüpfung zwischen Kategorie- und Global-Scope-Daten
  außerhalb der expliziten `id_reference`/`id_reference_or_literal`-Widgets
  (siehe [widgets.md](widgets.md)). Eine inhaltliche Konsistenz
  zwischen den Ebenen liegt in der Verantwortung des Pflegenden.

### Beispiel 2: Type-Kollision mit feldweiser Vererbung

Derselbe Schema-Type ist auf zwei Ebenen konfiguriert — Global und Seite.
`resolveTypeInheritance()` merged die Felder (spezifischere Ebene
überschreibt nur die tatsächlich befüllten Felder) und ordnet den
resultierenden Knoten ausschließlich der **spezifischsten** Ebene zu, auf
der der Type vorkommt; die allgemeinere Ebene gibt ihn nicht zusätzlich
aus.

Konfiguration:

- **Global:** `LocalBusiness` mit vollständiger Adresse, Telefonnummer,
  Öffnungszeiten
- **Seite** `filialen/muenchen-nord`: `LocalBusiness` mit abweichender
  Adresse und Telefonnummer für diesen Standort (`name`, `url` und
  `openingHours` bleiben auf Seiten-Ebene leer)

Ausgabe auf dieser Seite — **ein einziger** `LocalBusiness`-Block, nicht
zwei:

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "@id": "https://www.beispiel.de/#organization",
  "name": "Muster Beratung GmbH",
  "url": "https://www.beispiel.de",
  "telephone": "+4989987654",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Nordring 4",
    "postalCode": "80999",
    "addressLocality": "München",
    "addressCountry": "DE"
  },
  "openingHours": ["Mo-Fr 09:00-18:00"]
}
</script>
```

`name`, `url` und `openingHours` stammen von der globalen Ebene (auf
Seiten-Ebene leer gelassen), `telephone` und `address` stammen vollständig
von der Seiten-Ebene — bei verschachtelten Feldern wie `address` gewinnt
die Ebene mit dem **gefüllten Objekt vollständig**, es findet kein Merge
innerhalb des Adressobjekts statt (kein Mischen einzelner Adressfelder aus
beiden Ebenen). Auf allen anderen Seiten (ohne eigene
`LocalBusiness`-Konfiguration) bleibt weiterhin die vollständige globale
Version aktiv. Details zur Merge-Regel siehe
[configuration.md](configuration.md) und
[../README.md](../README.md#geltungsbereiche-und-vererbung).

## Siehe auch

- [../README.md](../README.md) — Nutzersicht auf `@id`-Anker, Verknüpfte Inhalte, Formularvalidierung
- [architecture.md](architecture.md) — Einordnung von `FormRenderer`/`JsonLdBuilder` im Gesamtsystem, Kontrollfluss
- [configuration.md](configuration.md) — feldweise Vererbung (`resolveTypeInheritance()`), auf der `buildJsonLdScript()` aufsetzt
- [schema-extending.md](schema-extending.md) — Widget-Typen und `ui:`-Properties, die dieses Rendering steuern
- [import.md](import.md) — wie importierte JSON-LD-Daten denselben Formular-Split durchlaufen
- [use-cases/](use-cases/) — Konfigurationsbeispiele je einzelnem Type
