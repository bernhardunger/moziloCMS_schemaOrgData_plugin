# Widgets: `id_reference` und `id_reference_or_literal`

Für Properties, die auf einen global definierten Knoten verweisen sollen
(z. B. `DonateAction.recipient`, `Event.organizer`,
`JobPosting.hiringOrganization`), stehen zwei Formular-Widgets zur
Verfügung. Beide werden ausschließlich über Properties in der jeweiligen
Schema-Datei deklariert — es gibt keine Widget-Logik, die an konkrete
Type-Namen im PHP-Code gebunden ist.

## Widget `id_reference`

Verweist zwingend auf einen festen Zielknoten. Kein Eingabefeld, kein
gespeicherter Wert — im Formular wird lediglich die aufgelöste Ziel-URI als
schreibgeschützte Info angezeigt.

**Schema-Deklaration:**

```json
"recipient": {
  "ui:widget": "id_reference",
  "ui:idTarget": "organization",
  "ui:required": true
}
```

`ui:idTarget` referenziert das Fragment (siehe
[use-cases/organization-identity.md](use-cases/organization-identity.md)),
nicht einen konkreten Type — welcher Type das Fragment aktuell trägt
(**NGO**, **Organization**, ein **LocalBusiness**-Typ …), ist für das Widget
irrelevant.

**Ausgabe-Beispiel** (**DonateAction** auf einer Spenden-Seite, **NGO**
global):

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
  "description": "Jetzt spenden und helfen!",
  "recipient": { "@id": "https://www.example.org/#organization" }
}
</script>
```

Zur Ausgabezeit fügt das Plugin dafür automatisch
`{"@id": "<Basis-URL>#organization"}` ein.

## Widget `id_reference_or_literal` (selektierbare Verknüpfung)

Für Properties, bei denen der Wert wahlweise ein bekannter globaler Knoten
**oder** eine reine Literal-Angabe sein soll (z. B. `Event.organizer`),
steht dieses Widget zur Verfügung. Der Nutzer wählt im Formular zwischen
zwei Modi.

**Schema-Deklaration:**

```json
"organizer": {
  "type": "object",
  "ui:widget": "id_reference_or_literal",
  "ui:label": "label_organizer",
  "ui:literalFields": ["name", "jobTitle"],
  "ui:literalFieldLabels": { "name": "label_name", "jobTitle": "label_job_title" },
  "ui:literalType": "Person",
  "ui:required": true
}
```

### a) Referenz-Modus — Verknüpfen mit globalem Knoten

Das Dropdown listet automatisch alle aktuell im Global-Scope
konfigurierten Typen, die ein `ui:idFragment` besitzen (z. B. **NGO**,
**Person**). Gespeichert werden `_mode: "reference"` und
`_fragment: "<fragment>"`. Zur Ausgabezeit emittiert das Plugin
`{"@id": "<Basis-URL>#<fragment>"}` — exakt wie `id_reference`. Der
Dangling-Reference-Guard (siehe unten) greift für den Referenz-Modus wie
gewohnt.

```json
{
  "@type": "Event",
  "name": "Jahreshauptversammlung",
  "organizer": { "@id": "https://www.example.org/#person" }
}
```

### b) Literal-Modus — Manuell eintragen

Einfache Textfelder (definiert per `ui:literalFields` im Schema). Das
Plugin emittiert ein eingebettetes Objekt mit optionalem `@type` (aus
`ui:literalType`) — **ohne** `@id`, kein Dangling-Guard-Bezug.

```json
{
  "@type": "Event",
  "name": "Jahreshauptversammlung",
  "organizer": {
    "@type": "Person",
    "name": "Max Mustermann",
    "jobTitle": "Vorstand"
  }
}
```

Der zweite Modus ist sinnvoll, wenn der Organisator/Ansprechpartner nicht
dauerhaft global gepflegt werden soll (z. B. wechselnde externe
Veranstalter).

## Dangling-Reference-Guard

Verweist eine `id_reference` (oder eine `id_reference_or_literal` im
Referenz-Modus) auf einen Knoten, der auf dieser Seite nicht ausgegeben
würde — z. B. weil die Kategorie in der Ausschlussliste steht (siehe
[../README.md](../README.md#geltungsbereiche-und-vererbung)) — erzwingt
das Plugin automatisch einen Minimal-Stub des Zielknotens (`@type`, `@id`,
`name`), damit der Graph stets valide bleibt. Der Stub enthält nur den
nötigsten Identifikator, keinen vollständigen Datensatz.

**Ausnahme:** Ist für den globalen Scope „Vorhandenes JSON-LD beibehalten"
(`keep`) aktiv (siehe [import.md](import.md)), hat dieser ausdrückliche
Nutzerwunsch Vorrang — in diesem Fall wird die `id_reference` **nicht**
emittiert. Es soll kein hängendes `@id` gegen den Nutzerwillen ausgegeben
werden.

## Neue Widgets für weitere Referenz-Properties

Ein neues Property mit `id_reference` oder `id_reference_or_literal` zu
versehen erfordert **keine** PHP-Änderung — es genügt die entsprechende
`ui:widget`-Deklaration in der Schema-Datei des jeweiligen Types (siehe
[schema-extending.md](schema-extending.md)). Voraussetzung ist lediglich,
dass ein Zielfragment (`ui:idTarget` bzw. die im Referenz-Modus
angebotenen Fragmente) tatsächlich von mindestens einem Type mit
`ui:idFragment` bedient wird.

## Siehe auch

- [../README.md](../README.md#verknuepfte-inhalte) — Kurzübersicht
- [use-cases/organization-identity.md](use-cases/organization-identity.md) — `@id`-Anker, De-Dup-Guard, Fragmente
- [use-cases/donate-action.md](use-cases/donate-action.md), [use-cases/event.md](use-cases/event.md), [use-cases/job-posting.md](use-cases/job-posting.md) — konkrete Verwendung
- [rendering.md](rendering.md) — Formular-Rendering und JSON-LD-Erzeugung im Gesamtzusammenhang
- [schema-extending.md](schema-extending.md) — Widget-Deklaration beim Anlegen neuer Schema-Types
