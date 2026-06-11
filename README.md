# schemaOrgData — moziloCMS Plugin

**Version:** 1.0.0 (geplant)  
**Kompatibilität:** moziloCMS 3.0.4 oder höher  
**PHP:** 8.1+  
**Lizenz:** GPL-3.0  
**Sprache (Inline-Dokumentation):** Deutsch

---

## Überblick

`schemaOrgData` ist ein Plugin für moziloCMS 3.0.4 oder höher, das **Schema.org-konformes JSON-LD** in den `<head>`-Bereich jeder Seite schreibt. Es ergänzt die im moziloCMS-Core bereits vorhandenen Microdata-Implementierungen (Article-Wrapper, ImageObject, BreadcrumbList, Contact) um maschinenlesbare JSON-LD-Blöcke, die von Suchmaschinen bevorzugt ausgewertet werden.

Das Plugin ist **vollständig eigenständig** und setzt kein anderes Plugin (z. B. `seo_urls`) voraus.

---

## Features

- JSON-LD-Ausgabe im `<head>` der Seite, nicht im Seiteninhalt
- Drei Geltungsbereiche: **Global**, **Kategorie**, **Seite**
- Vererbungslogik: Global → Kategorie → Seite (spezifischere Ebene überschreibt)
- **Schema-getriebenes Formular**: JSON-Schema-Dateien definieren sowohl Validierungsregeln als auch Formularfelder — kein hardcodiertes PHP pro Type
- Generisches `PostalAddress`-Schema nach schema.org (international)
- Öffnungszeiten-Widget
- **Erweiterungsfeld** (JSON-Textarea) für zusätzliche Properties mit Live-Validierung
- Validierung via **AJV.js** gegen eigene JSON-Schema-Dateien (client-side) — [AJV.js](https://ajv.js.org) (Another JSON Validator) ist eine weit verbreitete JavaScript-Bibliothek zur Validierung von JSON-Daten gegen JSON-Schema-Definitionen; wird lokal ausgeliefert, kein CDN
- Serverside-Absicherung via `json_decode()` vor dem Speichern
- Mehrsprachige Labels via `$language->getLanguageValue()` und `$admin_lang->getLanguageValue()`
- Neuen Schema-Type hinzufügen = neue `.json`-Datei in `schemas/`, kein PHP nötig

---

## Unterstützte Schema-Types (initial)

| Type | Beschreibung | Geltungsbereich |
|---|---|---|
| `LocalBusiness` | Lokales Unternehmen | Global / Kategorie |
| `ProfessionalService` | Dienstleister (z. B. Anwaltskanzlei, Arztpraxis) | Global / Kategorie |
| `Organization` | Organisation / Firma | Global |
| `Person` | Einzelperson | Global / Kategorie |
| `WebSite` | Website-Metadaten | Global |
| `FAQPage` | Häufig gestellte Fragen | Kategorie / Seite |

---

## Architektur

### Dateistruktur

```
plugins/schemaOrgData/
├── index.php                        # Plugin-Hauptklasse
├── schemas/                         # JSON-Schema-Dateien (Validierung + Formular)
│   ├── LocalBusiness.json
│   ├── ProfessionalService.json
│   ├── Organization.json
│   ├── Person.json
│   ├── WebSite.json
│   └── FAQPage.json
├── conf/                            # Konfigurationsdaten (vom Plugin verwaltet)
│   ├── _global.conf.php             # Globale Konfiguration
│   ├── cat_kontakt.conf.php         # Kategorie-spezifisch
│   └── page_kontakt_anfahrt.conf.php # Seiten-spezifisch
├── js/
│   ├── ajv.min.js                   # AJV JSON-Schema-Validator (lokal, kein CDN)
│   └── validator.js                 # Plugin-eigene Validierungslogik
└── sprachen/
    ├── admin_language_de.txt
    ├── admin_language_en.txt
    ├── cms_language_de.txt
    └── cms_language_en.txt
```

### Geltungsbereiche und Vererbung

Das Plugin kennt drei Konfigurationsebenen. Beim Seitenaufbau werden alle zutreffenden Ebenen geladen und zusammengeführt — die spezifischere Ebene überschreibt die allgemeinere:

```
_global.conf.php          → wird auf jeder Seite ausgegeben
cat_{kategorie}.conf.php  → wird zusätzlich auf allen Seiten der Kategorie ausgegeben
page_{kat}_{seite}.conf.php → wird zusätzlich nur auf dieser einzelnen Seite ausgegeben
```

Die aktive Kategorie wird über `CAT_REQUEST`, die aktive Seite über `PAGE_REQUEST` ermittelt.

### Schema-getriebenes Formular

Jede JSON-Schema-Datei in `schemas/` beschreibt einen Schema-Type vollständig:

- **Validierungsregeln** (type, format, required, enum)
- **Formularfeld-Metadaten** via `ui:`-Properties (Widget-Typ, Placeholder, Pflichtfeld)
- **Sprachschlüssel** für Labels und Fehlermeldungen (werden zur Laufzeit aufgelöst)

Das Plugin liest beim Laden des Admin-Formulars die passende Schema-Datei und rendert daraus dynamisch die Formularfelder. Neuen Type unterstützen = neue `.json`-Datei ablegen, kein PHP anfassen.

**Beispiel-Schema (Auszug `LocalBusiness.json`):**

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "LocalBusiness",
  "required": ["name", "url"],
  "properties": {
    "name": {
      "type": "string",
      "ui:widget": "text",
      "ui:label": "label_name",
      "ui:required": true
    },
    "address": {
      "type": "object",
      "ui:widget": "postal_address"
    },
    "openingHours": {
      "type": "array",
      "ui:widget": "opening_hours"
    },
    "priceRange": {
      "type": "string",
      "ui:widget": "select",
      "ui:options": ["€", "€€", "€€€"]
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

---

## Erweiterungsfeld (erweiterte Properties)

Jede Konfiguration enthält optional ein JSON-Textarea-Feld für Properties, die das Formular nicht abbildet. Die Inhalte werden beim Speichern mit den Formularfeldern zusammengeführt (merge). Das Formular hat Vorrang bei gleichnamigen Properties.

### Validierung

Die Validierung des Erweiterungsfelds erfolgt zweistufig:

**Client-side (live, AJV.js):**
1. JSON-Syntaxprüfung — Fehler mit Position werden sofort angezeigt
2. Property-Whitelist-Prüfung gegen das aktive JSON-Schema — unbekannte Properties werden mit Hinweis markiert (gelbe Warnung), aber nicht blockiert
3. Format-Prüfung bekannter Properties (z. B. URL-Format für `hasMap`)

**Server-side (PHP, beim Speichern):**
- `json_decode()` — bei ungültigem JSON wird nicht gespeichert, Fehlermeldung wird zurückgegeben

---

## Sprachunterstützung

Das Plugin nutzt das moziloCMS-eigene Sprachsystem:

- **Admin-UI** (Formular-Labels, Fehlermeldungen, Buttons): `$admin_lang->getLanguageValue()`, Sprachdatei `admin_language_{lang}.txt`
- **Frontend / CMS-Kontext** (z. B. Wochentag-Labels in openingHours): `$language->getLanguageValue()`, Sprachdatei `cms_language_{lang}.txt`
- JSON-Schema-Dateien enthalten Sprachschlüssel (z. B. `"ui:label": "label_name"`), keine hartcodierten Strings

Initiale Sprachen: **Deutsch** (`de`), **Englisch** (`en`).

---

## JSON-LD-Ausgabe

Das Plugin gibt das JSON-LD als `<script>`-Tag im `<head>` aus:

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

> **Tipp:** Das erzeugte JSON-LD kann mit dem offiziellen Schema.org-Validator geprüft werden:  
> 🔗 [https://validator.schema.org](https://validator.schema.org)

Pro Geltungsbereich wird ein eigener `<script>`-Block ausgegeben (Global + Kategorie + Seite = bis zu drei Blöcke). Types die ausschließlich auf Globalebene sinnvoll sind (`WebSite`, `Organization`) werden nur einmal ausgegeben.

---

## Tests

Das Plugin verwendet PHPUnit 11.x für Unit-Tests.

```bash
composer install
./vendor/bin/phpunit
```

> `vendor/` ist in `.gitignore` — PHPUnit wird nicht ins Repository eingecheckt.

---

## Steuerung der globalen Ausgabe

Die globale Konfiguration (`_global.conf.php`) wird standardmäßig auf jeder Seite ausgegeben. Dieses Verhalten kann gezielt eingeschränkt werden.

### Ausschlussliste

Im Admin-Bereich kann der Nutzer Kategorien definieren, auf denen die globale Ausgabe **nicht** erfolgt — z. B. Impressum, Datenschutz, Sitemap:

```
Globale Ausgabe deaktivieren für:
[ ] impressum
[ ] datenschutz
[ ] sitemap
```

### Type-Kollision

Ist für eine Kategorie oder Seite derselbe Schema-Type wie in der globalen Konfiguration hinterlegt, gibt **nur die spezifischere Ebene** aus. Die globale Ausgabe wird in diesem Fall automatisch unterdrückt.

Beispiel: `LocalBusiness` global + `LocalBusiness` auf Kategorie `kontakt` → auf der Kontakt-Seite wird nur der Kategorie-Block ausgegeben.

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

Erkennt das Plugin beim Seitenaufbau ein bereits vorhandenes `<script type="application/ld+json">` im Template oder Seiteninhalt, wird dem Benutzer im Admin-Bereich ein Hinweis angezeigt:

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

> ⚠️ Der Import überschreibt die aktuelle Formularkonfiguration. Es erfolgt kein Merge.

---

## Installation

1. Ordner `schemaOrgData` in `plugins/` hochladen
2. Im moziloCMS-Admin unter **Plugins** aktivieren
3. Konfiguration unter **Plugins → schemaOrgData** vornehmen

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
| Seiteninhalt | `Article` (Wrapper, minimal) | — |
| Bilder | `ImageObject` via `itemprop` | — |
| Breadcrumb | `BreadcrumbList` via Microdata | — |
| Kontakt | `LocalBusiness` via Microdata im Body | `LocalBusiness` als JSON-LD im `<head>` |

Dieses Plugin **ersetzt** die Core-Microdata nicht, sondern **ergänzt** sie um JSON-LD im `<head>`, das von Google und anderen Suchmaschinen für Rich Results bevorzugt ausgewertet wird.
