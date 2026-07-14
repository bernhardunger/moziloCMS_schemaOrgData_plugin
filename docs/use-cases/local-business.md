# Use Case: Lokales Unternehmen

Für Unternehmen mit physischer Adresse — Ladengeschäft, Praxis, Kanzlei,
Beratung — steht die `LocalBusiness`-Familie zur Verfügung:
**LocalBusiness** (generisch), **ProfessionalService** (Dienstleister),
**LegalService** (Anwaltskanzlei / Rechtsberatung), **MedicalBusiness**
(Arztpraxis / medizinische Einrichtung) und **AccountingService**
(Steuerberatung / Buchhaltung).

## Welchen Type wählen?

Innerhalb der Familie sollte der **spezifischste passende Type** gewählt
werden, nicht das generische `LocalBusiness` — siehe auch
[Best Practices](../best-practices.md#globaler-scope): je genauer der Type,
desto eindeutiger kann Google die Website einordnen.

| Betrieb | Empfohlener Type |
|---|---|
| Steuerkanzlei, Buchhaltungsbüro | `AccountingService` |
| Anwaltskanzlei, Rechtsberatung | `LegalService` |
| Arztpraxis, Physiotherapie, medizinische Einrichtung | `MedicalBusiness` |
| Beratung, Agentur, sonstige Dienstleistung | `ProfessionalService` |
| Alles andere mit physischer Adresse | `LocalBusiness` |

Die Familie ist als `ui:family: "localBusiness"` im jeweiligen Schema
deklariert (siehe [../schema-extending.md](../schema-extending.md)) und
damit gegenseitig exklusiv: Auf Global-Ebene ist nur **ein** Mitglied der
Familie gleichzeitig konfigurierbar.

## Geltungsbereich

`LocalBusiness` und ihre Subtypen sind auf **Global** oder **Kategorie**
konfigurierbar — nicht auf Seiten-Ebene. Typische Anwendung:

- **Global**, wenn es genau einen Standort / eine Praxis gibt
- **Kategorie**, wenn mehrere Standorte oder Fachbereiche über eigene
  Kategorien abgebildet werden (z. B. `/kanzlei-muenchen/`,
  `/kanzlei-augsburg/`) und jede ihre eigene Adresse/Telefonnummer trägt

Bei Kategorie-Konfiguration gilt die feldweise Vererbung aus
[Geltungsbereiche und Vererbung](../../README.md#geltungsbereiche-und-vererbung):
Felder, die auf Kategorie-Ebene leer bleiben, werden von der globalen
Konfiguration übernommen.

## Beispiel: Steuerkanzlei

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "AccountingService",
  "@id": "https://www.beispiel-kanzlei.de/#organization",
  "name": "Steuerkanzlei Beispiel",
  "url": "https://www.beispiel-kanzlei.de",
  "telephone": "+49 89 1234567",
  "email": "kanzlei@beispiel-kanzlei.de",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Musterstraße 12",
    "postalCode": "80331",
    "addressLocality": "München",
    "addressRegion": "Bayern",
    "addressCountry": "DE"
  },
  "openingHours": ["Mo-Do 08:00-12:00", "Mo-Do 13:00-17:00", "Fr 08:00-13:00"]
}
</script>
```

Details zu Adressfeldern und Öffnungszeiten stehen in der README unter
[Adressschema (PostalAddress)](../../README.md#adressschema-postaladdress)
und [Öffnungszeiten](../../README.md#oeffnungszeiten).

## Kombination mit anderen Types

Eine `AccountingService`-Konfiguration auf Global schließt weitere,
seitenspezifische Types nicht aus — z. B. kann eine einzelne Seite
zusätzlich **FAQPage** oder **JobPosting** tragen (siehe
[FAQPage](faq.md), [JobPosting](job-posting.md)). Die
Organisations-Identität aus `AccountingService` (bzw. dem gewählten
Familienmitglied) kann dabei per `@id` von anderen Seiten-Types
referenziert werden — siehe
[Organisations-Identität und @id-Anker](organization-identity.md).

## Siehe auch

- [../../README.md](../../README.md#lokales-unternehmen) — Kurzübersicht
- [organization-identity.md](organization-identity.md) — `@id`-Anker und Referenzierung
- [../validation.md](../validation.md) — Feldvalidierung (Telefonnummer, PLZ, Land)
- [../best-practices.md](../best-practices.md) — Auswahl des passenden Types
