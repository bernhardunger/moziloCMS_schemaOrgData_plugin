# Validierung

Alle Formularfelder werden zweistufig validiert: **live im Browser**
(JavaScript/AJV, siehe [rendering.md](rendering.md)) und **server-seitig
in PHP** beim Speichern. Die server-seitige Prüfung ist eigenständig und
greift auch, wenn JavaScript deaktiviert ist — sie ist in jedem Fall
maßgeblich für das, was tatsächlich gespeichert wird.

Das Feedback im Formular ist dreistufig: ✅ grün (OK) · ⚠️ gelb (Warnung,
blockiert nicht) · ❌ rot (Fehler, blockiert das Speichern). Alle
Fehlermeldungen kommen aus den Sprachdateien
(`$admin_lang->getLanguageValue()`, siehe [tests.md](tests.md)).

## Übersicht

| Feld | Prüfung | Nur DE |
|---|---|---|
| `postalCode` | Regex `[0-9]{5}` | ja |
| `telephone` | Normalisierung + E.164-Prüfung | nein |
| `url`, `logo`, `image` | URL-Format; `http://` ergibt HTTPS-Warnung (⚠️) | nein |
| `email` | E-Mail-Format | nein |
| `openingHours` | Format + Von-Zeit < Bis-Zeit (24-Stunden-Format) | nein |
| `addressCountry` | Enum-Prüfung gegen die Länderliste | nein |
| `geo` (Erweiterungsfeld) | numerisch + Wertebereich (Breite/Länge) | nein |
| Datumsfelder — alle Felder mit `format: date-time` (`Article.datePublished`/`dateModified`, `Event.startDate`/`endDate`, `JobPosting.datePosted`/`validThrough`, `ProfilePage.dateCreated`/`dateModified`) | ausschließlich deutsches Format `TT.MM.YYYY` (optional mit Uhrzeit `HH:MM`), kalendarische Gültigkeit | nein |
| Start-/End-Paare (`Event.startDate`/`endDate`, `JobPosting.datePosted`/`validThrough`) | zusätzlich Bereichsprüfung: das Ende darf nicht vor dem Beginn liegen | nein |

## Postleitzahl

Greift nur, wenn `addressCountry = DE` gewählt ist:

```
/^[0-9]{5}$/
```

Für andere Länder wird das Feld nicht gegen ein festes Muster geprüft, da
Postleitzahlformate international stark variieren.

## Telefonnummer

Gilt länderunabhängig, da E.164 der internationale Standard ist. Die
Eingabe wird zunächst normalisiert (alle Zeichen außer Ziffern und `+`
werden entfernt), anschließend gegen E.164 geprüft:

```
/^(\+|00)[1-9][0-9]{6,14}$/
```

Beispiel: Eingabe `089 / 123 456` wird zu `089123456` normalisiert — das
allein erfüllt E.164 noch nicht (fehlende Landesvorwahl). Empfohlen ist
die Eingabe mit Landesvorwahl, z. B. `+49 89 123456`.

## URL-Felder

Betrifft `url`, `logo`, `image` sowie beliebige URL-artige
Properties im Erweiterungsfeld:

- `https://…` — ✅ OK
- `http://…` — ⚠️ Warnung „Für Produktivseiten wird HTTPS empfohlen",
  blockiert aber nicht das Speichern
- syntaktisch ungültige URL — ❌ Fehler

## Öffnungszeiten

Je Wochentag ist neben dem Hauptzeitraum (`from`/`to`) ein zweiter,
optionaler Zeitraum vorgesehen (`from2`/`to2`, z. B. für eine
Mittagspause). Der zweite Zeitraum ist **niemals Pflicht** — bleiben
`from2` und `to2` leer, erzeugt das keinen Fehler, unabhängig davon, ob
der Hauptzeitraum befüllt ist.

Ist der zweite Zeitraum befüllt, muss er nach dem Ende des ersten
beginnen: `from2` muss größer oder gleich `to` sein. Beginnt er davor
(`from2 < to`), schlägt die Validierung mit einer eigenen
Überlappungs-Fehlermeldung fehl — unabhängig davon, ob `from2`/`to2` für
sich genommen ein gültiges Zeitfenster ergeben.

## Datumsfelder

Eingabe ausschließlich im deutschen Format `TT.MM.YYYY` bzw.
`TT.MM.YYYY HH:MM`. Eine gültige Eingabe wird beim Speichern
server-seitig auf ISO-8601 normalisiert:

- ohne Uhrzeit: `YYYY-MM-DD`
- mit Uhrzeit: `YYYY-MM-DDTHH:MM:SS±HH:MM` (Offset aus der
  Server-Zeitzone aufgelöst)

Gespeichert und im JSON-LD ausgegeben wird **ausschließlich** der
ISO-Wert — das deutsche Format ist reines Eingabeformat im Admin-Formular.

Für beide bekannten Start-/End-Datumsfeldpaare gilt dieselbe
Bereichsprüfung: Bei `Event` darf `endDate` nicht vor `startDate` liegen,
bei `JobPosting` darf `validThrough` nicht vor `datePosted` liegen —
in beiden Fällen schlägt die Validierung sonst fehl.

## Erweiterungsfeld (JSON-Textarea)

Zweistufig validiert, siehe
[Erweiterungsfeld](../README.md#erweiterungsfeld):

**Client-seitig (live, AJV.js):**
1. JSON-Syntaxprüfung — Fehler mit Position werden sofort angezeigt
2. Property-Whitelist-Prüfung gegen das aktive JSON-Schema — unbekannte
   Properties werden mit Hinweis markiert (⚠️ gelbe Warnung), aber nicht
   blockiert
3. Format-Prüfung bekannter Properties (z. B. URL-Format für `url`/`logo`,
   Wertebereich für `geo`-Koordinaten)

**Server-seitig (PHP, beim Speichern):**
- `json_decode()` — bei ungültigem JSON wird nicht gespeichert, eine
  Fehlermeldung wird zurückgegeben
- Strukturprüfung — erwartet wird ein JSON-Objekt; eine Liste wird mit
  eigener Fehlermeldung abgelehnt
- inhaltliche Prüfung bekannter Properties (z. B. `geo`-Koordinaten)

Schlüssel, die das Formular als eigenes Feld führt, werden beim Speichern
aus dem Erweiterungsfeld verworfen und als Hinweis gemeldet. Andernfalls
ließe sich die Bereinigung der Formularfelder umgehen: Ein leer gelassenes
Formularfeld liefert beim Merge keinen Wert, mit dem es den ungereinigten
Eintrag aus dem Erweiterungsfeld überschreiben könnte. Das Erweiterungsfeld
ist für Properties gedacht, die das Formular nicht abbildet.

## Siehe auch

- [../README.md](../README.md#formularvalidierung) — Kurzübersicht und Tabelle
- [rendering.md](rendering.md) — clientseitige AJV-Einbindung im Formular
- [use-cases/local-business.md](use-cases/local-business.md), [use-cases/event.md](use-cases/event.md), [use-cases/job-posting.md](use-cases/job-posting.md) — Felder mit Datums- bzw. Adressvalidierung im Kontext
