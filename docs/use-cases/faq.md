# Use Case: FAQPage

`FAQPage` bildet häufig gestellte Fragen auf **Kategorie-** oder
**Seiten-Ebene** ab.

> ⚠️ **Wichtigste Regel:** Die im Markup hinterlegten Fragen und Antworten
> müssen **wortgleich** auf der Seite sichtbar sein. Ein FAQ-Markup als
> reiner „SEO-Trick" auf einer normalen Inhaltsseite verstößt gegen die
> Google-Richtlinien und kann zum Ausschluss von Rich Results führen —
> siehe [Best Practices](../best-practices.md#faqpage-ohne-sichtbare-inhalte).
> Zudem hat Google die Rich-Results-Darstellung für `FAQPage` seit 2023 auf
> wenige autoritative Quellen beschränkt — auf den meisten Websites hat das
> Markup daher primär strukturellen, keinen Rich-Results-Wert mehr.

## Felder im Überblick

Das Formular bildet eine variable Liste von Frage-Antwort-Paaren ab.
Jedes Paar entspricht in der JSON-LD-Ausgabe einem `Question`-Knoten mit
eingebettetem `acceptedAnswer`:

```json
{
  "@type": "Question",
  "name": "Frage im Wortlaut",
  "acceptedAnswer": {
    "@type": "Answer",
    "text": "Antwort im Wortlaut"
  }
}
```

## Vollständiges Beispiel

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Wie lange dauert die Bearbeitung meiner Steuererklärung?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "In der Regel 2 bis 4 Wochen ab vollständiger Unterlagenübergabe."
      }
    },
    {
      "@type": "Question",
      "name": "Bieten Sie auch Erstberatungen an?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Ja, ein kostenloses Erstgespräch ist nach Terminvereinbarung möglich."
      }
    }
  ]
}
</script>
```

## Kategorie- vs. Seiten-Ebene

- **Kategorie-Ebene** eignet sich, wenn dieselbe FAQ-Liste auf allen Seiten
  einer Kategorie sichtbar ist (z. B. ein FAQ-Block im Kategorie-Template).
- **Seiten-Ebene** eignet sich für eine dedizierte FAQ-Einzelseite mit
  eigenem, nicht wiederverwendetem Fragenkatalog.

Eine Vermischung beider Ebenen für denselben Type folgt der
[feldweisen Vererbung](../../README.md#geltungsbereiche-und-vererbung) wie
jeder andere Type auch — in der Praxis ist bei `FAQPage` aber meist eine
vollständige Neukonfiguration je Ebene sinnvoller als eine teilweise
Vererbung einzelner Fragen.

## Siehe auch

- [../../README.md](../../README.md#use-cases-und-beispiele) — Use Cases im Überblick
- [../best-practices.md](../best-practices.md#faqpage-ohne-sichtbare-inhalte) — Grundregel „Markup entspricht sichtbarem Inhalt", FAQ als SEO-Trick
