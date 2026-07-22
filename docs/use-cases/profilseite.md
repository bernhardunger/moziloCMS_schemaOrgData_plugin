# Use Case: Profilseite (ProfilePage)

`ProfilePage` bildet eine Profilseite auf **Seiten-Ebene** ab — z. B. eine
Über-mich-Seite, Autorenseite oder das Kurzprofil eines
Kanzlei-/Praxis-Mitglieds. Der Type folgt der
[Google-ProfilePage-Guideline](https://developers.google.com/search/docs/appearance/structured-data/profile-page):
Hauptinhalt der Seite ist ausschließlich eine Person, nicht die
Organisation selbst.

## Voraussetzung: mindestens eine aktive Registry-Person

`mainEntity` verweist ausschließlich auf eine
[Registry-Person](../../README.md#personen-registry) — anders als bei den
übrigen `id_reference_or_literal`-Feldern (`Article.author`,
`Event.organizer`) steht hier **weder** eine Organisation-Referenz **noch**
Direkteingabe zur Verfügung. Ohne mindestens eine aktive Person in der
Personen-Registry lässt sich `ProfilePage` nicht speichern (`mainEntity`
ist Pflichtfeld).

## Felder im Überblick

| Feld | schema.org Property | Widget / Format |
|---|---|---|
| Hauptperson | `mainEntity` | Widget `id_reference_or_literal`, eingeschränkt auf `ui:referenceTargets: ["persons"]` mit `ui:allowLiteral: false` — reine Personen-Referenz, kein Modus-Umschalter |
| Erstellt am | `dateCreated` | optional, deutsches Datumsformat `TT.MM.YYYY`, analog `JobPosting.datePosted` |
| Zuletzt geändert | `dateModified` | optional, deutsches Datumsformat `TT.MM.YYYY`, analog `JobPosting.validThrough` |

Ein Erweiterungsfeld für zusätzliche, vom Formular nicht abgedeckte
Properties steht wie bei jedem Type zur Verfügung (siehe
[Erweiterungsfeld](../../README.md#erweiterungsfeld)).

`ProfilePage` erhält bewusst **kein eigenes** `@id`-Fragment (kein
`ui:idFragment` in der Schema-Datei) — der Anker `#person-{slug}` gehört
der referenzierten Person, nicht der Profilseite selbst.

## Vollständiges Beispiel

Auf einer Profilseite konfiguriert, ergeben sich zwei Blöcke: die
Profilseite selbst (verweist per `@id` auf die Person) und die Person als
eigenständiger `Person`-Knoten (siehe
[Organisations-Identität und @id-Anker](organization-identity.md)):

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ProfilePage",
  "mainEntity": { "@id": "https://www.example.org/#person-maria-beispiel" },
  "dateCreated": "2026-01-15",
  "dateModified": "2026-07-01"
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Person",
  "@id": "https://www.example.org/#person-maria-beispiel",
  "name": "Dr. Maria Beispiel",
  "jobTitle": "Geschäftsführerin",
  "knowsAbout": ["Steuerrecht", "Unternehmensberatung"]
}
</script>
```

`knowsAbout` erscheint im Person-Knoten unabhängig davon, über welchen der
drei Referenz-Mechanismen (Artikel-Autor, `Event.organizer`, `ProfilePage.mainEntity`)
die Person referenziert wurde (siehe
[organization-identity.md](organization-identity.md)).

## Verhalten bei gelöschter oder inaktiver Person

- **Person auf inaktiv gesetzt:** Die Ausgabe bleibt unverändert — eine
  bereits als `mainEntity` referenzierte Person erscheint weiter im
  Frontend, auch wenn sie aus den Auswahllisten anderer Formulare
  verschwindet. Dieses Verhalten ist bewusst analog zu `Event.organizer`
  (siehe [organization-identity.md](organization-identity.md)).
- **Person gelöscht:** Anders als bei einer nur inaktiven Person entfällt
  hier der **gesamte** `ProfilePage`-Block — nicht nur die `mainEntity`-Property.
  Eine Profilseite ohne ihre von Google geforderte Pflichtangabe
  `mainEntity` wäre ein irreführender Torso; das Plugin unterdrückt den
  Knoten daher vollständig, sobald die referenzierte Person nicht mehr in
  der Registry existiert. Der Admin-Bereich zeigt in diesem Fall den
  bestehenden Dangling-Hinweis. Übrige, unabhängig konfigurierte Blöcke
  (z. B. die globale Organisations-Identität) bleiben davon unberührt.

## Abgrenzung: Personen-Stammdaten gehören in die Registry

`ProfilePage` selbst enthält keine Personen-Stammdaten (Name, Titel,
Position, Bild etc.) — diese werden ausschließlich in der
[Personen-Registry](../../README.md#personen-registry) gepflegt und bei
Referenzierung automatisch als eigenständiger `Person`-Knoten ausgegeben.
Eine Profilseite ohne zugehörige Registry-Person lässt sich dadurch
strukturell nicht anlegen.

## Siehe auch

- [../../README.md](../../README.md#use-cases-und-beispiele) — Use Cases im Überblick
- [../widgets.md](../widgets.md) — Mechanik von `id_reference_or_literal`, `ui:referenceTargets`/`ui:allowLiteral`
- [organization-identity.md](organization-identity.md) — Registry-Personen, `#person-{slug}`-Fragmente, `knowsAbout`
- [Google: ProfilePage — strukturierte Daten](https://developers.google.com/search/docs/appearance/structured-data/profile-page)
