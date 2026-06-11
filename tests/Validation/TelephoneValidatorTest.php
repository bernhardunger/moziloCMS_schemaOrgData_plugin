<?php

namespace SchemaOrgData\Tests\Validation;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für validateTelephone() - Prüfung der Telefonnummer
* (Normalisierung + E.164-Format, länderunabhängig).
*
* Die Eingabe wird vor der Prüfung normalisiert
* (preg_replace('/[^0-9+]/', '', $input)) und anschließend gegen
* /^(\+|00)[1-9][0-9]{6,14}$/ geprüft. E.164 ist ein
* internationaler Standard - die Prüfung gilt unabhängig vom
* hinterlegten addressCountry für alle Länder (siehe CLAUDE.md,
* Abschnitt "Telefon").
*
***************************************************************/
final class TelephoneValidatorTest extends TestCase {

    private function validate(string $value, string $countryCode = 'DE'): array {
        $plugin = new \schemaOrgData();

        return callPluginMethod($plugin, 'validateTelephone', [$value, $countryCode]);
    }

    function testNumberWithPlusPrefixIsOk(): void {
        $result = $this->validate('+49 89 123456');

        $this->assertSame('ok', $result['status']);
    }

    function testNumberWithDoubleZeroPrefixIsOk(): void {
        $result = $this->validate('0049 89 123456');

        $this->assertSame('ok', $result['status']);
    }

    function testNormalizationRemovesHyphensBeforeCheck(): void {
        $result = $this->validate('+49 89 123-456');

        $this->assertSame('ok', $result['status']);
    }

    function testNormalizationRemovesParenthesesBeforeCheck(): void {
        $result = $this->validate('+49 (89) 123 456');

        $this->assertSame('ok', $result['status']);
    }

    function testNumberWithoutPlusOrDoubleZeroIsError(): void {
        $result = $this->validate('49 89 123456');

        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['message']);
    }

    function testTooShortNumberIsError(): void {
        $result = $this->validate('+49 1');

        $this->assertSame('error', $result['status']);
    }

    function testTooLongNumberIsError(): void {
        // 16 Ziffern nach dem "+" -> mehr als die zulässigen 1 + 14 Ziffern
        $result = $this->validate('+4123456789012345');

        $this->assertSame('error', $result['status']);
    }

    function testEmptyValueIsNotValidated(): void {
        $result = $this->validate('');

        // Leeres Feld ist optional - kein Fehler, sondern "nicht geprüft"
        $this->assertNull($result['status']);
        $this->assertNull($result['message']);
    }

    function testValidNumberOfOtherCountryIsOk(): void {
        $result = $this->validate('+33 1 23 45 67 89', 'FR');

        $this->assertSame('ok', $result['status']);
    }

    function testInvalidNumberIsErrorRegardlessOfCountry(): void {
        $result = $this->validate('keine-nummer', 'FR');

        $this->assertSame('error', $result['status']);
    }
}
