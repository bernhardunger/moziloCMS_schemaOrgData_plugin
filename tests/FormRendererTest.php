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
    * ProfessionalService.json fehlten address/openingHours/image im
    * Vergleich zu den übrigen Mitgliedern
    * der LocalBusiness-Familie (LocalBusiness/LegalService/
    * MedicalBusiness/AccountingService) - Nachtrag, 1:1 aus
    * LegalService.json übernommen.
    *
    ***************************************************************/
    function testProfessionalServiceSchemaEnthaeltAdressOeffnungszeitenBildFelder(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'ProfessionalService');

        $this->assertNotNull($schema);
        $this->assertSame('opening_hours', $schema['properties']['openingHours']['ui:widget']);
        $this->assertSame('text', $schema['properties']['image']['ui:widget']);

        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef($schema['properties']['address'], $schema);
        $this->assertSame('postal_address', $addressSchema['ui:widget']);

        $html = (new \SchemaOrgData_FormRenderer())->renderTypeFields(
            'global', 'ProfessionalService', $schema, [], null, null, ['data' => [], 'originLabel' => []],
            new \SchemaOrgData_DataSplitHelper(), $this->adminLang(), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', $plugin->PLUGIN_SELF_URL, new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_Validator(), $this->weekdayLang(), $this->availableFragments($plugin)
        );

        $this->assertStringContainsString('schemaOrgData-opening-hours', $html);
        $this->assertStringContainsString('name="schemaOrgData[global][data][image]"', $html);
        $this->assertStringContainsString('schemaOrgData_global_address_addressLocality', $html);
    }

    /***************************************************************
    *
    * JobPosting.json erhält hiringOrganization (id_reference_or_literal,
    * analog Event.organizer) und jobLocation
    * (place-Widget, analog Event.location) - beide gemäß Google-
    * Richtlinie für JobPosting als Pflichtfeld.
    *
    ***************************************************************/
    function testJobPostingSchemaEnthaeltHiringOrganizationUndJobLocation(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'JobPosting');

        $this->assertNotNull($schema);
        $this->assertSame(['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'], $schema['required']);
        $this->assertSame('id_reference_or_literal', $schema['properties']['hiringOrganization']['ui:widget']);
        $this->assertTrue((bool) $schema['properties']['hiringOrganization']['ui:required']);
        $this->assertSame('place', $schema['properties']['jobLocation']['ui:widget']);
        $this->assertTrue((bool) $schema['properties']['jobLocation']['ui:required']);

        $html = (new \SchemaOrgData_FormRenderer())->renderTypeFields(
            'page', 'JobPosting', $schema, [], null, null, ['data' => [], 'originLabel' => []],
            new \SchemaOrgData_DataSplitHelper(), $this->adminLang(), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', $plugin->PLUGIN_SELF_URL, new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_Validator(), $this->weekdayLang(), $this->availableFragments($plugin)
        );

        $this->assertStringContainsString('name="schemaOrgData[page][data][hiringOrganization]', $html);
        $this->assertStringContainsString('name="schemaOrgData[page][data][jobLocation][address][addressLocality]"', $html);
    }

    /***************************************************************
    *
    * Regressionstest für die addressLocality-Pflichtfeld-Validierung:
    * das Feld muss data-validate="address_required" (gruppen-bedingte
    * Live-Pflicht, siehe js/validator.js checkAddressRequiredField())
    * und eine vollständig aufgelöste data-required-message tragen,
    * damit validator.js "Pflichtfeld "Ort" fehlt." anzeigt, sobald
    * mindestens ein anderes Feld der Adressgruppe befüllt ist. Vor
    * diesem Test trug das Feld unconditional data-validate="required" -
    * das führte zu einer fälschlichen Live-Meldung bei einer komplett
    * leeren, nicht als Ganzes ui:required markierten Adresse
    * (Regressionsfall 8.12, siehe testAddressLocalityFieldTraegtAdressgruppenId()).
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
            '/id="schemaOrgData_global_address_addressLocality"[^>]*data-address-group="schemaOrgData_global_address"[^>]*data-validate="address_required"[^>]*data-required-message="Pflichtfeld &quot;Ort&quot; fehlt\./',
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest 8.12: streetAddress/postalCode/addressRegion tragen
    * dieselbe data-address-group-Id wie addressLocality (nicht
    * addressCountry, das per "default" immer einen Wert hat und daher
    * für die "wurde überhaupt etwas ausgefüllt"-Prüfung ausgenommen
    * bleibt - siehe SchemaOrgData_Validator::isAddressProvided()).
    *
    ***************************************************************/
    function testAddressGroupIdVerbindetAlleSubfelderAusserAddressCountry(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'LocalBusiness');
        $addressSchema = (new \SchemaOrgData_SchemaRepository())->resolveSchemaRef($schema['properties']['address'], $schema);

        $html = (new \SchemaOrgData_FormRenderer())->renderPostalAddressWidget(
            'global', 'address', $addressSchema, [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(), 'deDE'
        );

        foreach(['streetAddress', 'postalCode', 'addressLocality', 'addressRegion'] as $field) {
            $this->assertMatchesRegularExpression(
                '/id="schemaOrgData_global_address_'.$field.'"[^>]*data-address-group="schemaOrgData_global_address"/',
                $html
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/id="schemaOrgData_global_address_addressCountry"[^>]*data-address-group/',
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest 8.12: Event.location (place-Widget, ui:required
    * false) darf "Ort" nicht unconditional als Pflichtfeld rendern -
    * addressLocality erhält data-validate="address_required" statt
    * "required", und das Geschwisterfeld "name" trägt dieselbe
    * data-address-group-Id wie die verschachtelte Adresse, damit
    * checkAddressRequiredField() ein befülltes "name"-Feld ebenfalls
    * als "Adresse wurde angefasst" erkennt.
    *
    ***************************************************************/
    function testPlaceWidgetOhneWidgetweitesRequiredNutztAddressRequired(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'Event');
        $locationSchema = $schema['properties']['location'];

        $html = (new \SchemaOrgData_FormRenderer())->renderPlaceWidget(
            'page', 'location', $locationSchema, [], $schema, null,
            $this->adminLang(), new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_Validator(), 'deDE'
        );

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_page_location_address_addressLocality"[^>]*data-validate="address_required"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_page_location_name"[^>]*data-address-group="schemaOrgData_page_location_address"/',
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest 8.13 (Gegenprobe zu 8.12): JobPosting.jobLocation
    * ist als Ganzes ui:required - hier muss addressLocality weiterhin
    * unconditional data-validate="required" tragen (renderField() reicht
    * das ui:required-Flag des Widgets als $forceRequired durch), damit
    * ein komplett leeres Pflicht-Widget live erzwungen bleibt.
    *
    ***************************************************************/
    function testForceRequiredPlaceWidgetNutztWeiterhinUnconditionalRequired(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'JobPosting');

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'jobLocation', $schema['properties']['jobLocation'], [], $schema, [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), []
        );

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_page_jobLocation_address_addressLocality"[^>]*data-validate="required"/',
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
    * resolveInheritableFields(): Für eine
    * Kategorie-Sektion liefert die Methode die Properties der
    * globalen Konfiguration desselben Types als "geerbte" Werte
    * inkl. Ursprungs-Label "Global" - rein zur Anzeige
    * (Placeholder + "ü"-Badge), nicht zum Befüllen.
    *
    ***************************************************************/
    /***************************************************************
    *
    * Geerbtes Feld: renderField() rendert für ein leeres Feld,
    * dessen Wert von einer übergeordneten Ebene geerbt würde, einen
    * grauen Placeholder mit dem geerbten Wert und ein "ü"-Badge mit
    * Herkunfts-Tooltip - das Feld selbst bleibt leer (value=""),
    * damit die feldweise Vererbung beim Speichern unverändert bleibt.
    *
    ***************************************************************/
    /***************************************************************
    *
    * Geerbtes PostalAddress-Sub-Feld: renderPostalAddressWidget()
    * reicht $inheritedValue/$inheritedLabel
    * an renderAddressSubField() durch - ein leeres streetAddress-Feld
    * erhält den geerbten Wert als Placeholder und das "ü"-Badge.
    *
    ***************************************************************/

    /***************************************************************
    *
    * NGO/Organization-Feldsymmetrie - beide Types
    * beschreiben denselben globalen Organisationsknoten, daher dürfen
    * die verfügbaren Kontaktfelder nicht von der Type-Wahl abhängen.
    * NGO erhält telephone/email (aus Organization.json), Organization
    * erhält address (aus NGO.json).
    *
    ***************************************************************/
    function testNgoSchemaEnthaeltTelephoneUndEmail(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'NGO');

        $this->assertNotNull($schema);
        $this->assertSame('text', $schema['properties']['telephone']['ui:widget']);
        $this->assertSame('text', $schema['properties']['email']['ui:widget']);

        $html = (new \SchemaOrgData_FormRenderer())->renderTypeFields(
            'global', 'NGO', $schema, [], null, null, ['data' => [], 'originLabel' => []],
            new \SchemaOrgData_DataSplitHelper(), $this->adminLang(), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', $plugin->PLUGIN_SELF_URL, new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_Validator(), $this->weekdayLang(), $this->availableFragments($plugin)
        );

        $this->assertStringContainsString('name="schemaOrgData[global][data][telephone]"', $html);
        $this->assertStringContainsString('name="schemaOrgData[global][data][email]"', $html);
    }

    function testOrganizationSchemaEnthaeltAddress(): void {
        $plugin = new \schemaOrgData();
        $schema = (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'Organization');

        $this->assertNotNull($schema);
        $this->assertSame('#/definitions/PostalAddress', $schema['properties']['address']['$ref']);
        $this->assertSame('postal_address', $schema['definitions']['PostalAddress']['ui:widget']);

        $html = (new \SchemaOrgData_FormRenderer())->renderTypeFields(
            'global', 'Organization', $schema, [], null, null, ['data' => [], 'originLabel' => []],
            new \SchemaOrgData_DataSplitHelper(), $this->adminLang(), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', $plugin->PLUGIN_SELF_URL, new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_Validator(), $this->weekdayLang(), $this->availableFragments($plugin)
        );

        $this->assertStringContainsString('schemaOrgData_global_address_addressLocality', $html);
    }
}
