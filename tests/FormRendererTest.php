<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/FakeCatPage.php';

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
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        $this->assertNotNull($schema);
        $this->assertSame('text', $schema['properties']['name']['ui:widget']);
        $this->assertSame('textarea', $schema['properties']['description']['ui:widget']);
        $this->assertSame('opening_hours', $schema['properties']['openingHours']['ui:widget']);

        $html = callPluginMethod($plugin, 'renderTypeFields', ['global', 'LocalBusiness', $schema, []]);

        $this->assertStringContainsString('name="schemaOrgData[global][data][name]"', $html);
        $this->assertStringContainsString('<textarea id="schemaOrgData_global_description"', $html);
        $this->assertStringContainsString('schemaOrgData-opening-hours', $html);
    }

    function testOptionalFieldGetsNoBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        $html = callPluginMethod($plugin, 'renderField', [
            'global', 'description', $schema['properties']['description'], '', $schema, [],
        ]);

        $this->assertStringNotContainsString('schemaOrgData-optional', $html);
        $this->assertStringNotContainsString('schemaOrgData-required', $html);
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
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');
        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef($schema['properties']['address'], $schema);

        $html = callPluginMethod($plugin, 'renderPostalAddressWidget', ['global', 'address', $addressSchema, []]);

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_global_address_addressLocality"[^>]*data-validate="required"[^>]*data-required-message="Pflichtfeld &quot;Ort&quot; fehlt\./',
            $html
        );
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
    /***************************************************************
    *
    * Geerbtes PostalAddress-Sub-Feld (Feature 0.4.1-beta):
    * renderPostalAddressWidget() reicht $inheritedValue/$inheritedLabel
    * an renderAddressSubField() durch - ein leeres streetAddress-Feld
    * erhält den geerbten Wert als Placeholder und das "ü"-Badge.
    *
    ***************************************************************/
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

    function testDebugOutputCheckboxIsUncheckedWhenDisabled(): void {
        $plugin = new \schemaOrgData();
        $html = callPluginMethod($plugin, 'renderExcludedCatsField', [[], false]);

        // Das Eingabefeld darf kein checked-Attribut tragen
        $this->assertMatchesRegularExpression(
            '/name="schemaOrgData\[global\]\[debug_output\]"[^>]*>/',
            $html
        );
        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    // -----------------------------------------------------------
    // renderExistingJsonLdNotice(): Autofill-Button (ADR h/i)
    // -----------------------------------------------------------

    function testAutofillButtonRenderedWhenContentPresent(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();
        // existing_jsonld=true + Inhalt gesetzt → Button soll erscheinen
        callPluginMethod($plugin, 'saveScopeMeta', ['global', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => '{"@type":"LocalBusiness"}',
        ]]);

        $html = callPluginMethod($plugin, 'renderExistingJsonLdNotice', ['global']);

        $this->assertStringContainsString('schemaOrgData-autofill-btn', $html);
        $this->assertStringContainsString('data-target="schemaOrgData_import_global"', $html);
        $this->assertStringContainsString('data-existing-content="{&quot;@type&quot;:&quot;LocalBusiness&quot;}"', $html);
    }

    function testAutofillButtonAbsentWhenContentEmpty(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();
        // existing_jsonld=true, aber kein Inhalt gespeichert → kein Button
        callPluginMethod($plugin, 'saveScopeMeta', ['global', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => '',
        ]]);

        $html = callPluginMethod($plugin, 'renderExistingJsonLdNotice', ['global']);

        $this->assertStringNotContainsString('schemaOrgData-autofill-btn', $html);
    }

    function testAutofillButtonAbsentWhenNoExistingJsonLd(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();
        // existing_jsonld=false → gesamter Notice-Block fehlt → kein Button

        $html = callPluginMethod($plugin, 'renderExistingJsonLdNotice', ['global']);

        $this->assertSame('', $html);
        $this->assertStringNotContainsString('schemaOrgData-autofill-btn', $html);
    }
}
