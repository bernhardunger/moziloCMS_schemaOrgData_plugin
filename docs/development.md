# Entwicklung

## Lokales Setup

```bash
composer install
./vendor/bin/phpunit
```

`composer.json` (Repo-Root) deklariert nur eine Dev-Abhängigkeit,
`phpunit/phpunit: ^11.0`, sowie ein PSR-4-Autoload für die Testklassen
(`SchemaOrgData\Tests\` → `tests/`). `vendor/` ist gitignored — PHPUnit
wird nicht ins Repository eingecheckt, `composer install` ist also vor
dem ersten Testlauf notwendig. Testarten, Testausführung im Detail
(inkl. Jest-Setup) und das Test-Bootstrap-Mocking: siehe
[tests.md](tests.md).

## Commit-Konventionen

Commit-Messages sind auf Deutsch verfasst, im Imperativ oder als knappe
Beschreibung der Änderung („Dokumentation: README.md nach
Strukturvorgabe umgebaut", „docs/ an den Repo-Root verschoben"). Inline-
Dokumentation im Code (PHPDoc, Kommentare) ist ebenfalls durchgehend auf
Deutsch gehalten.

## Siehe auch

- [../README.md](../README.md) — Abschnitt „Tests" für den Nutzer-Blickwinkel
- [tests.md](tests.md) — Testarten, Testausführung, Bootstrap-Mocking im Detail
- [architecture.md](architecture.md) — warum die `lib/`-Klassen zustandslos sind (Testbarkeit)
- [schema-extending.md](schema-extending.md) — neuen Schema-Type hinzufügen, ohne PHP anzufassen
- [file-structure.md](file-structure.md) — Lage von `tests/` relativ zum Deployment-Ordner
