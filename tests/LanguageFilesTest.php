<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die ausgelieferten Sprachdateien selbst
* (sprachen/*.txt), nicht für SchemaOrgData_LanguageService.
*
* Zusicherung: kein Schlüssel kommt in einer Datei mehr als
* einmal vor.
*
* Gelesen wird zeilenweise roh von der Platte, nicht über
* Language/Properties: Properties::loadProperties() weist die
* Zeilen in ein Array zu, der zweite Eintrag eines doppelten
* Schlüssels überschreibt den ersten ohne Warnung. Durch die
* Klasse hindurch wäre das Duplikat also unsichtbar - und mit
* ihm die Tatsache, dass eine Textänderung am ersten Vorkommen
* folgenlos bliebe.
*
***************************************************************/
final class LanguageFilesTest extends TestCase {

    /**
    * @return array<string, array{string}>
    */
    public static function sprachdateiProvider(): array {
        $files = glob(BASE_DIR.'plugins/schemaOrgData/sprachen/*.txt');

        $cases = [];
        foreach($files === false ? [] : $files as $path) {
            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    #[DataProvider('sprachdateiProvider')]
    function testSprachdateiEnthaeltKeinenSchluesselDoppelt(string $path): void {
        $lines = file($path);
        $this->assertIsArray($lines, basename($path).' ist nicht lesbar');

        $counts = [];
        foreach($lines as $line) {
            // Zeilenfilter und Schlüsselerkennung entsprechen
            // Properties::loadProperties(): Kommentar-, Leer- und
            // <?php-Zeilen zählen nicht, der Schlüssel ist alles
            // vor dem ersten Gleichheitszeichen.
            if(preg_match('/^#/', $line) or preg_match('/^\s*$/', $line) or preg_match('/^<\?php$/', $line)) {
                continue;
            }
            if(preg_match('/^([^=]*)=(.*)/', $line, $matches)) {
                $key = trim($matches[1]);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $duplicates = array_keys(array_filter($counts, static fn(int $count): bool => $count > 1));

        $this->assertSame(
            [],
            $duplicates,
            basename($path).' führt doppelte Schlüssel: '.implode(', ', $duplicates)
                .' - der jeweils zweite Eintrag gewinnt und macht den ersten wirkungslos'
        );
    }
}
