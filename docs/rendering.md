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

`renderTypeFields()` rendert alle Felder eines Types für eine
Geltungsebene: bekannte Properties werden anhand des Schemas als
Formularfelder ausgegeben, unbekannte Properties landen im
Erweiterungsfeld (`SchemaOrgData_DataSplitHelper::splitDataForRendering()`).
`renderField()` verzweigt je Property anhand von `ui:widget` — von
einfachen Text-/Select-Feldern bis zu zusammengesetzten Widgets wie
`postal_address`, `opening_hours`, `faq_list`, `geo`, `place` und den
beiden `@id`-Verweis-Widgets (`id_reference`, `id_reference_or_literal`,
siehe [widgets.md](widgets.md)). Welches Widget welche `ui:`-Property
erwartet, ist in [schema-extending.md](schema-extending.md) referenzdokumentiert.

Für Kategorie-/Seiten-Sektionen zeigt das Formular zusätzlich an, welcher
Wert von einer allgemeineren Ebene geerbt würde: Ist ein Feld auf der
aktuellen Ebene leer, erscheint der geerbte Wert als Placeholder mit einem
Badge, das die Ursprungsebene nennt — rein zur Anzeige, er wird nicht ins
Feld übernommen oder gespeichert (die Vererbung selbst greift zur
Laufzeit über `resolveTypeInheritance()`, siehe
[configuration.md](configuration.md)).

## JSON-LD-Erzeugung (`SchemaOrgData_JsonLdBuilder`)

`buildJsonLdScript()` übernimmt die zusammengeführten Properties eines
Types (bereits feldweise über die Geltungsebenen vererbt, siehe
[configuration.md](configuration.md)) und baut daraus den fertigen
`<script>`-Block: HTML-Entity-Dekodierung, Entfernen leerer Properties,
verschachtelte Typisierung (`address` → `PostalAddress`, `geo` →
`GeoCoordinates`, `location`/`jobLocation` → `Place`), Auflösung der
`id_reference`/`id_reference_or_literal`-Widgets zu ihrem tatsächlichen
`@id`-Wert, und zuletzt die Kodierung per `json_encode()` mit
`JSON_HEX_TAG`, das `<`/`>` in Feldwerten als Unicode-Escapes kodiert und
damit verhindert, dass ein Feldwert wie `</script><script>…` aus dem
umgebenden `<script>`-Block ausbricht (Stored-XSS-Schutz, siehe auch
[../README.md](../README.md), Abschnitt „Sicherheit").

Ausgabe: `<script type="application/ld+json">\n{...}\n</script>\n`.

<a id="id-mechanismus"></a>
### `@id`-Mechanismus im Detail

`ui:idFragment` in der Schema-Datei deklariert einen Type als
potenziellen `@id`-Träger (rein schema-getrieben — im PHP-Code steht kein
Type-Name). Die Basis-URL wird zur Ausgabezeit aus dem aktuellen Request
abgeleitet (`SchemaOrgData_UrlHelper::resolveBaseUrl()`, kein eigenes
Domain-Setting); lässt sich kein Host ermitteln, entfällt die `@id`.

**De-Dup-Guard:** Wird derselbe Type (oder zwei Types mit demselben
Fragment, z. B. teilen sich **LocalBusiness** und **Organization** das
Fragment `"organization"`) auf mehreren Geltungsebenen gleichzeitig
ausgegeben, erhält nur der in Ausgabereihenfolge erste Knoten tatsächlich
eine `@id` — unabhängig davon, auf welcher Ebene er steht. Das verhindert
zwei Knoten mit identischer `@id` im selben Graph.

### `@id`-Referenzen zur Build-Zeit

`id_reference`- und `id_reference_or_literal`-Properties haben **keinen**
für sich stehenden gespeicherten Endwert — ihre JSON-LD-Repräsentation
(`{"@id": "<Basis-URL>#<Fragment>"}` im Referenz-Modus, ein eingebettetes
Objekt ohne `@id` im Literal-Modus) entsteht ausschließlich zur
Ausgabezeit in `buildJsonLdScript()`. Details zu den beiden Widgets stehen
in [widgets.md](widgets.md).

### Dangling-Reference-Guard

Verweist ein aktives `id_reference`/`id_reference_or_literal`-Feld auf ein
`@id`-Fragment, das im finalen Graph gar nicht ausgegeben wird (z. B. weil
die globale Ebene per `excluded_cats` auf der aktuellen Kategorie
ausgeblendet ist), würde ohne Gegenmaßnahme eine hängende `@id`-Referenz
ins Nichts entstehen. Der Guard (`SchemaOrgData_IdReferenceService::
applyDanglingReferenceGuard()`) fängt das ab: Ist die globale Ebene
stattdessen durch `jsonld_mode = 'keep'` unterdrückt, unterbleibt die
Referenz-Emission ganz (der ausdrückliche Nutzerwunsch hat Vorrang vor
einem funktionierenden Verweis). Andernfalls wird ein Minimal-Stub des
Zielknotens (nur `name`, `@type`, `@id`) synthetisch ergänzt, damit die
Referenz trotzdem auf einen existierenden Knoten zeigt. Ist derselbe
Fragment-Name bereits durch einen anderen aktiv ausgegebenen Knoten
gedeckt, bleibt der Guard ein No-op.

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
