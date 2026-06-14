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

    function testOptionalFieldGetsNoBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderField', [
            'global', 'description', $schema['properties']['description'], '', $schema, [],
        ]);

        $this->assertStringNotContainsString('schemaOrgData-optional', $html);
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

    /***************************************************************
    *
    * Regressionstest für die addressLocality-Pflichtfeld-Validierung
    * (Fix in 0.2.0-beta): das Feld muss data-validate="required" und
    * eine vollständig aufgelöste data-required-message tragen, damit
    * validator.js beim Blur sofort "Pflichtfeld "Ort" fehlt." anzeigt.
    *
    ***************************************************************/
    function testAddressLocalityFieldHasRequiredValidationAttributes(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);
        $addressSchema = callPluginMethod($plugin, 'resolveSchemaRef', [$schema['properties']['address'], $schema]);

        $html = callPluginMethod($plugin, 'renderPostalAddressWidget', ['global', 'address', $addressSchema, []]);

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_global_address_addressLocality"[^>]*data-validate="required"[^>]*data-required-message="Pflichtfeld &quot;Ort&quot; fehlt\./',
            $html
        );
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

    /***************************************************************
    *
    * get_CatArray(true) liefert auch das Wurzelverzeichnis
    * "kategorien" selbst als Eintrag zurück - das ist keine echte
    * Kategorie und darf in der Ausschlussliste nicht als Checkbox
    * erscheinen (nur echte Kategorien + "Alle Kategorien"-Toggle).
    *
    ***************************************************************/
    /***************************************************************
    *
    * resolveInheritableFields() (Feature 0.4.1-beta): Für eine
    * Kategorie-Sektion liefert die Methode die Properties der
    * globalen Konfiguration desselben Types als "geerbte" Werte
    * inkl. Ursprungs-Label "Global" - rein zur Anzeige
    * (Placeholder + "ü"-Badge), nicht zum Befüllen.
    *
    ***************************************************************/
    function testResolveInheritableFieldsReturnsGlobalValuesForCategoryScope(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => [
                'name' => 'Global GmbH',
                'telephone' => '+49 89 12345678',
            ],
        ]);

        $result = callPluginMethod($plugin, 'resolveInheritableFields', ['category', 'testkat', null, 'LocalBusiness']);

        $this->assertSame('+49 89 12345678', $result['data']['telephone']);
        $this->assertSame('Global', $result['originLabel']['telephone']);
    }

    function testResolveInheritableFieldsReturnsEmptyArraysForGlobalScope(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['telephone' => '+49 89 12345678']]);

        $result = callPluginMethod($plugin, 'resolveInheritableFields', ['global', null, null, 'LocalBusiness']);

        $this->assertSame([], $result['data']);
        $this->assertSame([], $result['originLabel']);
    }

    /***************************************************************
    *
    * Geerbtes Feld (Feature 0.4.1-beta): renderField() rendert für
    * ein leeres Feld, dessen Wert von einer übergeordneten Ebene
    * geerbt würde, einen grauen Placeholder mit dem geerbten Wert
    * und ein "ü"-Badge mit Herkunfts-Tooltip - das Feld selbst
    * bleibt leer (value=""), damit die feldweise Vererbung aus
    * 0.2.4-beta beim Speichern unverändert bleibt.
    *
    ***************************************************************/
    function testInheritedFieldStaysEmptyButShowsPlaceholderAndBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderField', [
            'category', 'telephone', $schema['properties']['telephone'], '', $schema, [], null,
            '+49 89 12345678', 'Global',
        ]);

        $this->assertStringContainsString('value=""', $html);
        $this->assertStringContainsString('placeholder="+49 89 12345678"', $html);
        $this->assertStringContainsString('schemaOrgData-inherited', $html);
        $this->assertStringContainsString('&Uuml;bernommen von: Global', $html);
    }

    function testFieldWithOwnValueDoesNotShowInheritedBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);

        $html = callPluginMethod($plugin, 'renderField', [
            'category', 'telephone', $schema['properties']['telephone'], '+49 30 99999999', $schema, [], null,
            '+49 89 12345678', 'Global',
        ]);

        $this->assertStringContainsString('value="+49 30 99999999"', $html);
        $this->assertStringNotContainsString('schemaOrgData-inherited', $html);
    }

    /***************************************************************
    *
    * Geerbtes PostalAddress-Sub-Feld (Feature 0.4.1-beta):
    * renderPostalAddressWidget() reicht $inheritedValue/$inheritedLabel
    * an renderAddressSubField() durch - ein leeres streetAddress-Feld
    * erhält den geerbten Wert als Placeholder und das "ü"-Badge.
    *
    ***************************************************************/
    function testInheritedAddressSubFieldRendersPlaceholderAndBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = callPluginMethod($plugin, 'loadSchema', ['LocalBusiness']);
        $addressSchema = callPluginMethod($plugin, 'resolveSchemaRef', [$schema['properties']['address'], $schema]);

        $inheritedAddress = ['streetAddress' => 'Globalweg 1', 'addressLocality' => 'Globalstadt', 'addressCountry' => 'DE'];

        $html = callPluginMethod($plugin, 'renderPostalAddressWidget', [
            'category', 'address', $addressSchema, [], 'cat_testkat', $inheritedAddress, 'Global',
        ]);

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_cat_testkat_address_streetAddress"[^>]*value=""[^>]*placeholder="Globalweg 1"/',
            $html
        );
        $this->assertStringContainsString('schemaOrgData-inherited', $html);
        $this->assertStringContainsString('&Uuml;bernommen von: Global', $html);
    }

    /***************************************************************
    *
    * Erzeugt ein Plugin mit isoliertem InMemorySettings-Stub als
    * $this->settings, damit resolveInheritableFields() (über
    * loadScopeConfig()) ohne Zugriff auf die echte plugin.conf.php
    * getestet werden kann (siehe JsonLdOutputTest::createPlugin()).
    *
    * @return array{0: \schemaOrgData, 1: \InMemorySettings}
    *
    ***************************************************************/
    private function pluginWithInMemorySettings(): array {
        $plugin = new \schemaOrgData();
        $settings = new \InMemorySettings();

        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $settings);

        return [$plugin, $settings];
    }

    function testExcludedCatsFieldOmitsKategorienRootEntry(): void {
        global $CatPage;
        $CatPage = new FakeCatPage(['kategorien', 'ueber-uns', 'impressum']);

        $plugin = new \schemaOrgData();
        $html = callPluginMethod($plugin, 'renderExcludedCatsField', [[]]);

        unset($CatPage);

        $this->assertStringContainsString('value="ueber-uns"', $html);
        $this->assertStringContainsString('value="impressum"', $html);
        $this->assertStringNotContainsString('value="kategorien"', $html);
        $this->assertStringContainsString('data-select-all="schemaOrgData[global][excluded_cats][]"', $html);
    }
}

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Klasse CatPage, ausschließlich
* für renderExcludedCatsField() (get_CatArray()).
*
***************************************************************/
final class FakeCatPage {

    function __construct(private array $cats) {
    }

    function get_CatArray(bool $all = false): array {
        return $this->cats;
    }
}
