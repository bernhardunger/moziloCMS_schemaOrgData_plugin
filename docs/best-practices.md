# Best Practices: Schema.org-Daten sinnvoll pflegen

Das Plugin validiert die **Struktur** der eingegebenen Daten — ob die
Daten **inhaltlich** zur Seite passen, liegt in der Verantwortung des
Betreibers. Dafür gilt eine einfache Grundregel:

> **Strukturierte Daten müssen dem sichtbaren Seiteninhalt entsprechen.**
> Suchmaschinen (insbesondere Google) werten Markup, das Inhalte behauptet,
> die auf der Seite nicht sichtbar sind, als irreführend — im schlimmsten
> Fall führt das zum Ausschluss der gesamten Website von Rich Results.

Diese Regel ist der Maßstab für alle folgenden Empfehlungen.

## So wenig Types wie nötig

Jeder konfigurierte Type sollte eine klare Entsprechung auf der Website
haben. Mehr Markup ist nicht automatisch besseres Ranking — ein Type ohne
inhaltliche Substanz auf der Seite bringt keinen SEO-Vorteil, aber ein
Risiko im Sinne der Grundregel oben.

<a id="globaler-scope"></a>
## Global nur die Identität

Auf globaler Ebene gehört hin, wer hinter der Website steht
(**Organization**, **NGO**, **Person** oder ein **LocalBusiness**-Typ) sowie ggf.
**WebSite** — und zwar **genau eine** Organisations-Identität, nicht
mehrere parallel. Innerhalb der `LocalBusiness`-Familie den **spezifischsten
passenden Type** wählen (siehe
[Lokales Unternehmen](use-cases/local-business.md)) — z. B. `AccountingService`
statt generisches `LocalBusiness` für eine Steuerkanzlei.

## Seiten-Types nur dort, wo der Inhalt es hergibt

**Event**, **JobPosting**, **DonateAction**, **FAQPage** und **Article** gehören auf
die Kategorie bzw. Seite, die den entsprechenden Inhalt tatsächlich
sichtbar zeigt:

- `FAQPage` nur, wenn Fragen und Antworten wortgleich sichtbar sind
- `Event` nur für tatsächlich stattfindende, aktuelle Veranstaltungen
- `JobPosting` nur für tatsächlich offene Stellen
- `DonateAction` nur, wenn eine echte Spendenmöglichkeit auf der Seite
  besteht

Siehe auch [Typische Fehler](common-mistakes.md) für die jeweilige
Gegenprobe.

## Nach jeder Änderung prüfen

**Debug-Modus** aktivieren (siehe
[Debug-Modus](../README.md#debug-modus)) und das erzeugte JSON-LD mit
[validator.schema.org](https://validator.schema.org) abgleichen; für die
Google-Sicht zusätzlich der
[Rich-Results-Test](https://search.google.com/test/rich-results). Das gilt
insbesondere nach:

- Wechsel des Identitäts-Types auf Global
- Änderungen an `@id`-relevanten Feldern (siehe
  [Organisations-Identität](use-cases/organization-identity.md))
- Änderungen am Layout-Template (`template.html`), die den
  `{schemaOrgData}`-Platzhalter betreffen könnten

## Pflege einplanen

Strukturierte Daten sind kein Einmal-Setup: Bei Inhaltsänderungen
(Veranstaltung vorbei, Stelle besetzt, Öffnungszeiten geändert) muss die
Konfiguration mitziehen. In der Praxis bewährt sich ein fester Rhythmus —
z. B. **Event**- und **JobPosting**-Konfigurationen bei jeder inhaltlichen
Aktualisierung der jeweiligen Seite mitprüfen, statt sie als
„einmal eingerichtet, nie wieder angefasst" zu behandeln.

## Migration von bestehendem JSON-LD

Wird beim Umstieg auf das Plugin bereits vorhandenes JSON-LD im Template
oder Seiteninhalt erkannt, empfiehlt sich die in
[import.md](import.md) beschriebene Reihenfolge: zunächst importieren und
im Formular prüfen, erst danach den alten Block entfernen und auf
„Überschreiben" umstellen — nie beides gleichzeitig ohne Zwischenschritt.

## Siehe auch

- [../README.md](../README.md#best-practices) — Kurzfassung
- [common-mistakes.md](common-mistakes.md) — konkrete Fehler, die aus dem Ignorieren dieser Regeln entstehen
- [use-cases/organization-identity.md](use-cases/organization-identity.md) — technischer Hintergrund zur „genau eine Identität"-Regel
