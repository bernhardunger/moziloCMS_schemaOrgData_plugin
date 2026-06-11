<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für die Öffnungszeiten-Validierung:
*
*   - parseOpeningHours() zerlegt die schema.org-Notation
*     ("Mo-Fr 09:00-18:00", "Sa 10:00-14:00", ...) in Von/Bis-
*     Zeiten je Wochentag und expandiert Tagesbereiche. Einträge
*     in einem nicht unterstützten Format (falsche Wochentags-
*     Kürzel, AM/PM) werden ignoriert (Tag bleibt "geschlossen").
*
*   - validateOpeningHoursTime() prüft ein einzelnes Von/Bis-
*     Zeitpaar des Widgets: ausschließlich 24-Stunden-Format
*     (HH:MM), Von muss vor Bis liegen. Sind beide Felder leer,
*     gilt der Tag als "geschlossen" (status === null).
*
***************************************************************/
final class OpeningHoursValidatorTest extends TestCase {

    private const DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    private function validateTime(string $from, string $to): array {
        $plugin = new \schemaOrgData();

        return callPluginMethod($plugin, 'validateOpeningHoursTime', [$from, $to]);
    }

    private function parse(array $openingHours): array {
        $plugin = new \schemaOrgData();

        return callPluginMethod($plugin, 'parseOpeningHours', [$openingHours, self::DAYS]);
    }

    function testValidFormatIsParsedAndValid(): void {
        $perDay = $this->parse(['Mo-Fr 09:00-18:00']);

        $this->assertSame(['from' => '09:00', 'to' => '18:00'], $perDay['Mo']);
        $this->assertSame(['from' => '09:00', 'to' => '18:00'], $perDay['Fr']);
        $this->assertSame('ok', $this->validateTime('09:00', '18:00')['status']);
    }

    function testValidDayRangeIsExpandedToAllDaysInRange(): void {
        $perDay = $this->parse(['Mo-Fr 09:00-18:00']);

        foreach(['Mo', 'Tu', 'We', 'Th', 'Fr'] as $day) {
            $this->assertSame(['from' => '09:00', 'to' => '18:00'], $perDay[$day]);
        }
        foreach(['Sa', 'Su'] as $day) {
            $this->assertSame(['from' => '', 'to' => ''], $perDay[$day]);
        }
    }

    function testSingleDayIsParsedCorrectly(): void {
        $perDay = $this->parse(['Sa 10:00-14:00']);

        $this->assertSame(['from' => '10:00', 'to' => '14:00'], $perDay['Sa']);
        $this->assertSame(['from' => '', 'to' => ''], $perDay['Mo']);
        $this->assertSame('ok', $this->validateTime('10:00', '14:00')['status']);
    }

    function testFromTimeAfterToTimeIsError(): void {
        $result = $this->validateTime('18:00', '09:00');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testInvalidWeekdayAbbreviationIsNotParsed(): void {
        $perDay = $this->parse(['Mon-Fri 09:00-18:00']);

        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => ''], $perDay[$day]);
        }
    }

    function testAmPmFormatIsInvalid(): void {
        $result = $this->validateTime('09:00 AM', '06:00 PM');

        $this->assertSame('error', $result['status']);

        $perDay = $this->parse(['Mo 09:00 AM-06:00 PM']);
        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => ''], $perDay[$day]);
        }
    }

    function testEmptyTimesAreTreatedAsClosed(): void {
        $result = $this->validateTime('', '');

        $this->assertNull($result['status']);

        $perDay = $this->parse([]);
        foreach(self::DAYS as $day) {
            $this->assertSame(['from' => '', 'to' => ''], $perDay[$day]);
        }
    }
}
