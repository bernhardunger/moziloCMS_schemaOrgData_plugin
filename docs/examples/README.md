# Ausgabebeispiele

Vollständige JSON-LD-Ausgabebeispiele, die über die Kurzbeispiele in
[../use-cases/](../use-cases/) hinausgehen — insbesondere das Zusammenspiel
mehrerer gleichzeitig aktiver Geltungsbereiche, wie es real im
`<head>`-Bereich einer einzelnen Frontend-Seite ausgegeben wird. Beide
Beispiele bilden das tatsächliche Verhalten von
`SchemaOrgData_FrontendRenderer::renderFrontend()` ab (siehe
[../rendering.md](../rendering.md), [../configuration.md](../configuration.md)).

## Beispiel 1: Drei gleichzeitig aktive Geltungsbereiche

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
  siehe [../use-cases/organization-identity.md](../use-cases/organization-identity.md))
  — **Article** und **FAQPage** haben in ihrer Schema-Datei kein
  `ui:idFragment` und werden deshalb ohne `@id` ausgegeben.
- Der `Article`-Block ist **nicht** von `LocalBusiness` abhängig, obwohl
  `author.name` inhaltlich denselben Namen trägt — es gibt keine
  automatische Verknüpfung zwischen Kategorie- und Global-Scope-Daten
  außerhalb der expliziten `id_reference`/`id_reference_or_literal`-Widgets
  (siehe [../widgets.md](../widgets.md)). Eine inhaltliche Konsistenz
  zwischen den Ebenen liegt in der Verantwortung des Pflegenden.

## Beispiel 2: Type-Kollision mit feldweiser Vererbung

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
[../configuration.md](../configuration.md) und
[../README.md](../README.md#geltungsbereiche-und-vererbung).

## Siehe auch

- [../rendering.md](../rendering.md) — `buildJsonLdScript()`-Transformationsreihenfolge, `@id`-Mechanismus
- [../configuration.md](../configuration.md) — `resolveTypeInheritance()`/`mergeConfigs()` im Detail
- [../use-cases/](../use-cases/) — Konfigurationsbeispiele je einzelnem Type
- [../../README.md](../../README.md#json-ld-ausgabe-im-detail) — Kurzübersicht der Ausgabemechanik
