# Use Case: Event

`Event` bildet eine Veranstaltung oder einen Termin auf **Seiten-Ebene**
ab — z. B. Jahreshauptversammlung, Vortrag, Messeauftritt, Konzert.

## Felder im Überblick

| Feld | schema.org Property | Widget / Format |
|---|---|---|
| Name | `name` | Textfeld |
| Beschreibung | `description` | Textbereich |
| Beginn | `startDate` | deutsches Datumsformat `TT.MM.YYYY HH:MM`, siehe [Formularvalidierung](../validation.md) |
| Ende | `endDate` | wie `startDate`, muss nach `startDate` liegen |
| Teilnahmeform | `eventAttendanceMode` | Auswahlliste: vor Ort, online, hybrid — optional |
| Status | `eventStatus` | Auswahlliste: wie geplant, abgesagt, verschoben, ins Internet verlegt — optional |
| Ort | `location` | verschachteltes `Place`-Objekt mit `PostalAddress` |
| Veranstalter | `organizer` | Widget `id_reference_or_literal`, siehe [widgets.md](../widgets.md) |

## Ort (`location`)

`location` wird als verschachteltes `Place`-Objekt mit
`PostalAddress` abgebildet (siehe
[Adressschema](../../README.md#adressschema-postaladdress)):

```json
"location": {
  "@type": "Place",
  "name": "Vereinsheim Beispiel e. V.",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Am Marktplatz 3",
    "postalCode": "94249",
    "addressLocality": "Bodenmais",
    "addressCountry": "DE"
  }
}
```

## Veranstalter (`organizer`)

`organizer` nutzt das Widget `id_reference_or_literal`. Im
Referenz-Modus bietet das Dropdown die global konfigurierte
Organisations-Identität (**NGO**, **Organization** oder ein
**LocalBusiness**-Typ) sowie jede aktive Person der Personen-Registry an.
Der Literal-Modus stellt ein einzelnes Namensfeld bereit; der eingetragene
Wert wird als eingebettetes **Organization**-Objekt ausgegeben. Details zu
beiden Modi stehen in [widgets.md](../widgets.md).

## Vollständiges Beispiel

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Jahreshauptversammlung 2026",
  "description": "Ordentliche Mitgliederversammlung mit Vorstandswahl.",
  "startDate": "2026-09-12T19:00:00+02:00",
  "endDate": "2026-09-12T21:30:00+02:00",
  "location": {
    "@type": "Place",
    "name": "Vereinsheim Beispiel e. V.",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "Am Marktplatz 3",
      "postalCode": "94249",
      "addressLocality": "Bodenmais",
      "addressCountry": "DE"
    }
  },
  "organizer": { "@id": "https://www.example.org/#person-maria-beispiel" }
}
</script>
```

Datumsfelder werden im deutschen Format eingegeben und beim Speichern auf
ISO-8601 normalisiert (siehe [Formularvalidierung](../validation.md)) —
der Offset in der Ausgabe (`+02:00` im Beispiel) stammt aus der
Server-Zeitzone.

## Typischer Fehler: veraltete Events

Ein `Event` mit vergangenem `startDate`/`endDate` bleibt im Markup, wenn
die Seitenkonfiguration nach der Veranstaltung nicht entfernt oder
aktualisiert wird — Suchmaschinen werten das als veraltetes Signal. Siehe
[Best Practices](../best-practices.md#veraltete-events).

## Siehe auch

- [../../README.md](../../README.md#use-cases-und-beispiele) — Use Cases im Überblick
- [../widgets.md](../widgets.md) — Mechanik von `id_reference_or_literal`
- [organization-identity.md](organization-identity.md) — Person-/Org-Fragmente für `organizer`
- [../validation.md](../validation.md) — Datumsvalidierung
