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

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function weekdayLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/cms_language_deDE.txt');
    }

    /***************************************************************
    *
    * Repliziert resolveAvailableGlobalFragments() über die reale
    * (private) settings-Property der Fassade-Instanz.
    *
    ***************************************************************/
    private function availableFragments(\schemaOrgData $plugin): array {
        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $settings = $ref->getValue($plugin);

        return (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang()
        );
    }

    function testKnownSchemaTypeRendersExpectedWidgetTypes(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        $this->assertNotNull($schema);
        $this->assertSame('text', $schema['properties']['name']['ui:widget']);
        $this->assertSame('textarea', $schema['properties']['description']['ui:widget']);
        $this->assertSame('opening_hours', $schema['properties']['openingHours']['ui:widget']);

        $html = (new \SchemaOrgData_FormRenderer())->renderTypeFields(
            'global', 'LocalBusiness', $schema, [], null, null, ['data' => [], 'originLabel' => []],
            new \SchemaOrgData_DataSplitHelper(), $this->adminLang(), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', $plugin->PLUGIN_SELF_URL, new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_Validator(), $this->weekdayLang(), $this->availableFragments($plugin)
        );

        $this->assertStringContainsString('name="schemaOrgData[global][data][name]"', $html);
        $this->assertStringContainsString('<textarea id="schemaOrgData_global_description"', $html);
        $this->assertStringContainsString('schemaOrgData-opening-hours', $html);
    }

    function testOptionalFieldGetsNoBadge(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'global', 'description', $schema['properties']['description'], '', $schema, [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(),
            $this->availableFragments($plugin)
        );

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

        $html = (new \SchemaOrgData_FormRenderer())->renderPostalAddressWidget(
            'global', 'address', $addressSchema, [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(), 'deDE'
        );

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
}
