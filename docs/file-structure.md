# Dateistruktur

Vollständiger Datei- und Ordnerbaum des Deployment-Ordners
`plugins/schemaOrgData/` — das ist der Ordner, der 1:1 in eine
moziloCMS-Installation übernommen wird. Tests, Composer-Setup und
weiterführende Dokumentation liegen außerhalb dieses Ordners und sind
nicht Teil des Deployment-Pakets (siehe [development.md](development.md)).

```
plugins/schemaOrgData/
├── index.php                          # Plugin-Hauptklasse (Fassade), moziloCMS-Einstiegspunkt
├── plugin.conf.php                    # Plugin-Metadaten + alleiniger Speicherort der Live-Konfiguration
│                                         (siehe configuration.md)
│
├── schemas/                           # JSON-Schema-Dateien: Validierungsregeln + Formularfelder,
│   │                                     eine Datei je Schema-Type (siehe schema-extending.md)
│   ├── AccountingService.json         # Steuerberatung/Buchhaltung (LocalBusiness-Familie)
│   ├── Article.json                   # Artikel/Blogbeitrag (Kategorie/Seite)
│   ├── DonateAction.json              # Spendenaufruf, id_reference auf den Org-Knoten (Seite)
│   ├── Event.json                     # Veranstaltung/Termin, place- und id_reference_or_literal-Widget (Seite)
│   ├── FAQPage.json                   # Häufig gestellte Fragen, faq_list-Widget (Kategorie/Seite)
│   ├── JobPosting.json                # Stellenanzeige, id_reference_or_literal + place-Widget (Seite)
│   ├── LegalService.json              # Anwaltskanzlei/Rechtsberatung (LocalBusiness-Familie)
│   ├── LocalBusiness.json             # Lokales Unternehmen (LocalBusiness-Familie, Global/Kategorie)
│   ├── MedicalBusiness.json           # Arztpraxis/medizinische Einrichtung (LocalBusiness-Familie)
│   ├── NGO.json                       # Gemeinnützige Organisation, @id-Anker "organization" (Global)
│   ├── Organization.json              # Organisation/Firma, @id-Anker "organization" (Global)
│   ├── Person.json                    # Einzelperson, @id-Anker "person" (Global)
│   ├── ProfessionalService.json       # Dienstleister (LocalBusiness-Familie)
│   └── WebSite.json                   # Website-Metadaten (Global)
│
├── js/
│   ├── ajv.min.js                     # AJV JSON-Schema-Validator (Draft-07), lokal ausgeliefert, kein CDN
│   └── validator.js                   # Plugin-eigene clientseitige Live-Validierung, Scope-/Type-Umschaltung,
│                                         Extension-Feld-AJV-Wiring, Autofill-/Import-Button-Verdrahtung
│
├── lib/                                # Plugin-Komponenten, je Datei ein eigener IS_CMS-Guard,
│   │                                     per require_once aus index.php geladen (siehe architecture.md)
│   ├── SchemaOrgData_UrlHelper.php            # Basis-URL-Ermittlung aus dem Request (@id-Grundlage)
│   ├── SchemaOrgData_LanguageService.php      # CMS-Sprachcode → Plugin-Locale, Language-Objekt-Fabrik
│   ├── SchemaOrgData_SchemaRepository.php     # Laden/Cachen von schemas/*.json, $ref-Auflösung
│   ├── SchemaOrgData_ScopeResolver.php        # Geltungsbereichs-Logik, Settings-Keys, Vererbung
│   ├── SchemaOrgData_JsonLdBuilder.php        # <script>-Block-Erzeugung, @id-Vergabe, Leerfeld-Filter
│   ├── SchemaOrgData_IdReferenceService.php   # id_reference(_or_literal)-Fragmentliste, Dangling-Guard
│   ├── SchemaOrgData_CollisionDetector.php    # Erkennung vorhandener JSON-LD-Blöcke, Platzhalter-Check
│   ├── SchemaOrgData_OpeningHoursHelper.php   # openingHours-Array ↔ Pro-Tag-Formularwerte
│   ├── SchemaOrgData_DataSplitHelper.php      # gespeicherte Daten → Formular-/Erweiterungsfeld-Split
│   ├── SchemaOrgData_Validator.php            # serverseitige Feldvalidierung (alle Feldtypen)
│   ├── SchemaOrgData_FormRenderer.php         # Admin-Formular-Rendering, alle Widget-Typen
│   ├── SchemaOrgData_ImportService.php        # Parsen eines eingefügten JSON-LD-Blocks
│   ├── SchemaOrgData_AdminController.php      # Orchestrierung Admin-Sektion/-Seite
│   ├── SchemaOrgData_AdminPageRenderer.php    # zustandslose Anzeige-Bausteine der Admin-Seite
│   ├── SchemaOrgData_AdminRequestHandler.php  # POST-/Import-Dispatch des Admin-Formulars
│   ├── SchemaOrgData_ConfigSaveService.php    # Validieren/Bereinigen/Speichern einer Scope-Konfiguration
│   ├── SchemaOrgData_ValidationResult.php     # Ergebnis-Objekt der Erweiterungsfeld-Validierung
│   ├── SchemaOrgData_FrontendRenderer.php     # Frontend-Ausgabepipeline, Debug-Widget
│   ├── SchemaOrgData_FrontendRequestContext.php # Kollaboratoren-Bündel für renderFrontend()
│   └── SchemaOrgData_AdminRequestContext.php    # Kollaboratoren-Bündel für renderAdminPage()/renderScopeSection()
│
└── sprachen/                          # Sprachdateien (Format "schluessel = wert" je Zeile)
    ├── admin_language_deDE.txt        # Admin-UI-Labels/Fehlermeldungen, Deutsch
    ├── admin_language_enEN.txt        # Admin-UI-Labels/Fehlermeldungen, Englisch
    ├── cms_language_deDE.txt          # Frontend-/Wochentag-Labels, Deutsch
    └── cms_language_enEN.txt          # Frontend-/Wochentag-Labels, Englisch
```

## Was nicht im Deployment-Ordner liegt

Diese Pfade existieren im Entwicklungs-Repository, aber nicht in einer
moziloCMS-Installation (dort landet nur der Inhalt von
`plugins/schemaOrgData/`, siehe oben):

```
(Repo-Root)
├── docs/               # diese Dokumentation (Geschwister von plugins/, analog zu tests/)
├── tests/              # PHPUnit-Tests + tests/js/ (Jest), siehe tests.md
├── vendor/             # Composer-Dependencies (gitignored)
├── composer.json / composer.lock
├── phpunit.xml
└── README.md
```

## Siehe auch

- [../README.md](../README.md) — Feature-Überblick und Nutzerdokumentation
- [architecture.md](architecture.md) — Komponentenaufbau und Zusammenspiel der `lib/`-Klassen
- [schema-extending.md](schema-extending.md) — neuen Schema-Type per JSON-Datei hinzufügen
- [development.md](development.md) — lokales Setup, Commit-Konventionen
- [tests.md](tests.md) — Testarten, Testausführung im Detail
