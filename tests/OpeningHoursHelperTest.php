<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_OpeningHoursHelper.
* Zustandslose Instanz ohne Abhängigkeiten - reine
* Array-/String-Transformationen, kein Bezug zu CMS-Globals.
*
***************************************************************/
final class OpeningHoursHelperTest extends TestCase {

    private const DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    // -----------------------------------------------------------
    // isPerDayOpeningHoursValue()
    // -----------------------------------------------------------

    function testErkenntRohePerTagWerte(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $this->assertTrue($helper->isPerDayOpeningHoursValue([
            'Mo' => ['from' => '09:00', 'to' => '18:00'],
        ]));
    }

    function testErkenntSchemaOrgNotationNichtAlsPerTagWerte(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $this->assertFalse($helper->isPerDayOpeningHoursValue(['Mo-Fr 09:00-18:00']));
    }

    function testLeeresArrayIstKeinePerTagWertMenge(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $this->assertFalse($helper->isPerDayOpeningHoursValue([]));
    }

    // -----------------------------------------------------------
    // parseOpeningHours()
    // -----------------------------------------------------------

    function testEinzeltagWirdKorrektGeparst(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['Sa 10:00-14:00'], self::DAYS);

        $this->assertSame(['from' => '10:00', 'to' => '14:00', 'from2' => '', 'to2' => ''], $result['Sa']);
        $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $result['Mo']);
    }

    function testTagesbereichWirdAufAlleTageImBereichAngewendet(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['Mo-Fr 09:00-18:00'], self::DAYS);

        foreach(['Mo', 'Tu', 'We', 'Th', 'Fr'] as $day) {
            $this->assertSame(['from' => '09:00', 'to' => '18:00', 'from2' => '', 'to2' => ''], $result[$day]);
        }
        $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $result['Sa']);
    }

    function testZweiEintraegeProTagErgebenHauptUndZweitenZeitraum(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['Mo 09:00-12:00', 'Mo 13:00-18:00'], self::DAYS);

        $this->assertSame([
            'from' => '09:00', 'to' => '12:00',
            'from2' => '13:00', 'to2' => '18:00',
        ], $result['Mo']);
    }

    function testDritterEintragProTagWirdIgnoriert(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(
            ['Mo 08:00-09:00', 'Mo 09:00-12:00', 'Mo 13:00-18:00'],
            self::DAYS
        );

        $this->assertSame([
            'from' => '08:00', 'to' => '09:00',
            'from2' => '09:00', 'to2' => '12:00',
        ], $result['Mo'], 'Der dritte Eintrag (13:00-18:00) liegt außerhalb des Widget-Scopes und wird verworfen');
    }

    function testUngueltigesFormatWirdUebersprungen(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['nicht geschlossen', 'Mo 09:00-18:00'], self::DAYS);

        $this->assertSame(['from' => '09:00', 'to' => '18:00', 'from2' => '', 'to2' => ''], $result['Mo']);
    }

    // -----------------------------------------------------------
    // buildOpeningHoursArray()
    // -----------------------------------------------------------

    function testZusammenhaengendeTageErgebenEinenBereich(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $perDay = [
            'Mo' => ['from' => '09:00', 'to' => '18:00'],
            'Tu' => ['from' => '09:00', 'to' => '18:00'],
            'We' => ['from' => '09:00', 'to' => '18:00'],
        ];

        $result = $helper->buildOpeningHoursArray($perDay, ['Mo', 'Tu', 'We']);

        $this->assertSame(['Mo-We 09:00-18:00'], $result);
    }

    function testUnterbrocheneTageErgebenMehrereBereiche(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $perDay = [
            'Mo' => ['from' => '09:00', 'to' => '18:00'],
            'Tu' => ['from' => '09:00', 'to' => '18:00'],
            'We' => [],
            'Th' => ['from' => '09:00', 'to' => '18:00'],
        ];

        $result = $helper->buildOpeningHoursArray($perDay, ['Mo', 'Tu', 'We', 'Th']);

        $this->assertSame(['Mo-Tu 09:00-18:00', 'Th 09:00-18:00'], $result);
    }

    function testFromKeyUndToKeyAdressierenZweitenZeitraum(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $perDay = [
            'Mo' => ['from' => '09:00', 'to' => '12:00', 'from2' => '13:00', 'to2' => '18:00'],
        ];

        $result = $helper->buildOpeningHoursArray($perDay, ['Mo'], 'from2', 'to2');

        $this->assertSame(['Mo 13:00-18:00'], $result);
    }

    function testLeereTageWerdenAusgelassen(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $perDay = [
            'Mo' => ['from' => '09:00', 'to' => '18:00'],
            'Tu' => [],
        ];

        $result = $helper->buildOpeningHoursArray($perDay, ['Mo', 'Tu']);

        $this->assertSame(['Mo 09:00-18:00'], $result);
    }
}
