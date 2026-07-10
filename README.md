# schemaOrgData — moziloCMS Plugin

**Version:** 0.9.2-rc  
**Kompatibilität:** moziloCMS 3.0.4 oder höher  
**PHP:** 8.1+  
**Lizenz:** GPL-3.0  
**Sprache (Inline-Dokumentation):** Deutsch

---

## Überblick

**Strukturierte Daten ohne SEO-Agentur.** `schemaOrgData` macht die Inhalte
einer moziloCMS-Website für Suchmaschinen eindeutig interpretierbar: wer hinter
der Website steht (Unternehmen, Praxis, Kanzlei, Verein), was angeboten wird und
welche Seiten besondere Inhalte tragen — Veranstaltungen, Stellenanzeigen,
Spendenaufrufe, FAQ. Das erhöht die Chance auf **Rich Results** in der
Google-Suche und verbessert die maschinelle Auswertbarkeit der Website insgesamt.
Technisch geschieht das über validiertes, Schema.org-konformes **JSON-LD**, das
vollständig im Admin-Bereich über Formulare gepflegt wird: kein Eingriff in
Templates nötig (bis auf einen einmalig zu setzenden Platzhalter), keine
Code-Kenntnisse erforderlich, client- und server-seitige Validierung inklusive.

`schemaOrgData` ist ein Plugin für moziloCMS 3.0.4 oder höher, das **Schema.org-konformes JSON-LD** in den `<head>`-Bereich jeder Seite schreibt. Es ergänzt die im moziloCMS-Core bereits vorhandenen Microdata-Implementierungen (Article-Wrapper, ImageObject, BreadcrumbList, Contact) um maschinenlesbare JSON-LD-Blöcke, die von Suchmaschinen bevorzugt ausgewertet werden.

Das Plugin ist **vollständig eigenständig** und setzt kein anderes Plugin (z. B. `seo_urls`) voraus.

Das Plugin arbeitet **konfigurationsgetrieben**: Die strukturierten Daten werden im Admin-Bereich über Formulare gepflegt und nicht automatisch aus dem Seiteninhalt abgeleitet. Für die typische moziloCMS-Website (überschaubare Seitenzahl, ein Betreiber) ist das der passende Zuschnitt — volle Kontrolle über die Ausgabe, kein Mapping-Setup nötig.

---

## Features

- JSON-LD-Ausgabe im `<head>` der Seite, nicht im Seiteninhalt
- Drei Geltungsbereiche: **Global**, **Kategorie**, **Seite**
- Vererbungslogik: Global → Kategorie → Seite (spezifischere Ebene überschreibt feldweise, siehe „Type-Kollision")
- **Schema-getriebenes Formular**: JSON-Schema-Dateien definieren sowohl Validierungsregeln als auch Formularfelder — kein hardcodiertes PHP pro Type
- **@id-Anker und Knotenreferenzen**: Seiten-Typen (z. B. `DonateAction`, `Event`) verweisen per `@id` auf global definierte Identitätsknoten — inkl. Schutzmechanismen gegen doppelte und hängende Referenzen
- Generisches `PostalAddress`-Schema nach schema.org (international)
- Öffnungszeiten-Widget (inkl. optionalem zweitem Zeitraum je Wochentag, z. B. für Mittagspausen)
- **Erweiterungsfeld** (JSON-Textarea) für zusätzliche Properties mit Live-Validierung
- **Erkennung vorhandener JSON-LD-Blöcke** in Template und Seiteninhalt, wahlweise Beibehalten oder Überschreiben — plus **Import-Feld** zur Übernahme bestehender Daten ins Formular
- **Debug-Modus**: erzeugte JSON-LD-Blöcke im Frontend als Pop-up anzeigen (zum Abgleich mit validator.schema.org)
- Validierung via **AJV.js** gegen eigene JSON-Schema-Dateien (client-side) — [AJV.js](https://ajv.js.org) (Another JSON Validator) ist eine weit verbreitete JavaScript-Bibliothek zur Validierung von JSON-Daten gegen JSON-Schema-Definitionen; wird lokal ausgeliefert, kein CDN
- Eigenständige server-seitige Validierung und Absicherung beim Speichern (unabhängig von JavaScript)
- Mehrsprachige Labels via `$language->getLanguageValue()` und `$admin_lang->getLanguageValue()`
- Neuen Schema-Type hinzufügen = neue `.json`-Datei in `schemas/`, kein PHP nötig

---

## Unterstützte Schema-Types

| Type | Beschreibung | Geltungsbereich |
|---|---|---|
| `LocalBusiness` | Lokales Unternehmen | Global / Kategorie |
| `ProfessionalService` | Dienstleister | Global / Kategorie |
| `LegalService` | Anwaltskanzlei / Rechtsberatung (LocalBusiness-Subtyp) | Global / Kategorie |
| `MedicalBusiness` | Arztpraxis / medizinische Einrichtung (LocalBusiness-Subtyp) | Global / Kategorie |
| `AccountingService` | Steuerberatung / Buchhaltung | Global / Kategorie |
| `Organization` | Organisation / Firma, mit `@id`-Anker `#organization` | Global |
| `NGO` | Gemeinnützige Organisation (Verein, Stiftung u. a.), mit `@id`-Anker `#organization` | Global |
| `Person` | Einzelperson, mit `@id`-Anker `#person` | Global |
| `WebSite` | Website-Metadaten | Global |
| `FAQPage` | Häufig gestellte Fragen | Kategorie / Seite |
| `Article` | Artikel / Blogbeitrag | Kategorie / Seite |
| `JobPosting` | Stellenanzeige | Seite |
| `DonateAction` | Spendenaufruf (verknüpft per `@id` mit dem globalen Org-Knoten) | Seite |
| `Event` | Veranstaltung / Termin (`location` als `Place` mit Adresse, `organizer` wahlweise als Referenz oder Direkteingabe) | Seite |

---

## Best Practices: Schema.org-Daten sinnvoll pflegen

Das Plugin validiert die **Struktur** der eingegebenen Daten — ob die Daten
**inhaltlich** zur Seite passen, liegt in der Verantwortung des Betreibers.
Dafür gilt eine einfache Grundregel:

> **Strukturierte Daten müssen dem sichtbaren Seiteninhalt entsprechen.**
> Suchmaschinen (insbesondere Google) werten Markup, das Inhalte behauptet,
> die auf der Seite nicht sichtbar sind, als irreführend — im schlimmsten
> Fall führt das zum Ausschluss der gesamten Website von Rich Results.

### Empfehlungen

- **So wenig Types wie nötig.** Jeder konfigurierte Type sollte eine klare
  Entsprechung auf der Website haben. Mehr Markup ist nicht automatisch
  besseres Ranking.
- **Global nur die Identität.** Auf globaler Ebene gehört hin, wer hinter der
  Website steht (`Organization`, `NGO`, `Person` oder ein `LocalBusiness`-Typ)
  sowie ggf. `WebSite` — und zwar **genau eine** Organisations-Identität, nicht
  mehrere parallel.
- **Seiten-Types nur dort, wo der Inhalt es hergibt.** `Event`, `JobPosting`,
  `DonateAction`, `FAQPage` und `Article` gehören auf die Kategorie bzw. Seite,
  die den entsprechenden Inhalt tatsächlich sichtbar zeigt.
- **Nach jeder Änderung prüfen.** Debug-Modus aktivieren und das erzeugte
  JSON-LD mit [validator.schema.org](https://validator.schema.org) abgleichen;
  für die Google-Sicht zusätzlich der
  [Rich-Results-Test](https://search.google.com/test/rich-results).
- **Pflege einplanen.** Strukturierte Daten sind kein Einmal-Setup: Bei
  Inhaltsänderungen (Veranstaltung vorbei, Stelle besetzt, Öffnungszeiten
  geändert) muss die Konfiguration mitziehen.

### Typische Fehler — so bitte nicht

- ❌ **`FAQPage` ohne sichtbare Fragen und Antworten.** Die Fragen im Markup
  müssen wortgleich auf der Seite stehen — ein FAQ-Markup als reiner
  „SEO-Trick" auf einer normalen Inhaltsseite verstößt gegen die
  Google-Richtlinien.
- ❌ **Abgelaufene `Event`-Einträge stehen lassen.** Eine Veranstaltung von
  letztem Jahr im Markup signalisiert Suchmaschinen veraltete Daten. Nach dem
  Termin: Konfiguration der Seite entfernen oder aktualisieren.
- ❌ **`DonateAction` ohne tatsächliche Spendenmöglichkeit.** Der Spendenaufruf
  im Markup muss auf der Seite nachvollziehbar sein (Spendenformular,
  Bankverbindung, Spenden-Link).
- ❌ **Keyword-Stuffing im `name`-Feld.** „Zahnarzt München Zahnarztpraxis
  günstig Implantate" ist kein Name. Ins `name`-Feld gehört der tatsächliche
  Name — für Leistungen und Orte gibt es eigene Properties bzw. den sichtbaren
  Seiteninhalt.
- ❌ **Mehrere Organisations-Identitäten global parallel.** `Organization`,
  `NGO` und ein `LocalBusiness`-Typ gleichzeitig global zu konfigurieren
  erzeugt konkurrierende Aussagen darüber, wer die Website betreibt.
  Einen Typ wählen, der am besten passt — im Zweifel den spezifischsten
  (z. B. `NGO` statt `Organization` für einen e. V.).
- ❌ **Daten eintragen, „weil das Feld da ist".** Leere Felder sind kein
  Mangel — das Plugin tilgt sie automatisch aus der Ausgabe. Geschätzte oder
  erfundene Werte (Geo-Koordinaten, Gründungsdatum, Öffnungszeiten) schaden
  mehr als fehlende.

---

## Architektur

### Dateistruktur

```
plugins/schemaOrgData/
├── index.php                        # Plugin-Hauptklasse (Fassade)
├── plugin.conf.php                  # moziloCMS-Plugin-Metadaten
├── schemas/                         # JSON-Schema-Dateien (Validierung + Formular)
│   ├── AccountingService.json
│   ├── Article.json
│   ├── DonateAction.json
│   ├── Event.json
│   ├── FAQPage.json
│   ├── JobPosting.json
│   ├── LegalService.json
│   ├── LocalBusiness.json
│   ├── MedicalBusiness.json
│   ├── NGO.json
│   ├── Organization.json
│   ├── Person.json
│   ├── ProfessionalService.json
│   └── WebSite.json
├── js/
│   ├── ajv.min.js                   # AJV JSON-Schema-Validator (lokal, kein CDN)
│   └── validator.js                 # Plugin-eigene Validierungslogik (Live-Feedback)
├── lib/                             # Plugin-Komponenten (per require_once geladen,
│   │                                  je Datei mit eigenem IS_CMS-Guard)
│   ├── SchemaOrgData_UrlHelper.php            # Basis-URL-Ermittlung (Frontend/Admin)
│   ├── SchemaOrgData_LanguageService.php      # Sprachauflösung und Sprachdatei-Laden
│   ├── SchemaOrgData_SchemaRepository.php     # Schema-Dateien laden, $ref auflösen, Type-Liste
│   ├── SchemaOrgData_ScopeResolver.php        # Settings-Keys, Scope-Erkennung, Vererbung, Type-Kollision
│   ├── SchemaOrgData_JsonLdBuilder.php        # JSON-LD-Erzeugung inkl. @id-Einbettung und Leerfeld-Tilgung
│   ├── SchemaOrgData_IdReferenceService.php   # Globale @id-Fragmente auflösen, Dangling-Reference-Guard
│   ├── SchemaOrgData_CollisionDetector.php    # Erkennung vorhandener JSON-LD-Blöcke, Platzhalter-Prüfung
│   ├── SchemaOrgData_OpeningHoursHelper.php   # Öffnungszeiten parsen und in schema.org-Notation wandeln
│   ├── SchemaOrgData_DataSplitHelper.php      # Aufteilung Formulardaten / Erweiterungsdaten
│   ├── SchemaOrgData_Validator.php            # Server-seitige Feld- und Schema-Validierung
│   ├── SchemaOrgData_FormRenderer.php         # Formularfelder und Widgets rendern
│   ├── SchemaOrgData_ImportService.php        # Import vorhandener JSON-LD-Blöcke
│   ├── SchemaOrgData_AdminController.php      # Orchestrierung der Admin-Seite
│   ├── SchemaOrgData_AdminPageRenderer.php    # Hinweise, Selektoren, CSS der Admin-Seite
│   ├── SchemaOrgData_AdminRequestHandler.php  # POST-Verarbeitung im Admin
│   ├── SchemaOrgData_ConfigSaveService.php    # Sanitizing, Validierung und Speichern der Konfiguration
│   ├── SchemaOrgData_ValidationResult.php     # Ergebnis-Objekt der Validierungsphase
│   ├── SchemaOrgData_FrontendRenderer.php     # Frontend-Ausgabe inkl. Debug-Widget
│   ├── SchemaOrgData_FrontendRequestContext.php # Context-Objekt für die Frontend-Ausgabe
│   └── SchemaOrgData_AdminRequestContext.php  # Context-Objekt für die Admin-Seite
└── sprachen/
    ├── admin_language_deDE.txt
    ├── admin_language_enEN.txt
    ├── cms_language_deDE.txt
    └── cms_language_enEN.txt
```

Die Konfigurationsdaten werden **nicht** als Dateien im Plugin-Ordner abgelegt, sondern über die moziloCMS-eigene Settings-API (`$this->settings`) gespeichert — siehe „Geltungsbereiche und Vererbung".

### Geltungsbereiche und Vererbung

Das Plugin kennt drei Konfigurationsebenen. Die Konfiguration wird über die Settings-API des moziloCMS-Kerns unter einem ebenenspezifischen Schlüssel gespeichert:

```
config_global                     → wird auf jeder Seite ausgegeben
config_cat_{kategorie}            → wird zusätzlich auf allen Seiten der Kategorie ausgegeben
config_page_{kategorie}_{seite}   → wird zusätzlich nur auf dieser einzelnen Seite ausgegeben
```

Beim Seitenaufbau werden alle zutreffenden Ebenen geladen und zusammengeführt — die spezifischere Ebene überschreibt die allgemeinere (feldweise, siehe „Type-Kollision"). Die aktive Kategorie wird über `CAT_REQUEST`, die aktive Seite über `PAGE_REQUEST` ermittelt.

### Schema-getriebenes Formular

Jede JSON-Schema-Datei in `schemas/` beschreibt einen Schema-Type vollständig:

- **Validierungsregeln** (type, format, required, enum)
- **Formularfeld-Metadaten** via `ui:`-Properties (Widget-Typ, Placeholder, Pflichtfeld)
- **Sprachschlüssel** für Labels und Fehlermeldungen (werden zur Laufzeit aufgelöst)
- **Geltungsbereiche** des Types via `ui:scopes` (z. B. `["global", "category"]`)

Das Plugin liest beim Laden des Admin-Formulars die passende Schema-Datei und rendert daraus dynamisch die Formularfelder. Neuen Type unterstützen = neue `.json`-Datei ablegen, kein PHP anfassen.

**Beispiel-Schema (Auszug `LocalBusiness.json`):**

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "LocalBusiness",
  "ui:scopes": ["global", "category"],
  "required": ["name", "url"],
  "properties": {
    "name": {
      "type": "string",
      "minLength": 1,
      "ui:widget": "text",
      "ui:label": "label_name",
      "ui:required": true
    },
    "address": {
      "$ref": "#/definitions/PostalAddress"
    },
    "openingHours": {
      "type": "array",
      "ui:widget": "opening_hours",
      "ui:label": "label_opening_hours"
    }
  }
}
```

---

## Adressschema (PostalAddress)

Das Adressfeld folgt exakt `schema.org/PostalAddress` und ist international ausgelegt:

| Formularfeld | schema.org Property | Pflicht |
|---|---|---|
| Straße | `streetAddress` | nein |
| Postleitzahl | `postalCode` | nein |
| Ort | `addressLocality` | ja |
| Region / Bundesland | `addressRegion` | nein |
| Land | `addressCountry` | **ja** |

Das Feld **Land** wird als Select-Box mit Klarnamen dargestellt (z. B. „Deutschland"), intern wird der **ISO-3166-1-alpha-2-Code** gespeichert (z. B. `DE`). Standard-Vorauswahl: Deutschland (`DE`).

Die Länderliste ist in der zugehörigen JSON-Schema-Datei als `enum` definiert und dort pflegbar.

---

## Öffnungszeiten

Das Öffnungszeiten-Widget bildet die sieben Wochentage als Zeitraum-Felder ab (Von / Bis). Leere Felder werden als „geschlossen" interpretiert. Intern werden die Werte als `openingHours`-Array nach schema.org-Notation gespeichert, z. B.:

```json
"openingHours": ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
```

Je Wochentag steht zusätzlich ein **optionaler zweiter Zeitraum** (Pause) zur Verfügung. Beide Felder des zweiten Zeitraums müssen gemeinsam befüllt oder gemeinsam leer sein. Der zweite Zeitraum muss nach dem Ende des ersten beginnen (`from2 >= to`). Beispiel mit Mittagspause:

```json
"openingHours": ["Mo-Fr 08:00-12:00", "Mo-Fr 13:00-17:00"]
```

Der zweite Zeitraum ist niemals Pflicht — Organisationen ohne Pause bleiben beim Ein-Zeitraum-Modell.

Es wird ausschließlich das **24-Stunden-Format** (`HH:MM`) unterstützt — kein AM/PM. Ein entsprechender Hinweis wird im Widget angezeigt.

---

## Erweiterungsfeld (erweiterte Properties)

Jede Konfiguration enthält optional ein JSON-Textarea-Feld für Properties, die das Formular nicht abbildet. Die Inhalte werden beim Speichern mit den Formularfeldern zusammengeführt (merge). Das Formular hat Vorrang bei gleichnamigen Properties.

### Validierung

Die Validierung des Erweiterungsfelds erfolgt zweistufig:

**Client-side (live, AJV.js):**
1. JSON-Syntaxprüfung — Fehler mit Position werden sofort angezeigt
2. Property-Whitelist-Prüfung gegen das aktive JSON-Schema — unbekannte Properties werden mit Hinweis markiert (gelbe Warnung), aber nicht blockiert
3. Format-Prüfung bekannter Properties (z. B. URL-Format für `hasMap`, Wertebereich für `geo`-Koordinaten)

**Server-side (PHP, beim Speichern):**
- `json_decode()` — bei ungültigem JSON wird nicht gespeichert, Fehlermeldung wird zurückgegeben
- inhaltliche Prüfung bekannter Properties (z. B. `geo`-Koordinaten)

---

## Formularvalidierung

Alle Formularfelder werden zweistufig validiert: **live im Browser** (JavaScript/AJV) und **server-seitig in PHP** beim Speichern. Die server-seitige Prüfung ist eigenständig und greift auch, wenn JavaScript deaktiviert ist.

Das Feedback ist dreistufig: ✅ grün (OK) · ⚠️ gelb (Warnung) · ❌ rot (Fehler). Alle Fehlermeldungen kommen aus den Sprachdateien (`$admin_lang->getLanguageValue()`).

| Feld | Prüfung | Nur DE |
|---|---|---|
| `postalCode` | Regex `[0-9]{5}` | ja |
| `telephone` | Normalisierung + E.164-Prüfung | nein |
| `url`, `logo`, `hasMap`, `image` | URL-Format; `http://` ergibt HTTPS-Warnung (⚠️) | nein |
| `email` | E-Mail-Format | nein |
| `openingHours` | Format + Von-Zeit < Bis-Zeit (24-Stunden-Format) | nein |
| `addressCountry` | Enum-Prüfung gegen die Länderliste | nein |
| `geo` (Erweiterungsfeld) | numerisch + Wertebereich (Breite/Länge) | nein |
| Datumsfelder (`startDate`, `endDate`) | ISO-8601 (`YYYY-MM-DD`, optional mit Uhrzeit und Zeitzonen-Offset) **oder** deutsches Format `TT.MM.YYYY` (optional mit Uhrzeit `HH:MM`), kalendarische Gültigkeit; bei `Event` zusätzlich `endDate` nicht vor `startDate` (Vergleich über beide Formate hinweg) | nein |

**PLZ** — nur wenn `addressCountry = DE`: `/^[0-9]{5}$/`

**Telefon** — alle Länder (E.164 ist internationaler Standard): Eingabe wird normalisiert (alle Zeichen außer Ziffern und `+` entfernt), dann gegen E.164 geprüft: `/^(\+|00)[1-9][0-9]{6,14}$/`

**URL** — `http://` ergibt die Warnung „Für Produktivseiten wird HTTPS empfohlen", `https://` ist OK, eine ungültige URL ist ein Fehler.

**Datum** — eine gültige Eingabe im deutschen Format `TT.MM.YYYY`/`TT.MM.YYYY HH:MM` wird beim Speichern serverseitig auf ISO-8601 normalisiert (`YYYY-MM-DD` bzw. `YYYY-MM-DDTHH:MM:SS±HH:MM`, Offset aus der Server-Zeitzone aufgelöst); gespeichert und im JSON-LD ausgegeben wird ausschließlich der ISO-Wert.

---

## Sprachunterstützung

Das Plugin nutzt das moziloCMS-eigene Sprachsystem:

- **Admin-UI** (Formular-Labels, Fehlermeldungen, Buttons): `$admin_lang->getLanguageValue()`, Sprachdatei `admin_language_{locale}.txt` (z. B. `admin_language_deDE.txt`)
- **Frontend / CMS-Kontext** (z. B. Wochentag-Labels in openingHours): `$language->getLanguageValue()`, Sprachdatei `cms_language_{locale}.txt` (z. B. `cms_language_deDE.txt`)
- JSON-Schema-Dateien enthalten Sprachschlüssel (z. B. `"ui:label": "label_name"`), keine hartcodierten Strings

Initiale Sprachen: **Deutsch** (`deDE`), **Englisch** (`enEN`).

---

## JSON-LD-Ausgabe

Das Plugin gibt das JSON-LD als `<script>`-Tag im `<head>` aus — an der Stelle, an der im Layout-Template der Platzhalter `{schemaOrgData}` steht (siehe „Installation"):

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Muster GmbH",
  "url": "https://www.example.com",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Musterstraße 12",
    "postalCode": "12345",
    "addressLocality": "Musterstadt",
    "addressCountry": "DE"
  },
  "openingHours": ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
}
</script>
```

> **Hinweis:** Der Platzhalter `{schemaOrgData}` muss in `template.html`
> stehen, nicht in `gallerytemplate.html`. Galerie-Vollansichten haben
> keine eigene Kategorie-/Seiten-Identität — das Plugin erkennt diesen
> Fall und gibt dort ohnehin kein JSON-LD aus. Der Admin-Bereich prüft
> beim Platzhalter-Hinweis ausschließlich `template.html`; ein
> Platzhalter, der nur in `gallerytemplate.html` steht, gilt daher
> korrekt als fehlend.

> **Tipp:** Das erzeugte JSON-LD kann mit dem offiziellen Schema.org-Validator geprüft werden:  
> 🔗 [https://validator.schema.org](https://validator.schema.org)

Pro Geltungsbereich wird ein eigener `<script>`-Block ausgegeben (Global + Kategorie + Seite = bis zu drei Blöcke). Types die ausschließlich auf Globalebene sinnvoll sind (`WebSite`, `Organization`) werden nur einmal ausgegeben. Leere Felder werden vor der Ausgabe entfernt; vollständig leere Knoten werden gar nicht ausgegeben.

### Debug-Modus

In der globalen Konfiguration kann ein **Debug-Modus** aktiviert werden. Er blendet im Frontend einen kleinen Button ein, der alle auf der aktuellen Seite erzeugten JSON-LD-Blöcke als Pop-up anzeigt — zum Kopieren und manuellen Abgleich mit [validator.schema.org](https://validator.schema.org). Nicht für den Produktivbetrieb gedacht.

---

## @id-Anker (stabile Knoten-Identität)

Ausgewählte Schema-Types erhalten zusätzlich eine stabile `@id` — eine URI, die
den Knoten innerhalb des Datengraphen eindeutig identifiziert. Erweiterungen
(z. B. eine Spendenaktion oder Veranstaltung) können so per `@id`
auf den global definierten Organisationsknoten verweisen, ohne den
Organisationsblock auf jeder Seite zu wiederholen.

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "NGO",
  "@id": "https://www.example.org/#organization",
  "name": "Beispiel-Hilfe e. V.",
  "url": "https://www.example.org",
  "nonprofitStatus": "DERegisteredAssociationCharity"
}
</script>
```

**Generisch und schema-getrieben.** Ob und unter welchem URI-Fragment ein Type
eine `@id` bekommt, wird ausschließlich in der jeweiligen Schema-Datei über die
Property `ui:idFragment` festgelegt — es gibt keine Type-Namen im PHP-Code.
Aktuell deklarieren `NGO`, `Organization` sowie die LocalBusiness-Familie
(`LocalBusiness`, `ProfessionalService`, `LegalService`,
`MedicalBusiness`, `AccountingService`) gemeinsam das Fragment
`organization` (geteiltes Fragment für unterschiedliche
Org-Identitätstypen) und `Person` das Fragment `person`. Pro Seite trägt **genau ein** Knoten ein
gegebenes Fragment — sind auf derselben Seite z. B. sowohl `NGO` als auch
`Organization` global konfiguriert, erhält nur der in Ausgabereihenfolge
erste Knoten die `@id` (De-Dup-Guard, siehe unten). Schema-Dateien ohne
`ui:idFragment` erhalten unverändert keine `@id`.

**Basis-URL.** Die absolute Basis-URL wird zur Ausgabezeit aus dem aktuellen
Request abgeleitet (Protokoll + Host + Pfad), analog zur kanonischen URL des
moziloCMS-Kerns. Das Plugin besitzt **kein** eigenes Domain-Setting; damit die
`@id` stabil und eindeutig bleibt, sollte die Installation per `.htaccess` auf
einen kanonischen Host umleiten (z. B. 301 auf den `www`-Host und auf HTTPS).
Lässt sich kein Host ermitteln, wird **keine** (leere) `@id` ausgegeben.

**De-Dup-Guard.** Würden mehrere ausgegebene Knoten dasselbe Fragment tragen,
erhält nur der erste in Ausgabereihenfolge die `@id`; die übrigen bleiben ohne
Anker. Eine einmal gesetzte `@id` wird nie still entfernt — sie steht immer
direkt hinter `@type` im Output.

---

### Widget `id_reference` und Dangling-Reference-Guard

Das Widget `id_reference` ermöglicht es Seiten-Typen (z. B. `DonateAction`), per
`@id` auf den global definierten Organisationsknoten zu verweisen, ohne ihn auf
jeder Seite zu wiederholen. Die Deklaration erfolgt ausschließlich im Schema:

```json
"recipient": {
  "ui:widget": "id_reference",
  "ui:idTarget": "organization",
  "ui:required": true
}
```

Zur Ausgabezeit fügt das Plugin dafür automatisch `{"@id": "<Basis-URL>#organization"}`
ein — kein Eingabefeld, kein gespeicherter Wert. Im Formular wird die aufgelöste
Ziel-URI als schreibgeschützte Info angezeigt.

**Ausgabe-Beispiel (`DonateAction` auf einer Spenden-Seite, `NGO` global):**

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

**Dangling-Reference-Guard.** Verweist eine `id_reference` auf einen Knoten, der
auf dieser Seite nicht ausgegeben würde (z. B. weil die Kategorie in der
Ausschlussliste steht), erzwingt das Plugin automatisch einen Minimal-Stub des
Zielknotens (`@type`, `@id`, `name`) — damit der Graph stets valide bleibt.
Der Stub enthält nur den nötigsten Identifikator, keinen vollständigen Datensatz.

Ausnahme: Ist für den globalen Scope „Vorhandenes JSON-LD beibehalten" (keep)
aktiv, hat dieser ausdrückliche Nutzerwunsch Vorrang — in diesem Fall wird die
`id_reference` **nicht** emittiert (kein Dangling-`@id` gegen den Nutzerwillen).

> **Künftige Optionen (noch nicht umgesetzt):** ein optionales manuelles
> Basis-URL-/Domain-Setting (für Reverse-Proxy-/CDN-Szenarien) sowie die
> Darstellung einer Entität mit mehreren Typen über ein `@type`-Array.

---

### Person-Fragment (`#person`)

`schemas/Person.json` erhält analog zu `NGO` einen eigenen `@id`-Anker mit dem
Fragment `"person"`. Damit können Seiten-Typen (z. B. `Event.organizer`) auf eine
global definierte Person verweisen:

```html
<!-- Global: Person als Identitätsanker -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Person",
  "@id": "https://www.example.org/#person",
  "name": "Max Mustermann",
  "jobTitle": "Vorstand"
}
</script>
```

`Person` ist ausschließlich auf dem **Global-Scope** verfügbar (kein Kategorie-Scope) —
analog zu `NGO`. De-Dup-Guard und Dangling-Reference-Guard greifen unabhängig von
`"organization"`: die Fragmente `#person` und `#organization` sind getrennte Anker.

---

### Widget `id_reference_or_literal` (selektierbare Verknüpfung)

Für Properties, bei denen der Wert wahlweise ein bekannter globaler Knoten
**oder** eine reine Literal-Angabe sein soll (z. B. `Event.organizer`), steht das
Widget `id_reference_or_literal` zur Verfügung.

Der Nutzer wählt im Formular zwischen zwei Modi:

**a) Referenz-Modus — Verknüpfen mit globalem Knoten**

Das Dropdown listet automatisch alle aktuell im Global-Scope konfigurierten Typen,
die ein `ui:idFragment` besitzen (z. B. NGO, Person). Gespeichert werden
`_mode: "reference"` und `_fragment: "<fragment>"`. Zur Ausgabezeit emittiert das
Plugin `{"@id": "<Basis-URL>#<fragment>"}` — exakt wie `id_reference`. Der
Dangling-Reference-Guard greift für Referenz-Modus wie gewohnt.

```json
{
  "@type": "Event",
  "name": "Jahreshauptversammlung",
  "organizer": { "@id": "https://www.example.org/#person" }
}
```

**b) Literal-Modus — Manuell eintragen**

Einfache Textfelder (definiert per `ui:literalFields` im Schema). Das Plugin emittiert
ein eingebettetes Objekt mit optionalem `@type` (aus `ui:literalType`) — **ohne** `@id`,
kein Dangling-Guard-Bezug.

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

Bestehende `id_reference`-Properties (z. B. `DonateAction.recipient`) bleiben
unverändert — das Widget ist additiv, kein Breaking Change.

---

## Steuerung der globalen Ausgabe

Die globale Konfiguration (Settings-Key `config_global`) wird standardmäßig auf jeder Seite ausgegeben. Dieses Verhalten kann gezielt eingeschränkt werden.

### Ausschlussliste

Im Admin-Bereich kann der Nutzer Kategorien definieren, auf denen die globale Ausgabe **nicht** erfolgt — z. B. Impressum, Datenschutz, Sitemap:

```
Globale Ausgabe deaktivieren für:
[ ] impressum
[ ] datenschutz
[ ] sitemap
```

Die Ausschlussliste unterdrückt ausschließlich die **globale** Konfiguration auf den betroffenen Kategorien. Eine eigenständige Konfiguration der Kategorie oder ihrer Seiten wird davon nicht berührt.

### Type-Kollision / feldweise Vererbung

Ist für eine Kategorie oder Seite derselbe Schema-Type wie auf einer übergeordneten Ebene hinterlegt, werden die Felder zusammengeführt (Global → Kategorie → Seite): leere bzw. fehlende Felder der spezifischeren Ebene übernehmen den Wert der übergeordneten Ebene, gefüllte Felder überschreiben ihn. Bei verschachtelten Feldern (z. B. `address`, `openingHours`) gewinnt die Ebene mit dem gefüllten Objekt vollständig — es erfolgt kein Merge innerhalb des Objekts. Die Ausgabe erfolgt einmalig auf der spezifischsten Ebene, auf der der Type konfiguriert ist.

Beispiel: `LocalBusiness` global mit `name = "Beispiel GmbH"` und `telephone = "+49 89 123456"`, dieselbe Konfiguration auf Kategorie `kontakt` mit `name = "Beispiel GmbH - Filiale Nord"` → auf Seiten der Kategorie `kontakt` wird `name = "Beispiel GmbH - Filiale Nord"` und `telephone = "+49 89 123456"` (von global geerbt) ausgegeben.

Verschiedene Types bleiben unabhängig voneinander.

### Info-Block im Admin

Der Admin-Bereich zeigt oberhalb der Konfigurationsfelder einen allgemein verständlichen Info-Block der das Ausgabeverhalten für den aktuellen Geltungsbereich erklärt — ohne dass der Nutzer die Dokumentation lesen muss:

> **ℹ️ Wie funktioniert die Ausgabe?**
>
> Strukturierte Daten werden als unsichtbarer JSON-LD-Block im Seitenkopf (`<head>`) ausgegeben — für Besucher nicht sichtbar, aber von Suchmaschinen wie Google ausgewertet.
>
> Es gelten folgende Prioritäten:
> - **Global** — wird auf allen Seiten ausgegeben, sofern nicht ausgeschlossen
> - **Kategorie** — gilt für alle Seiten dieser Kategorie; bei gleichem Type überschreibt sie die globale Ausgabe
> - **Seite** — gilt nur für diese Seite; bei gleichem Type überschreibt sie Kategorie und Global
>
> Tipp: Das erzeugte JSON-LD kann unter [https://validator.schema.org](https://validator.schema.org) geprüft werden.

---

## Verhalten bei vorhandenem JSON-LD

Erkennt das Plugin beim Seitenaufbau ein bereits vorhandenes `<script type="application/ld+json">` im Template oder Seiteninhalt, wird dem Benutzer im Admin-Bereich ein Hinweis angezeigt.

Die Erkennung erfolgt **scope-genau**:

- Ein Block im **Layout-Template** ist layoutweit gültig — der Hinweis erscheint ausschließlich im **Global-Scope**.
- Ein Block im **Seiteninhalt** (direkt in der Seite hinterlegt) ist seitenspezifisch — der Hinweis erscheint ausschließlich im **Seiten-Scope** der betreffenden Seite.
- Im **Kategorie-Scope** wird dieser Hinweis grundsätzlich nicht automatisch angezeigt — Kategorie-Ebene ist kein eigenständiges Signal für Template- oder Inhalts-Treffer.

> ⚠️ **Auf dieser Seite wurde bereits ein JSON-LD-Block gefunden.**
> Bitte wähle wie das Plugin vorgehen soll:
>
> - **Vorhandenes beibehalten** — das Plugin gibt kein eigenes JSON-LD aus, solange ein externer Block erkannt wird
> - **Mit Plugin-Konfiguration überschreiben** — das Plugin gibt sein eigenes JSON-LD aus; der vorhandene Block bleibt im Template/Inhalt und muss manuell entfernt werden
>
> ⚠️ Es erfolgt **kein automatischer Merge** beider Strukturen.

Die Einstellung wird pro Geltungsbereich (Global / Kategorie / Seite) gespeichert und kann jederzeit geändert werden.

**Empfohlene Vorgehensweise bei Migration:**
1. Vorhandenes JSON-LD in das Import-Feld kopieren (siehe unten)
2. Felder im Plugin-Formular prüfen und anpassen
3. Manuell den alten JSON-LD-Block aus Template / Seiteninhalt entfernen
4. Auf „Mit Plugin-Konfiguration überschreiben" umstellen

### Import vorhandener JSON-LD-Daten

Im Admin-Bereich steht ein **Import-Feld** zur Verfügung. Ein bestehender JSON-LD-Block kann dort eingefügt werden — das Plugin parst den Block und befüllt automatisch die bekannten Formularfelder. Properties die das Formular nicht abbildet, werden automatisch ins Erweiterungsfeld übernommen.

Wurde ein JSON-LD-Block im Template oder Seiteninhalt erkannt, erscheint oberhalb des Import-Felds ein **„Erkannten Block übernehmen"**-Button. Ein Klick überträgt den erkannten Block direkt ins Import-Feld — ohne sofortiges Speichern. Anschließend kann der Import über den „Importieren"-Button ausgelöst werden.

> ⚠️ Wurden mehrere JSON-LD-Blöcke erkannt, wird dieser Button durch einen erklärenden Hinweistext ersetzt — der passende Block muss dann manuell aus der Vorschau kopiert werden, da eine automatische Übernahme kein gültiges Einzel-JSON ergäbe.

> ⚠️ Der Import überschreibt die aktuelle Formularkonfiguration. Es erfolgt kein Merge.

---

## Sicherheit

- **Kein Direktzugriff**: Jede PHP-Datei des Plugins (inkl. aller `lib/`-Komponenten) prüft die moziloCMS-Konstante `IS_CMS` und bricht bei Direktaufruf ab.
- **Settings-Key-Härtung**: Kategorie- und Seitenbezeichner werden vor der Verwendung in Settings-Keys bereinigt (`sanitizeScopeIdentifier()`) — Schutz vor Path-Traversal und unerwünschten Zeichen in Schlüsselnamen.
- **Eingabe-Sanitizing**: Alle Formularwerte werden beim Speichern getrimmt und von HTML-Tags befreit; Telefonnummern werden normalisiert.
- **Script-Breakout-Schutz**: Die JSON-LD-Ausgabe erfolgt mit `JSON_HEX_TAG` — in Feldwerten enthaltene `<`/`>`-Zeichen können den umgebenden `<script>`-Block nicht aufbrechen. Dasselbe gilt für die an das Admin-JavaScript übergebenen Meldungstexte.
- **Server-seitige Validierung ist maßgeblich**: Die client-seitige AJV-Validierung ist Komfort; gespeichert wird nur, was die PHP-Validierung besteht.
- **Kein CDN**: AJV.js wird lokal ausgeliefert — keine externen Skript-Quellen.

---

## Tests

Das Plugin verwendet **PHPUnit 11.x** für Unit-Tests. Da moziloCMS kein eigenes Test-Framework mitbringt, werden CMS-Abhängigkeiten (Konstanten, Basisklassen) im Test-Bootstrap gemockt.

```bash
composer install
./vendor/bin/phpunit
```

Das Plugin ist umfassend automatisiert getestet (PHPUnit + Jest), inkl. einiger dokumentierter Skips für strukturell im Unit-Test nicht erreichbare Fälle. Ergänzend wird das Plugin per Browser-Regressionstests (Playwright) gegen eine reale moziloCMS-Installation verifiziert.

> `vendor/` ist in `.gitignore` — PHPUnit wird nicht ins Repository eingecheckt. Die Tests liegen im Entwicklungs-Repository eine Ebene über dem Plugin-Ordner und sind nicht Teil des Deployment-Pakets.

---

## Installation

1. Ordner `schemaOrgData` in `plugins/` hochladen
2. Im moziloCMS-Admin unter **Plugins** aktivieren
3. **Wichtig:** Den Platzhalter `{schemaOrgData}` an passender Stelle im `<head>`-Bereich des aktiven Layout-Templates (`template.html`) ergänzen — **ohne diesen Platzhalter gibt das Plugin im Frontend keinerlei JSON-LD aus**, unabhängig von der Konfiguration. Fehlt der Platzhalter, zeigt der Admin-Bereich einen entsprechenden Hinweis an. In `gallerytemplate.html` sollte der Platzhalter nicht gesetzt werden (siehe „JSON-LD-Ausgabe").
4. Konfiguration unter **Plugins → schemaOrgData** vornehmen

---

## Kompatibilität und Abhängigkeiten

- moziloCMS 3.0.4 oder höher
- PHP 8.1+
- Kein weiteres Plugin erforderlich
- AJV.js wird lokal ausgeliefert (kein externes CDN)
- Kompatibel mit `seo_urls`-Plugin (optional): kanonische URLs aus `seo_urls` können manuell als `url`-Property eingetragen werden

---

## Abgrenzung zu bestehenden Core-Implementierungen

moziloCMS 3.0 enthält bereits folgende Schema.org-Implementierungen als Microdata:

| Bereich | Core-Implementierung | Dieses Plugin |
|---|---|---|
| Seiteninhalt | `Article` (Wrapper, minimal) | `Article` als JSON-LD im `<head>` |
| Bilder | `ImageObject` via `itemprop` | — |
| Breadcrumb | `BreadcrumbList` via Microdata | — |
| Kontakt | `LocalBusiness` via Microdata im Body | `LocalBusiness` als JSON-LD im `<head>` |

Dieses Plugin **ersetzt** die Core-Microdata nicht, sondern **ergänzt** sie um JSON-LD im `<head>`, das von Google und anderen Suchmaschinen für Rich Results bevorzugt ausgewertet wird.
