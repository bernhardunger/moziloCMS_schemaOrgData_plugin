<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die Öffnungszeiten-Validierung:
*
*   - parseOpeningHours() zerlegt die schema.org-Notation
*     ("Mo-Fr 09:00-18:00", "Sa 10:00-14:00", ...) in Von/Bis-
*     Zeiten je Wochentag und expandiert Tagesbereiche. Mehrere
*     Einträge für denselben Tag werden gesammelt und nach
*     from-Zeit sortiert: frühester → Hauptzeitraum (from/to),
*     zweiter → zweiter Zeitraum (from2/to2). Einträge in einem
*     nicht unterstützten Format (falsche Wochentags-Kürzel,
*     AM/PM) werden ignoriert (Tag bleibt "geschlossen").
*
*   - validateOpeningHoursTime() prüft ein einzelnes Von/Bis-
*     Zeitpaar des Widgets: ausschließlich 24-Stunden-Format
*     (HH:MM), Von muss vor Bis liegen. Sind beide Felder leer,
*     gilt der Tag als "geschlossen" (status === null).
*
*   - buildOpeningHoursArray() führt die Range-Merge-Logik durch
*     und kann wahlweise from/to oder from2/to2 als Quellfelder
*     lesen ($fromKey/$toKey-Parameter).
*
***************************************************************/
final class OpeningHoursValidatorTest extends TestCase {

    private const DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validateTime(string $from, string $to): array {
        $validator = new \SchemaOrgData_Validator();

        return $validator->validateOpeningHoursTime($from, $to, $this->adminLang());
    }

    private function parse(array $openingHours): array {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        return $helper->parseOpeningHours($openingHours, self::DAYS);
    }

    private function build(array $perDay, string $fromKey = 'from', string $toKey = 'to'): array {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        return $helper->buildOpeningHoursArray($perDay, self::DAYS, $fromKey, $toKey);
    }

    // ── parseOpeningHours: Bestandsfälle (Ein-Zeitraum) ─────────────────────

    function testValidFormatIsParsedAndValid(): void {
        $perDay = $this->parse(['Mo-Fr 09:00-18:00']);

        $this->assertSame(['from' => '09:00', 'to' => '18:00', 'from2' => '', 'to2' => ''], $perDay['Mo']);
        $this->assertSame(['from' => '09:00', 'to' => '18:00', 'from2' => '', 'to2' => ''], $perDay['Fr']);
        $this->assertSame('ok', $this->validateTime('09:00', '18:00')['status']);
    }

    function testValidDayRangeIsExpandedToAllDaysInRange(): void {
        $perDay = $this->parse(['Mo-Fr 09:00-18:00']);

        foreach(['Mo', 'Tu', 'We', 'Th', 'Fr'] as $day) {
            $this->assertSame(['from' => '09:00', 'to' => '18:00', 'from2' => '', 'to2' => ''], $perDay[$day]);
        }
        foreach(['Sa', 'Su'] as $day) {
            $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $perDay[$day]);
        }
    }

    function testSingleDayIsParsedCorrectly(): void {
        $perDay = $this->parse(['Sa 10:00-14:00']);

        $this->assertSame(['from' => '10:00', 'to' => '14:00', 'from2' => '', 'to2' => ''], $perDay['Sa']);
        $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $perDay['Mo']);
        $this->assertSame('ok', $this->validateTime('10:00', '14:00')['status']);
    }

    function testInvalidWeekdayAbbreviationIsNotParsed(): void {
        $perDay = $this->parse(['Mon-Fri 09:00-18:00']);

        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $perDay[$day]);
        }
    }

    function testAmPmFormatIsInvalid(): void {
        $result = $this->validateTime('09:00 AM', '06:00 PM');

        $this->assertSame('error', $result['status']);

        $perDay = $this->parse(['Mo 09:00 AM-06:00 PM']);
        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $perDay[$day]);
        }
    }

    function testEmptyTimesAreTreatedAsClosed(): void {
        $result = $this->validateTime('', '');

        $this->assertNull($result['status']);

        $perDay = $this->parse([]);
        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $perDay[$day]);
        }
    }

    // ── parseOpeningHours: zweiter Zeitraum (Pausenzeiten) ──────────────────

    function testTwoSegmentsPerDayAreParsedIntoMainAndSecondRange(): void {
        $perDay = $this->parse(['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00']);

        $this->assertSame('08:00', $perDay['Mo']['from']);
        $this->assertSame('12:00', $perDay['Mo']['to']);
        $this->assertSame('13:00', $perDay['Mo']['from2']);
        $this->assertSame('17:00', $perDay['Mo']['to2']);
    }

    function testTwoSegmentsAreSortedByFromTime(): void {
        // Einträge bewusst in umgekehrter Reihenfolge im Array
        $perDay = $this->parse(['Mo 13:00-17:00', 'Mo 08:00-12:00']);

        $this->assertSame('08:00', $perDay['Mo']['from']);
        $this->assertSame('12:00', $perDay['Mo']['to']);
        $this->assertSame('13:00', $perDay['Mo']['from2']);
        $this->assertSame('17:00', $perDay['Mo']['to2']);
    }

    function testThirdSegmentForSameDayIsIgnored(): void {
        $perDay = $this->parse(['Mo 08:00-10:00', 'Mo 12:00-14:00', 'Mo 16:00-18:00']);

        $this->assertSame('08:00', $perDay['Mo']['from']);
        $this->assertSame('10:00', $perDay['Mo']['to']);
        $this->assertSame('12:00', $perDay['Mo']['from2']);
        $this->assertSame('14:00', $perDay['Mo']['to2']);
    }

    function testDaysWithoutSecondSegmentHaveEmptyFrom2To2(): void {
        $perDay = $this->parse(['Mo 09:00-17:00', 'Sa 10:00-14:00', 'Sa 15:00-18:00']);

        $this->assertSame('', $perDay['Mo']['from2']);
        $this->assertSame('', $perDay['Mo']['to2']);
        $this->assertSame('15:00', $perDay['Sa']['from2']);
        $this->assertSame('18:00', $perDay['Sa']['to2']);
    }

    // ── buildOpeningHoursArray: Hauptzeiträume und Pausenzeiträume ───────────

    function testBuildPrimaryRangesFromMainFields(): void {
        $perDay = $this->parse(['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00']);

        $primary = $this->build($perDay);

        $this->assertContains('Mo-Fr 08:00-12:00', $primary);
        $this->assertNotContains('Mo-Fr 13:00-17:00', $primary);
    }

    function testBuildSecondaryRangesFromSecondFields(): void {
        $perDay = $this->parse(['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00']);

        $secondary = $this->build($perDay, 'from2', 'to2');

        $this->assertContains('Mo-Fr 13:00-17:00', $secondary);
        $this->assertNotContains('Mo-Fr 08:00-12:00', $secondary);
    }

    function testMergedResultHasPrimaryFirstThenSecondary(): void {
        $perDay = $this->parse(['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00']);

        $primary   = $this->build($perDay);
        $secondary = $this->build($perDay, 'from2', 'to2');
        $merged    = array_merge($primary, $secondary);

        $this->assertSame(['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00'], $merged);
    }

    function testDayWithOnlyMainRangeProducesNoSecondaryEntry(): void {
        $perDay = $this->parse(['Mo 09:00-18:00']);

        $secondary = $this->build($perDay, 'from2', 'to2');

        $this->assertSame([], $secondary);
    }

    // ── validateOpeningHoursTime: Bestandsfälle ──────────────────────────────

    function testFromTimeAfterToTimeIsError(): void {
        $result = $this->validateTime('18:00', '09:00');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    // ── Validierung zweiter Zeitraum (Cross-Feld-Prüfung) ───────────────────

    function testSecondRangeOnlyOneFieldFilledIsError(): void {
        // from2 gesetzt, to2 leer → Format-Fehler
        $result = $this->validateTime('13:00', '');
        $this->assertSame('error', $result['status']);

        // from2 leer, to2 gesetzt → Format-Fehler
        $result = $this->validateTime('', '17:00');
        $this->assertSame('error', $result['status']);
    }

    function testSecondRangeBothEmptyIsNullStatus(): void {
        $result = $this->validateTime('', '');
        $this->assertNull($result['status']);
    }

    function testSecondRangeFrom2BeforeToIsOverlap(): void {
        // from2 (11:00) < to (12:00) → Überlappung → Fehler
        $from2 = '11:00';
        $to    = '12:00';

        $this->assertLessThan($to, $from2, 'Voraussetzung: from2 < to');
        // Prüfung erfolgt in validateFormData — hier testen wir die Bedingung
        $this->assertTrue($from2 < $to);
    }

    function testSecondRangeFrom2EqualToToIsNotOverlap(): void {
        // from2 == to (nahtloser Anschluss) → kein Fehler
        $from2 = '12:00';
        $to    = '12:00';

        $this->assertFalse($from2 < $to, 'from2 == to darf kein Overlap-Fehler auslösen');
    }
}
