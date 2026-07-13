# Typische Fehler — so bitte nicht

Diese Liste ergänzt [Best Practices](best-practices.md) um konkrete
Negativbeispiele. Jeder Punkt verstößt gegen dieselbe Grundregel:
strukturierte Daten müssen dem sichtbaren Seiteninhalt entsprechen.

<a id="faqpage-ohne-sichtbare-inhalte"></a>
## ❌ `FAQPage` ohne sichtbare Fragen und Antworten

Die Fragen im Markup müssen **wortgleich** auf der Seite stehen — ein
FAQ-Markup als reiner „SEO-Trick" auf einer normalen Inhaltsseite verstößt
gegen die Google-Richtlinien und kann zum Ausschluss von Rich Results für
die gesamte Website führen.

**So nicht:** Ein FAQ-Block mit zehn Frage-Antwort-Paaren im JSON-LD, von
denen auf der Seite selbst nur drei tatsächlich als Text zu lesen sind.

**Richtig:** Nur die Fragen/Antworten ins Formular übernehmen, die auch im
sichtbaren Seiteninhalt stehen. Siehe [FAQPage](use-cases/faq.md).

<a id="veraltete-events"></a>
## ❌ Abgelaufene `Event`-Einträge stehen lassen

Eine Veranstaltung von letztem Jahr im Markup signalisiert Suchmaschinen
veraltete Daten. Nach dem Termin: Konfiguration der Seite entfernen oder
auf den nächsten Termin aktualisieren.

**Richtig:** `Event`-Konfigurationen als Teil der regulären
Content-Pflege behandeln, nicht als Einmal-Setup — siehe
[Pflege einplanen](best-practices.md#pflege-einplanen).

## ❌ `DonateAction` ohne tatsächliche Spendenmöglichkeit

Der Spendenaufruf im Markup muss auf der Seite nachvollziehbar sein
(Spendenformular, Bankverbindung, Spenden-Link). Ein `DonateAction`, das
lediglich als generischer Aufruf ohne konkrete Umsetzung auf der Seite
steht, entspricht nicht dem sichtbaren Inhalt.

**Richtig:** `DonateAction` erst konfigurieren, wenn die Spendenseite die
Möglichkeit auch tatsächlich anbietet. Siehe
[DonateAction](use-cases/donate-action.md).

## ❌ Keyword-Stuffing im `name`-Feld

„Zahnarzt München Zahnarztpraxis günstig Implantate" ist kein Name. Ins
`name`-Feld gehört der tatsächliche Name — für Leistungen und Orte gibt es
eigene Properties bzw. den sichtbaren Seiteninhalt.

**So nicht:**
```json
"name": "Steuerberater München günstig Steuererklärung Firmengründung"
```

**Richtig:**
```json
"name": "Steuerkanzlei Beispiel"
```

## ❌ Mehrere Organisations-Identitäten global parallel

`Organization`, `NGO` und ein `LocalBusiness`-Typ gleichzeitig global zu
konfigurieren erzeugt konkurrierende Aussagen darüber, wer die Website
betreibt. Technisch verhindert das der [De-Dup-Guard](use-cases/organization-identity.md)
nicht vollständig — nur der erste ausgegebene Knoten erhält die `@id`,
die übrigen Knoten werden trotzdem ausgegeben, nur ohne Anker.

**Richtig:** Einen Type wählen, der am besten passt — im Zweifel den
spezifischsten (z. B. `NGO` statt `Organization` für einen e. V.,
`AccountingService` statt `LocalBusiness` für eine Steuerkanzlei). Siehe
[Global nur die Identität](best-practices.md#globaler-scope).

## ❌ Daten eintragen, „weil das Feld da ist"

Leere Felder sind kein Mangel — das Plugin tilgt sie automatisch aus der
Ausgabe (siehe [JSON-LD-Ausgabe im Detail](../README.md#json-ld-ausgabe-im-detail)).
Geschätzte oder erfundene Werte (Geo-Koordinaten, Gründungsdatum,
Öffnungszeiten) schaden mehr als fehlende Werte — sie können zu falschen
Rich-Result-Anzeigen führen (z. B. „geöffnet" obwohl geschlossen).

**Richtig:** Nur Felder befüllen, für die tatsächlich verlässliche Daten
vorliegen. Ein unvollständiges, aber korrektes Markup ist einem
vollständigen, aber teilweise erfundenen Markup vorzuziehen.

## Siehe auch

- [best-practices.md](best-practices.md) — die zugrunde liegenden Empfehlungen
- [../README.md](../README.md#typische-fehler) — Kurzfassung dieser Liste
- [use-cases/](use-cases/) — Type-spezifische Beispiele mit korrekter Konfiguration
