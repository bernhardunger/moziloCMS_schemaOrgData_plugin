# Schema-Referenz: `ui:*`-Properties

Vollständige Referenz aller `ui:`-Schlüssel, die in `schemas/*.json`
tatsächlich vorkommen. Für eine Einführung mit Beispielen und
Schritt-für-Schritt-Anleitung zum Anlegen eines neuen Types siehe
[../schema-extending.md](../schema-extending.md) — diese Seite dupliziert
diese Anleitung nicht, sondern dient als schnelles Nachschlagewerk.

Ermittelt direkt aus den Schema-Dateien unter `plugins/schemaOrgData/schemas/`
(Stand: 14 Types). Ein `ui:`-Schlüssel gehört zu genau einer von zwei
Ebenen: **Type-Ebene** (Top-Level im Schema-Objekt, einmal je Datei) oder
**Property-Ebene** (innerhalb einer einzelnen `properties.<feld>`-Definition).

## Type-Ebene

| Schlüssel | Pflicht | Bedeutung | Vorkommen |
|---|---|---|---|
| `ui:typeLabel` | ja | Sprachschlüssel für den Anzeigenamen im Type-Dropdown (`renderTypeSelector()`) | alle 14 Types |
| `ui:scopes` | ja | Array aus `"global"`, `"category"`, `"page"` — auf welchen Geltungsebenen der Type wählbar ist | alle 14 Types |
| `ui:family` | nein | gemeinsamer Familienschlüssel; ist auf Global bereits ein Type derselben Familie konfiguriert, filtert `SchemaOrgData_AdminController::renderScopeSection()` die übrigen Familienmitglieder aus dem Dropdown von Kategorie/Seite, `SchemaOrgData_ConfigSaveService::saveConfig()` weist eine Manipulation serverseitig zurück | `AccountingService`, `LegalService`, `LocalBusiness`, `MedicalBusiness`, `ProfessionalService` (Wert jeweils `"localBusiness"`) |
| `ui:idFragment` | nein | URI-Fragment für einen `@id`-Anker (siehe [../rendering.md](../rendering.md#id-mechanismus)) | `AccountingService`, `LegalService`, `LocalBusiness`, `MedicalBusiness`, `ProfessionalService`, `NGO`, `Organization` — alle mit dem Wert `"organization"`. Personen-Fragmente (`person-{slug}`) stehen in keiner Schema-Datei, sie entstehen dynamisch aus der Personen-Registry |

## Property-Ebene

| Schlüssel | Bedeutung | Genutzt von Widget(s) |
|---|---|---|
| `ui:widget` | Widget-Typ (siehe Tabelle unten); fehlt er, rendert `renderField()` ein einfaches Textfeld | alle |
| `ui:label` | Sprachschlüssel für das Feld-Label | alle |
| `ui:required` | Pflichtfeld-Badge, Live-Validierung, serverseitige Prüfung; muss zum Vorkommen der Property in der Top-Level-`required`-Liste passen (siehe `SchemaConsistencyTest`, [../tests.md](../tests.md)) | alle |
| `ui:placeholder` | Platzhaltertext im Eingabefeld, literal im Schema hinterlegt | `text`, `textarea` |
| `ui:placeholderKey` | Sprachschlüssel für den Platzhaltertext statt Literaltext — Alternative zu `ui:placeholder`, in Gebrauch an den Datumsfeldern (`placeholder_date`, `placeholder_date_time`) | `text` |
| `ui:enumLabels` | `{ "deDE": { wert: label, … }, "enEN": { … } }` — sprachabhängige Anzeigenamen für `enum`-Werte | `select` |
| `ui:days` | Array der sieben Wochentags-Kürzel in Anzeigereihenfolge (`["Mo", "Tu", …, "Su"]`) | `opening_hours` |
| `ui:dayLabelKeys` | Sprachschlüssel je Wochentags-Kürzel | `opening_hours` |
| `ui:emitAs` | verlagert den Wert bei der Emission in einen verschachtelten Knoten, statt ihn direkt als Property auszugeben; Objekt aus `property` (Ziel-Property am Type-Knoten), `wrapperType` (`@type` des Zwischenknotens), `as` (Property darin) und `itemType` (`@type` je Eintrag) — in Gebrauch für `openingHours`, ausgegeben als `location` → `Place` → `openingHoursSpecification` → `OpeningHoursSpecification` | `opening_hours` |
| `ui:idTarget` | Fragment-Name des Zielknotens (muss einem `ui:idFragment` eines global konfigurierbaren Types entsprechen) | `id_reference` |
| `ui:literalFields` | Liste der im Literal-Modus angebotenen Textfelder | `id_reference_or_literal` |
| `ui:literalFieldPlaceholders` | `{ "<feldname>": "<sprachschluessel>" }` — Placeholder-Text je Literal-Feld | `id_reference_or_literal` |
| `ui:literalType` | `@type`-Wert des eingebetteten Literal-Objekts (z. B. `"Organization"`) | `id_reference_or_literal` |
| `ui:referenceTargets` | schränkt die im Referenz-Modus angebotenen Ziele ein; ohne den Schlüssel steht die volle Auswahl offen, `["persons"]` filtert sie auf die aktiven Registry-Personen | `id_reference_or_literal` |
| `ui:allowLiteral` | `false` schaltet den Literal-Modus ab — das Feld wird zur reinen Referenz ohne Modus-Umschalter | `id_reference_or_literal` |

`ui:literalFieldLabels` (analog zu `ui:literalFieldPlaceholders`, aber für
eigene Feld-Labels statt des Default `label_<feldname>`) ist in
[../schema-extending.md](../schema-extending.md) als unterstützter
Schlüssel dokumentiert, kommt aber in keiner aktuellen `schemas/*.json`-Datei
zum Einsatz. Vier Properties nutzen `id_reference_or_literal`:
`Article.author`, `Event.organizer` und `JobPosting.hiringOrganization`
bieten im Literal-Modus jeweils nur `name` an und greifen dafür auf den
Default-Label-Schlüssel `label_name` zurück; bei `ProfilePage.mainEntity`
ist der Literal-Modus über `ui:allowLiteral: false` ganz abgeschaltet.

## Widget-Typen und ihre `ui:`-Attribute

| `ui:widget` | Relevante `ui:`-Attribute (zusätzlich zu `ui:label`/`ui:required`/`ui:placeholder`) | Speicherform |
|---|---|---|
| `text` (Default) | — | Skalarer String |
| `textarea` | — | Skalarer String |
| `select` | `ui:enumLabels` (Alternative: `enum` ohne Labels — dann werden die Rohwerte selbst angezeigt) | Skalarer String |
| `postal_address` | — (Sub-Felder fest: `streetAddress`, `postalCode`, `addressLocality`, `addressRegion`, `addressCountry`) | Objekt `{streetAddress, postalCode, addressLocality, addressRegion, addressCountry}` |
| `geo` | — (Sub-Felder fest: `latitude`, `longitude`) | Objekt `{latitude, longitude}` (numerisch) |
| `opening_hours` | `ui:days`, `ui:dayLabelKeys` | Array in schema.org-Notation, z. B. `["Mo-Fr 09:00-18:00"]` |
| `faq_list` | — | Array `[{name, acceptedAnswer: {text}}, …]` |
| `place` | — (Namensfeld + verschachtelte `postal_address`-Gruppe unter `address`) | Objekt `{name, address: {…}}` |
| `id_reference` | `ui:idTarget` | kein POST-Wert — Emission erst zur Build-Zeit in `buildJsonLdScript()` |
| `id_reference_or_literal` | `ui:literalFields`, `ui:literalFieldPlaceholders`, `ui:literalType`, `ui:referenceTargets`, `ui:allowLiteral` | Objekt `{_mode: "reference", _fragment: "…"}` oder `{_mode: "literal", <literalFields>…}` |

`postal_address` wird in der Praxis nie direkt definiert, sondern per
`"$ref": "#/definitions/PostalAddress"` aus einem lokalen
`definitions.PostalAddress`-Block referenziert — dieser Block muss über
alle Types, die ihn führen, strukturell identisch bleiben
(`SchemaConsistencyTest`, siehe [../tests.md](../tests.md)).

## Siehe auch

- [../schema-extending.md](../schema-extending.md) — Einführung, Aufbau einer Schema-Datei, Minimalbeispiel für einen neuen Type
- [../rendering.md](../rendering.md) — wie `ui:widget` zu Formularfeld und JSON-LD-Ausgabe wird
- [../widgets.md](../widgets.md) — `id_reference`/`id_reference_or_literal` im Detail (Mechanik, Referenz-/Literal-Modus)
- [../../README.md](../../README.md#unterstuetzte-schema-types) — Liste der unterstützten Types
