# Best Practices: Schema.org-Daten sinnvoll pflegen

Das Plugin validiert die **Struktur** der eingegebenen Daten — ob die
Daten **inhaltlich** zur Seite passen, liegt in der Verantwortung des
Betreibers. Dafür gilt eine einfache Grundregel:

> **Strukturierte Daten müssen dem sichtbaren Seiteninhalt entsprechen.**
> Suchmaschinen (insbesondere Google) werten Markup, das Inhalte behauptet,
> die auf der Seite nicht sichtbar sind, als irreführend — im schlimmsten
> Fall führt das zum Ausschluss der gesamten Website von Rich Results.

Diese Regel ist der Maßstab für alle folgenden Empfehlungen. Googles
eigene Richtlinien gehen an einigen Stellen über die schema.org-Spec
hinaus, siehe [Intro to Structured Data](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data).
Einen Überblick über Aufbau und Umfang des Vokabulars bietet die
[offizielle schema.org-Dokumentation](https://schema.org/docs/documents.html).

## So wenig Types wie nötig

Jeder konfigurierte Type sollte eine klare Entsprechung auf der Website
haben. Mehr Markup ist nicht automatisch besseres Ranking — ein Type ohne
inhaltliche Substanz auf der Seite bringt keinen SEO-Vorteil, aber ein
Risiko im Sinne der Grundregel oben.

### Kein Keyword-Stuffing im `name`-Feld

„Zahnarzt München Zahnarztpraxis günstig Implantate" ist kein Name. Ins
`name`-Feld gehört der tatsächliche Name — für Leistungen und Orte gibt es
eigene Properties bzw. den sichtbaren Seiteninhalt.

**❌ So nicht:**
```json
"name": "Steuerberater München günstig Steuererklärung Firmengründung"
```

**✅ Richtig:**
```json
"name": "Steuerkanzlei Beispiel"
```

### Nur Felder befüllen, für die verlässliche Daten vorliegen

Leere Felder sind kein Mangel — das Plugin tilgt sie automatisch aus der
Ausgabe (siehe [JSON-LD-Ausgabe im
Detail](../README.md#json-ld-ausgabe-im-detail)). Geschätzte oder
erfundene Werte (Geo-Koordinaten, Gründungsdatum, Öffnungszeiten) schaden
mehr als fehlende Werte — sie können zu falschen Rich-Result-Anzeigen
führen (z. B. „geöffnet" obwohl geschlossen).

**Richtig:** Nur Felder befüllen, für die tatsächlich verlässliche Daten
vorliegen. Ein unvollständiges, aber korrektes Markup ist einem
vollständigen, aber teilweise erfundenen Markup vorzuziehen.

<a id="globaler-scope"></a>
## Global nur die Identität

Auf globaler Ebene gehört hin, wer hinter der Website steht
(**Organization**, **NGO**, **Person** oder ein **LocalBusiness**-Typ) sowie ggf.
**WebSite** — und zwar **genau eine** Organisations-Identität, nicht
mehrere parallel. Innerhalb der `LocalBusiness`-Familie den **spezifischsten
passenden Type** wählen (siehe
[Lokales Unternehmen](use-cases/local-business.md)) — z. B. `AccountingService`
statt generisches `LocalBusiness` für eine Steuerkanzlei.

**❌ So nicht:** **Organization**, **NGO** und ein **LocalBusiness**-Typ
gleichzeitig global konfigurieren — das erzeugt konkurrierende Aussagen
darüber, wer die Website betreibt. Technisch verhindert das der
[De-Dup-Guard](use-cases/organization-identity.md) nicht vollständig — nur
der erste ausgegebene Knoten erhält die `@id`, die übrigen Knoten werden
trotzdem ausgegeben, nur ohne Anker.

**✅ Richtig:** Einen Type wählen, der am besten passt — im Zweifel den
spezifischsten (z. B. **NGO** statt **Organization** für einen e. V.,
**AccountingService** statt **LocalBusiness** für eine Steuerkanzlei).

<a id="seiten-types-nur-wo-passend"></a>
## Seiten-Types nur dort, wo der Inhalt es hergibt

**Event**, **JobPosting**, **DonateAction**, **FAQPage** und **Article** gehören auf
die Kategorie bzw. Seite, die den entsprechenden Inhalt tatsächlich
sichtbar zeigt.

<a id="faqpage-ohne-sichtbare-inhalte"></a>
### `FAQPage` nur mit wortgleich sichtbaren Fragen/Antworten

Die Fragen im Markup müssen **wortgleich** auf der Seite stehen — ein
FAQ-Markup als reiner „SEO-Trick" auf einer normalen Inhaltsseite verstößt
gegen die Google-Richtlinien und kann zum Ausschluss von Rich Results für
die gesamte Website führen.

**❌ So nicht:** Ein FAQ-Block mit zehn Frage-Antwort-Paaren im JSON-LD,
von denen auf der Seite selbst nur drei tatsächlich als Text zu lesen
sind.

**✅ Richtig:** Nur die Fragen/Antworten ins Formular übernehmen, die auch
im sichtbaren Seiteninhalt stehen. Siehe [FAQPage](use-cases/faq.md).

<a id="veraltete-events"></a>
### `Event` nur für tatsächlich aktuelle Veranstaltungen

Eine Veranstaltung von letztem Jahr im Markup signalisiert Suchmaschinen
veraltete Daten. Nach dem Termin: Konfiguration der Seite entfernen oder
auf den nächsten Termin aktualisieren — als Teil der regulären
Content-Pflege behandeln, nicht als Einmal-Setup (siehe [Pflege
einplanen](#pflege-einplanen) unten).

### `DonateAction` nur mit echter Spendenmöglichkeit

Der Spendenaufruf im Markup muss auf der Seite nachvollziehbar sein
(Spendenformular, Bankverbindung, Spenden-Link). Ein `DonateAction`, das
lediglich als generischer Aufruf ohne konkrete Umsetzung auf der Seite
steht, entspricht nicht dem sichtbaren Inhalt.

**Richtig:** `DonateAction` erst konfigurieren, wenn die Spendenseite die
Möglichkeit auch tatsächlich anbietet. Siehe
[DonateAction](use-cases/donate-action.md).

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
- Einfügen oder Entfernen eines fremden JSON-LD-Blocks im Inhalt einer Seite

<a id="pflege-einplanen"></a>
## Pflege einplanen

Strukturierte Daten sind kein Einmal-Setup: Bei Inhaltsänderungen
(Veranstaltung vorbei, Stelle besetzt, Öffnungszeiten geändert) muss die
Konfiguration mitziehen. In der Praxis bewährt sich ein fester Rhythmus —
z. B. **Event**- und **JobPosting**-Konfigurationen bei jeder inhaltlichen
Aktualisierung der jeweiligen Seite mitprüfen, statt sie als
„einmal eingerichtet, nie wieder angefasst" zu behandeln.

## Migration von bestehendem JSON-LD

Wird beim Umstieg auf das Plugin bereits vorhandenes JSON-LD im
Layout-Template oder im Inhalt einer Seite erkannt, empfiehlt sich die in
[import.md](import.md) beschriebene Reihenfolge: zunächst importieren und
im Formular prüfen, erst danach den alten Block entfernen und auf
„Überschreiben" umstellen — nie beides gleichzeitig ohne Zwischenschritt.

## Siehe auch

- [../README.md](../README.md#best-practices) — Kurzfassung
- [../README.md](../README.md#typische-fehler) — Kurzfassung der Fehlerbeispiele
- [use-cases/organization-identity.md](use-cases/organization-identity.md) — technischer Hintergrund zur „genau eine Identität"-Regel
- [use-cases/faq.md](use-cases/faq.md), [use-cases/donate-action.md](use-cases/donate-action.md) — Type-spezifische Beispiele mit korrekter Konfiguration
