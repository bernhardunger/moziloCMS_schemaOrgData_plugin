# Use Case: JobPosting

`JobPosting` bildet eine Stellenanzeige auf **Seiten-Ebene** ab.

## Felder im Überblick

| Feld | schema.org Property | Widget / Format |
|---|---|---|
| Titel | `title` | Textfeld |
| Beschreibung | `description` | Textfeld |
| Veröffentlichungsdatum | `datePosted` | deutsches Datumsformat, siehe [Formularvalidierung](../validation.md) |
| Ablaufdatum | `validThrough` | wie `datePosted` |
| Beschäftigungsart | `employmentType` | Auswahl (z. B. `FULL_TIME`, `PART_TIME`) |
| Arbeitgeber | `hiringOrganization` | Widget `id_reference_or_literal`, siehe [widgets.md](../widgets.md) |
| Einsatzort | `jobLocation` | Place-Widget, analog zu `Event.location` |

## Arbeitgeber (`hiringOrganization`)

`hiringOrganization` nutzt wie `Event.organizer` das Widget
`id_reference_or_literal`: entweder Referenz auf den global konfigurierten
Organisationsknoten (`NGO`, `Organization`, ein `LocalBusiness`-Typ) oder
Direkteingabe, falls die ausschreibende Stelle nicht mit der global
gepflegten Identität übereinstimmt (z. B. bei einer Tochtergesellschaft).

## Einsatzort (`jobLocation`)

`jobLocation` ist wie `Event.location` ein Place-Widget mit
`PostalAddress` (siehe
[Adressschema](../../README.md#adressschema-postaladdress)).

## Vollständiges Beispiel

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "JobPosting",
  "title": "Steuerfachangestellte / Steuerfachangestellter (m/w/d)",
  "description": "Zur Verstärkung unseres Teams suchen wir zum nächstmöglichen Zeitpunkt …",
  "datePosted": "2026-07-01",
  "validThrough": "2026-09-30",
  "employmentType": "FULL_TIME",
  "hiringOrganization": { "@id": "https://www.beispiel-kanzlei.de/#organization" },
  "jobLocation": {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "Musterstraße 12",
      "postalCode": "80331",
      "addressLocality": "München",
      "addressCountry": "DE"
    }
  }
}
</script>
```

## Typischer Fehler: besetzte Stellen stehen lassen

Analog zu abgelaufenen `Event`-Einträgen (siehe
[Typische Fehler](../common-mistakes.md)) sollte eine besetzte Stelle
zeitnah aus der Konfiguration entfernt oder das Feld `validThrough`
konsequent gepflegt werden — sonst signalisiert das Markup dauerhaft eine
nicht mehr existierende Vakanz.

## Siehe auch

- [../../README.md](../../README.md#use-case-jobposting) — Kurzübersicht
- [../widgets.md](../widgets.md) — Mechanik von `id_reference_or_literal`
- [organization-identity.md](organization-identity.md) — Organisationsknoten für `hiringOrganization`
- [../validation.md](../validation.md) — Datumsvalidierung
