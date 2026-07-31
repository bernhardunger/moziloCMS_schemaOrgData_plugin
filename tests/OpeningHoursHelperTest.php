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

    function testRueckwaertsLaufenderTagesbereichLaeuftUeberDasWochenende(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['Fr-Mo 09:00-17:00'], self::DAYS);

        foreach(['Fr', 'Sa', 'Su', 'Mo'] as $day) {
            $this->assertSame(['from' => '09:00', 'to' => '17:00', 'from2' => '', 'to2' => ''], $result[$day]);
        }
        foreach(['Tu', 'We', 'Th'] as $day) {
            $this->assertSame(['from' => '', 'to' => '', 'from2' => '', 'to2' => ''], $result[$day]);
        }
    }

    function testKleingeschriebenesTageskuerzelWirdErkannt(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->parseOpeningHours(['mo 09:00-17:00'], self::DAYS);

        $this->assertSame(['from' => '09:00', 'to' => '17:00', 'from2' => '', 'to2' => ''], $result['Mo']);
    }

    /***************************************************************
    *
    * Der Bauer gruppiert ausschließlich in $days-Reihenfolge und kann
    * deshalb keinen über das Wochenende laufenden Bereich erzeugen.
    * Das Ergebnis ist semantisch gleichwertig zur Eingabe, aber nicht
    * bytegleich - die Erwartung ist bewusst exakt festgenagelt, damit
    * niemand sie später zur Eingabeform "zurückrepariert".
    *
    ***************************************************************/
    function testRoundtripEinesWochenendbereichsErgibtZweiEintraege(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $perDay = $helper->parseOpeningHours(['Fr-Mo 09:00-17:00'], self::DAYS);
        $result = $helper->buildOpeningHoursArray($perDay, self::DAYS);

        $this->assertSame(['Mo 09:00-17:00', 'Fr-Su 09:00-17:00'], $result);
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

    // -----------------------------------------------------------
    // buildOpeningHoursSpecifications()
    // -----------------------------------------------------------

    function testTagesbereichWirdZuEinemObjektMitMehrerenDayOfWeekUris(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['Mo-Fr 09:00-18:00'], self::DAYS);

        $this->assertSame([[
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => [
                'https://schema.org/Monday',
                'https://schema.org/Tuesday',
                'https://schema.org/Wednesday',
                'https://schema.org/Thursday',
                'https://schema.org/Friday',
            ],
            'opens'  => '09:00',
            'closes' => '18:00',
        ]], $result);
    }

    function testEinzeltagLiefertDayOfWeekTrotzdemAlsArray(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['Sa 10:00-14:00'], self::DAYS);

        $this->assertSame([['https://schema.org/Saturday']], array_column($result, 'dayOfWeek'));
    }

    function testZweiZeitraeumeErgebenZweiObjekteInEingabereihenfolge(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        // Gespeicherte Realform: array_merge(Hauptzeitraum, zweiter Zeitraum).
        $result = $helper->buildOpeningHoursSpecifications(
            ['Mo-Fr 09:00-12:00', 'Mo-Fr 14:00-18:00'],
            self::DAYS
        );

        $this->assertCount(2, $result);
        $this->assertSame([['09:00', '12:00'], ['14:00', '18:00']], [
            [$result[0]['opens'], $result[0]['closes']],
            [$result[1]['opens'], $result[1]['closes']],
        ]);
    }

    /***************************************************************
    *
    * Abgrenzung zu parseOpeningHours(): jene Methode kennt je Tag nur
    * zwei Zeiträume und verwirft den dritten. Der Serialisierer setzt
    * am kompakten Array an und bildet deshalb jeden Eintrag ab.
    *
    ***************************************************************/
    function testDreiZeitraeumeAmSelbenTagErgebenDreiObjekte(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $entries = ['Mo 08:00-10:00', 'Mo 12:00-14:00', 'Mo 18:00-20:00'];

        $result = $helper->buildOpeningHoursSpecifications($entries, self::DAYS);

        $this->assertCount(3, $result);
        $this->assertSame(['08:00', '12:00', '18:00'], array_column($result, 'opens'));
        $this->assertSame(['20:00'], [$result[2]['closes']]);
    }

    function testUngueltigeNotationWirdUebersprungen(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(
            ['Montag 9-18', '', 'Mo 09:00-18:00', ['Mo']],
            self::DAYS
        );

        $this->assertCount(1, $result);
        $this->assertSame(['https://schema.org/Monday'], $result[0]['dayOfWeek']);
    }

    function testUnbekanntesTageskuerzelWirdUebersprungen(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['Xx 09:00-18:00'], self::DAYS);

        $this->assertSame([], $result);
    }

    /***************************************************************
    *
    * Ein Kürzel, das zwar in $days steht, aber keine kanonische
    * schema.org-URI hat, lässt den Tag entfallen - hier bleibt vom
    * Bereich kein Tag übrig, also entfällt der ganze Eintrag.
    *
    ***************************************************************/
    function testTagOhneKanonischeUriEntfaelltOhneException(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['Zz 09:00-18:00'], ['Zz']);

        $this->assertSame([], $result);
    }

    function testRueckwaertsLaufenderTagesbereichZaehltUeberDasWochenendeAuf(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['Fr-Mo 09:00-17:00'], self::DAYS);

        $this->assertCount(1, $result);
        $this->assertSame([
            'https://schema.org/Friday',
            'https://schema.org/Saturday',
            'https://schema.org/Sunday',
            'https://schema.org/Monday',
        ], $result[0]['dayOfWeek']);
    }

    function testKleingeschriebenesTageskuerzelLiefertKanonischeUri(): void {
        $helper = new \SchemaOrgData_OpeningHoursHelper();

        $result = $helper->buildOpeningHoursSpecifications(['mo 09:00-17:00'], self::DAYS);

        $this->assertCount(1, $result);
        $this->assertSame(['https://schema.org/Monday'], $result[0]['dayOfWeek']);
    }
}
