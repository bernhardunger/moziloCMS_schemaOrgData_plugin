<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für das schema-getriebene Rendering des Admin-Formulars:
*
*   - loadSchema() liefert die Widget-Metadaten ("ui:widget") aus
*     der jeweiligen schemas/{Type}.json bzw. null für unbekannte
*     Types
*   - renderTypeFields() erzeugt für jedes Property das passende
*     Widget (text, textarea, select, opening_hours, ...)
*   - renderField() kennzeichnet Pflichtfelder ("ui:required")
*     bzw. optionale Felder anhand von renderRequiredBadge()
*   - renderPostalAddressWidget() rendert alle Felder von
*     PostalAddress inkl. addressCountry-Vorauswahl "DE"
*   - renderOpeningHoursWidget() rendert alle sieben Wochentage
*
***************************************************************/
final class FormRendererTest extends TestCase {

    function testKnownSchemaTypeRendersExpectedWidgetTypes(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $this->assertNotNull($schema);
        $this->assertSame('text', $schema['properties']['name']['ui:widget']);
        $this->assertSame('textarea', $schema['properties']['description']['ui:widget']);
        $this->assertSame('select', $schema['properties']['priceRange']['ui:widget']);
        $this->assertSame('opening_hours', $schema['properties']['openingHours']['ui:widget']);

        $html = callPluginMethod($plugin, 'renderTypeFields', ['global', 'LocalBusiness', $schema, []]);

        $this->assertStringContainsString('name="schemaOrgData[global][data][name]"', $html);
        $this->assertStringContainsString('<textarea id="schemaOrgData_global_description"', $html);
        $this->assertStringContainsString('name="schemaOrgData[global][data][priceRange]"', $html);
        $this->assertStringContainsString('schemaOrgData-opening-hours', $html);
    }

    function testRequiredFieldIsRenderedAsRequired(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderField', [
            'global', 'name', $schema['properties']['name'], '', $schema, [],
        ]);

        $this->assertStringContainsString('schemaOrgData-required', $html);
        $this->assertStringNotContainsString('schemaOrgData-optional', $html);
    }

    function testOptionalFieldGetsOptionalBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderField', [
            'global', 'description', $schema['properties']['description'], '', $schema, [],
        ]);

        $this->assertStringContainsString('schemaOrgData-optional', $html);
        $this->assertStringNotContainsString('schemaOrgData-required', $html);
    }

    function testUnknownSchemaTypeReturnsNull(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['NichtVorhandenerType']);

        $this->assertNull($schema);
    }

    function testPostalAddressWidgetContainsAllFiveAddressFields(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);
        $addressSchema = callPluginMethod($plugin, 'resolveSchemaRef', [$schema['properties']['address'], $schema]);

        $html = callPluginMethod($plugin, 'renderPostalAddressWidget', ['global', 'address', $addressSchema, []]);

        foreach(['streetAddress', 'postalCode', 'addressLocality', 'addressRegion', 'addressCountry'] as $field) {
            $this->assertStringContainsString('schemaOrgData_global_address_'.$field, $html);
        }
    }

    function testAddressCountrySelectContainsGermanyAsDefault(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);
        $addressSchema = callPluginMethod($plugin, 'resolveSchemaRef', [$schema['properties']['address'], $schema]);

        $html = callPluginMethod($plugin, 'renderPostalAddressWidget', ['global', 'address', $addressSchema, []]);

        $this->assertMatchesRegularExpression('/<option value="DE" selected="selected">[^<]*<\/option>/', $html);
    }

    function testOpeningHoursWidgetContainsAllSevenWeekdays(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderOpeningHoursWidget', [
            'global', 'openingHours', $schema['properties']['openingHours'], [],
        ]);

        foreach(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $day) {
            $this->assertStringContainsString('schemaOrgData_global_openingHours_'.$day.'_from', $html);
            $this->assertStringContainsString('schemaOrgData_global_openingHours_'.$day.'_to', $html);
        }
    }
}
