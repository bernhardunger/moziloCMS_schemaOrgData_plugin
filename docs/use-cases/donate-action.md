# Use Case: DonateAction

`DonateAction` bildet einen Spendenaufruf auf **Seiten-Ebene** ab und
verweist per `id_reference` (`recipient`) auf den global definierten
Organisationsknoten — siehe [widgets.md](../widgets.md) für Mechanismus
und Dangling-Reference-Guard.

## Felder im Überblick

| Feld | schema.org Property | Widget / Format |
|---|---|---|
| Beschreibung | `description` | Textfeld |
| Empfänger | `recipient` | Widget `id_reference`, Ziel: `#organization` |

`recipient` ist im Formular keine editierbare Eingabe, sondern zeigt
schreibgeschützt die aufgelöste Ziel-URI des global konfigurierten
Organisationsknotens an (`NGO`, `Organization` oder ein
`LocalBusiness`-Typ) — siehe
[Organisations-Identität und @id-Anker](organization-identity.md).

## Vollständiges Beispiel

```html
<!-- Auf jeder Seite (Global): -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "NGO",
  "@id": "https://www.example.org/#organization",
  "name": "Beispiel-Hilfe e. V."
}
</script>

<!-- Nur auf der Spenden-Seite (Seite): -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "DonateAction",
  "description": "Jetzt spenden und helfen — jeder Beitrag zählt!",
  "recipient": { "@id": "https://www.example.org/#organization" }
}
</script>
```

## Voraussetzung: globale Organisations-Identität

`DonateAction` ohne global konfigurierten Organisationsknoten führt zum
Dangling-Reference-Guard: Das Plugin erzwingt dann einen Minimal-Stub
(`@type`, `@id`, `name`) für den Empfänger, damit der Graph valide bleibt
— besser ist es, vorab tatsächlich eine Organisations-Identität auf
Global-Ebene zu konfigurieren (siehe [Erste Konfiguration](../../README.md#erste-konfiguration)).

## Typischer Fehler: Spendenaufruf ohne echte Möglichkeit

Der Spendenaufruf im Markup muss auf der Seite nachvollziehbar sein
(Spendenformular, Bankverbindung, Spenden-Link) — ein `DonateAction` ohne
sichtbare Entsprechung auf der Seite widerspricht derselben Grundregel wie
bei `FAQPage` (siehe [Best Practices](../best-practices.md)).

## Siehe auch

- [../../README.md](../../README.md#use-case-donateaction) — Kurzübersicht
- [../widgets.md](../widgets.md) — Mechanik von `id_reference`, Dangling-Reference-Guard
- [organization-identity.md](organization-identity.md) — Organisationsknoten und `@id`-Fragmente
- [../common-mistakes.md](../common-mistakes.md) — Spendenaufruf ohne echte Spendenmöglichkeit
