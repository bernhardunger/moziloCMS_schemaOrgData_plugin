# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei
festgehalten. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

Diese Liste ist kuratiert und beschränkt sich auf Änderungen mit Auswirkung
für Nutzer und Betreiber des Plugins (neue Features, sichtbare Bugfixes,
Breaking Changes). Rein interne Refactorings, Dokumentations-Updates und
Testerweiterungen ohne Verhaltensänderung sind hier nicht aufgeführt.

## [0.9.x] – Release-Candidate-Phase

Feature-Freeze mit Fokus auf Fehlerbehebungen und Feinschliff vor dem
1.0-Release.

### Added

- Erkennung vorhandener JSON-LD-Blöcke in Template oder Seiteninhalt inkl.
  Ein-Klick-Autofill sowie eigenem, einklappbarem Bereich für den manuellen
  Import
- Placeholder-Texte für die Direkteingabe-Felder von `JobPosting.hiringOrganization`
  und `Event.organizer`
- Warnhinweis (nicht blockierend), wenn das `Event.startDate` in der
  Vergangenheit liegt

### Changed

- Datumsfelder bei `Event` akzeptieren nur noch das deutsche Eingabeformat
  (`TT.MM.YYYY[ HH:MM]`); Speicherung und Ausgabe bleiben weiterhin ISO-8601
- Die adminseitige Erkennung des `{schemaOrgData}`-Platzhalters und
  vorhandener JSON-LD-Blöcke berücksichtigt je Layout ausschließlich
  `template.html`

### Fixed

- URL-Felder akzeptieren nur noch `http://`/`https://` statt beliebiger
  URI-Syntax
- `JobPosting`: `validThrough` vor `datePosted` wird jetzt als Fehler erkannt
- Galerie-Vollansichten lieferten fälschlich das JSON-LD einer anderen Seite
  aus — die Ausgabe wird dort jetzt vollständig unterdrückt
- Das Debug-Widget-Markup landete teilweise im `<head>` statt ausschließlich
  als `<script>`-Block
- Das Pflichtfeld „Ort" wurde bei Orts-/Adress-Widgets (`Event.location`,
  `JobPosting.jobLocation`) inkonsistent erzwungen bzw. nicht erzwungen
- Öffnungszeiten: die Validierung der Pausenzeiten vertauschte in bestimmten
  Fällen Von- und Bis-Zeit
- Nach einem durch eine Pflichtfeldprüfung blockierten Speichern-Versuch
  gingen bereits gültige Eingaben in den Geo- und Öffnungszeiten-Feldern
  verloren
- Aktualisierte Skript-Dateien (`ajv.min.js`, `validator.js`) wurden ohne
  Cache-Busting ausgeliefert, wodurch Browser teils veraltete Fassungen
  weiterverwendeten

### Removed

- Temporärer Entwickler-Button zum Zurücksetzen einer Konfigurationsebene
  vor dem Release entfernt

## [0.4.x] – Feature-Ausbau (Beta)

### Added

- Schema-Types: `LocalBusiness`-Familie (`LocalBusiness`, `ProfessionalService`,
  `LegalService`, `MedicalBusiness`, `AccountingService`), `Organization`,
  `NGO`, `Person`, `WebSite`, `FAQPage`, `Article`, `JobPosting`,
  `DonateAction`, `Event`
- `@id`-Anker-Mechanismus für global definierte Identitätsknoten
  (Organisation/Person) sowie die Widgets `id_reference` und
  `id_reference_or_literal` zum Verweisen darauf aus Seiten-Types
- `geo`-Feld (`GeoCoordinates`, Breite/Länge) für die `LocalBusiness`-Familie
- Optionaler zweiter Zeitraum je Wochentag im Öffnungszeiten-Widget (z. B.
  für Mittagspausen)
- Feldweise Vererbung der Konfiguration Global → Kategorie → Seite
- Debug-Modus: erzeugtes JSON-LD als Pop-up im Frontend anzeigen

### Changed

- Sprachdateien auf Locale-Codes umgestellt (`admin_language_deDE.txt` /
  `_enEN.txt` statt `_de.txt` / `_en.txt`)

### Security

- Schutz gegen Script-Breakout in der JSON-LD-Ausgabe (`JSON_HEX_TAG`)
- Das Erweiterungsfeld kann die reservierten Properties `@context`, `@type`
  und `@id` nicht mehr überschreiben
- DOM-XSS in der Live-Feedback-Anzeige des Erweiterungsfelds behoben

## [0.1.0] – Erste Version

### Added

- Admin-Formular mit drei Geltungsbereichen (Global, Kategorie, Seite)
- Generisches `PostalAddress`-Schema und Öffnungszeiten-Widget
- JSON-LD-Ausgabe im `<head>` der Seite
