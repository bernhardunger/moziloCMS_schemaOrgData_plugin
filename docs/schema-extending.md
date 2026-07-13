# Neuen Schema-Type hinzufügen

Ein neuer Schema.org-Type kommt ausschließlich als `.json`-Datei in
`schemas/` hinzu — es ist keine PHP-Änderung nötig. Die Datei definiert
gleichzeitig die serverseitige Validierung (`SchemaOrgData_Validator`,
`SchemaOrgData_ConfigSaveService::saveConfig()`), die clientseitige
AJV-Validierung (`js/validator.js`) und die Formularfelder
(`SchemaOrgData_FormRenderer`). `SchemaOrgData_SchemaRepository::
getAvailableSchemaTypes()` listet jede `.json`-Datei im Verzeichnis
automatisch als verfügbaren Type auf; welche Geltungsbereiche sie anbietet,
steuert ausschließlich `ui:scopes` innerhalb der Datei.

## Aufbau einer Schema-Datei

Eine Schema-Datei ist regulärer JSON-Schema-Draft-07-Text (`$schema`,
`$id`, `type`, `required`, `properties`, `definitions`) plus Plugin-eigene
`ui:`-Properties, die das Standard-JSON-Schema um Formular- und
Anzeige-Metadaten ergänzen. Top-Level-Schlüssel eines Schema-Types:

| Schlüssel | Pflicht | Bedeutung |
|---|---|---|
| `title` | ja | Schema.org-Type-Name, wird 1:1 als `@type` im JSON-LD ausgegeben |
| `ui:typeLabel` | ja | Sprachschlüssel für den Anzeigenamen im Type-Dropdown (`renderTypeSelector()`), z. B. `"schema_type_localbusiness"` |
| `ui:scopes` | ja | Array aus `"global"`, `"category"`, `"page"` — auf welchen Geltungsebenen der Type wählbar ist |
| `ui:family` | nein | gemeinsamer Familienschlüssel (z. B. `"localBusiness"`); ist auf Global bereits ein Type derselben Familie konfiguriert, filtert `SchemaOrgData_AdminController::renderScopeSection()` die übrigen Familienmitglieder aus dem Dropdown von Kategorie/Seite und `SchemaOrgData_ConfigSaveService::saveConfig()` weist eine Manipulation serverseitig zurück (`error_family_type_mismatch`) |
| `ui:idFragment` | nein | URI-Fragment für einen `@id`-Anker (z. B. `"organization"`); siehe [rendering.md](rendering.md#id-mechanismus) |
| `required` | nein | Standard-JSON-Schema-`required`-Liste; muss 1:1 zu `ui:required: true` der jeweiligen Property passen (wird in Tests projektweit geprüft) |
| `properties` | ja | Feld-Definitionen, siehe unten |
| `definitions` | nein | wiederverwendbare Teilschemas, typischerweise `definitions.PostalAddress` (siehe unten) |

## Aufbau einer Property

Jede Property in `properties` kombiniert Standard-JSON-Schema-Attribute
(`type`, `format`, `enum`, `minLength`, `minItems`) mit `ui:`-Attributen:

| `ui:`-Attribut | Bedeutung |
|---|---|
| `ui:widget` | Widget-Typ, siehe Tabelle unten. Fehlt es, rendert `renderField()` ein einfaches Textfeld (`text`). |
| `ui:label` | Sprachschlüssel für das Feld-Label (`$admin_lang->getLanguageValue()`) |
| `ui:required` | `true`/`false` — steuert Pflichtfeld-Badge, Live-Validierung und serverseitige Prüfung. Muss zum Vorkommen der Property in der Top-Level-`required`-Liste passen. |
| `ui:placeholder` | Platzhaltertext im Eingabefeld |
| `ui:options` | flache Liste erlaubter Werte für `select` (Alternative zu `enum`) |
| `ui:enumLabels` | `{ "deDE": { wert: label, … }, "enEN": { … } }` — sprachabhängige Anzeigenamen für `enum`-Werte |
| `ui:days` / `ui:dayLabelKeys` | nur `opening_hours`-Widget, siehe unten |
| `ui:literalFields` / `ui:literalFieldLabels` / `ui:literalFieldPlaceholders` / `ui:literalType` | nur `id_reference_or_literal`-Widget, siehe unten |
| `ui:idTarget` | nur `id_reference`-Widget, siehe unten |

`format: "uri"`, `format: "email"` und `format: "date-time"` lösen in
`SchemaOrgData_Validator::validateFormData()`/`renderFieldFeedback()`
automatisch die passende Validierung aus, ohne dass der Property-Name
bekannt sein muss — Ausnahme ist das Feld `telephone`, das anhand seines
Namens erkannt wird (`SchemaOrgData_Validator::validateTelephone()`).

## Verfügbare Widget-Typen

Ermittelt aus `SchemaOrgData_FormRenderer::renderField()` (das `match` über
`ui:widget`) und den tatsächlich verwendeten Werten in `schemas/*.json`:

| `ui:widget` | Rendert | Speicherform |
|---|---|---|
| `text` (Default) | `<input type="text">` | Skalarer String |
| `textarea` | `<textarea>` | Skalarer String |
| `select` | `<select>`, Optionen aus `enum`+`ui:enumLabels` oder `ui:options` | Skalarer String |
| `postal_address` | `PostalAddress`-Feldgruppe (Straße, PLZ, Ort, Region, Land) | Objekt `{streetAddress, postalCode, addressLocality, addressRegion, addressCountry}` |
| `geo` | `GeoCoordinates`-Feldpaar (Breite/Länge), Paar-Pflicht „beides oder nichts" | Objekt `{latitude, longitude}` (numerisch) |
| `opening_hours` | Von/Bis-Zeittabelle je Wochentag inkl. optionalem zweiten Zeitraum | Array in schema.org-Notation, z. B. `["Mo-Fr 09:00-18:00"]` |
| `faq_list` | wiederholbare Frage/Antwort-Zeilen plus eine leere Anlege-Zeile | Array `[{name, acceptedAnswer: {text}}, …]` |
| `place` | Namensfeld + verschachtelte `postal_address`-Gruppe unter `address` | Objekt `{name, address: {…}}` |
| `id_reference` | schreibgeschützte Info-Anzeige der aufgelösten `@id`-URI, kein Eingabefeld | kein POST-Wert — wird erst zur Build-Zeit emittiert (siehe [rendering.md](rendering.md)) |
| `id_reference_or_literal` | Radio-Auswahl Referenz (Dropdown globaler `@id`-Knoten) vs. Literal (Textfelder gemäß `ui:literalFields`) | Objekt `{_mode: "reference", _fragment: "…"}` oder `{_mode: "literal", <literalFields>…}` |

`postal_address` wird üblicherweise nicht direkt definiert, sondern per
`"$ref": "#/definitions/PostalAddress"` aus einem lokalen
`definitions.PostalAddress`-Block referenziert (siehe unten,
`SchemaOrgData_SchemaRepository::resolveSchemaRef()` löst `$ref` zur
Laufzeit auf). `SchemaConsistencyTest` (siehe [development.md](development.md))
prüft, dass dieser Block über alle Types, die ihn führen, strukturell
identisch bleibt — beim Kopieren aus einem bestehenden Schema also den
Block unverändert übernehmen.

## `id_reference` und `id_reference_or_literal` im Detail

```json
"recipient": {
  "type": "object",
  "ui:widget": "id_reference",
  "ui:label": "label_recipient",
  "ui:idTarget": "organization",
  "ui:required": true
}
```

`ui:idTarget` benennt das `ui:idFragment` eines global konfigurierbaren
Types (z. B. `Organization`, `NGO`). Zur Ausgabezeit fügt
`SchemaOrgData_JsonLdBuilder::buildJsonLdScript()` automatisch
`{"@id": "<Basis-URL>#organization"}` als Wert ein.

```json
"organizer": {
  "type": "object",
  "ui:widget": "id_reference_or_literal",
  "ui:label": "label_organizer",
  "ui:literalFields": ["name"],
  "ui:literalFieldPlaceholders": { "name": "placeholder_organizer_name" },
  "ui:literalType": "Organization"
}
```

`ui:literalFields` listet die im Literal-Modus angebotenen Textfelder,
`ui:literalFieldLabels`/`ui:literalFieldPlaceholders` ordnen ihnen optional
eigene Sprachschlüssel zu (Default: `label_<feldname>`), `ui:literalType`
liefert das `@type` des eingebetteten Literal-Objekts. Das Dropdown im
Referenz-Modus füllt sich automatisch aus allen global konfigurierten
Types mit `ui:idFragment` (`SchemaOrgData_IdReferenceService::
resolveAvailableGlobalFragments()`) — auch hier ist keine PHP-Änderung
nötig, ein neuer Type mit `ui:idFragment` erscheint dort automatisch.

## `@id`-Anker (`ui:idFragment`)

```json
{ "title": "Organization", "ui:idFragment": "organization", … }
```

Deklariert einen Type als potenziellen `@id`-Träger. Mehrere Types können
sich dasselbe Fragment teilen (z. B. `LocalBusiness` und `Organization`
teilen sich `"organization"` als unterschiedliche Ausprägungen derselben
Identität) — pro Seite erhält dann nur der erste in Ausgabereihenfolge
ausgegebene Knoten tatsächlich eine `@id` (De-Dup-Guard, siehe
[rendering.md](rendering.md)).

## Sprachschlüssel-Konvention

Jeder über `ui:label`, `ui:typeLabel`, `ui:literalFieldLabels` etc.
referenzierte Sprachschlüssel muss in **beiden** Admin-Sprachdateien
(`sprachen/admin_language_deDE.txt`, `sprachen/admin_language_enEN.txt`)
vorhanden sein — Format `schluessel = Wert`, siehe
[development.md](development.md). Für einen neuen Type sind das
mindestens:

- `schema_type_<lowercase-typename>` (Wert von `ui:typeLabel`) — erscheint im Type-Dropdown
- je Property ein `label_<feldname>` (sofern kein bereits vorhandener Schlüssel wiederverwendet wird, z. B. `label_name`, `label_description`)
- bei `enum`+`ui:enumLabels`: die dort referenzierten Werte
- bei eigenen Validierungsfehlern: die passenden `error_*`-Schlüssel (bestehende Validatoren wie `validateUrl()`/`validateEmail()` bringen ihre Meldungen bereits mit)

## Vollständiges Minimalbeispiel

Ein neuer, einfacher Type `Product` auf Seiten-Ebene mit Name (Pflicht),
Beschreibung und Preis-Angabe als URL zu einem Angebot:

`schemas/Product.json`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "https://schema.org/Product",
  "title": "Product",
  "type": "object",
  "ui:typeLabel": "schema_type_product",
  "ui:scopes": ["page"],
  "required": ["name"],
  "properties": {
    "name": {
      "type": "string",
      "minLength": 1,
      "ui:widget": "text",
      "ui:label": "label_name",
      "ui:required": true
    },
    "description": {
      "type": "string",
      "ui:widget": "textarea",
      "ui:label": "label_description",
      "ui:required": false
    },
    "url": {
      "type": "string",
      "format": "uri",
      "ui:widget": "text",
      "ui:label": "label_url",
      "ui:placeholder": "https://www.beispiel.de/produkt",
      "ui:required": false
    }
  }
}
```

Ergänzend in beiden `sprachen/admin_language_*.txt`:

```
schema_type_product = Produkt
```

(`label_name`, `label_description` und `label_url` existieren bereits,
da sie von anderen Types mitverwendet werden.) Damit ist der Type
vollständig nutzbar: Er erscheint auf Seiten-Ebene im Type-Dropdown, das
Formular wird automatisch gerendert, Validierung (Pflichtfeld `name`,
URL-Format für `url`) läuft client- und serverseitig, und
`buildJsonLdScript()` gibt bei ausgefüllten Feldern z. B.

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Musterprodukt",
  "url": "https://www.beispiel.de/produkt"
}
```

aus.

## Siehe auch

- [../README.md](../README.md) — Feature-Überblick, insbesondere „Widget-Deklaration im Schema (Beispiele)"
- [rendering.md](rendering.md) — wie `ui:widget` zu Formularfeld und JSON-LD-Ausgabe wird, `@id`-Mechanismus im Detail
- [architecture.md](architecture.md) — Rolle von `SchemaOrgData_SchemaRepository`/`SchemaOrgData_FormRenderer` im Gesamtsystem
- [development.md](development.md) — Sprachdatei-Format, `SchemaConsistencyTest`
