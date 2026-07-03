<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_FormRenderer.
* Echte, zustandslose Language-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_Validator-/SchemaOrgData_OpeningHoursHelper-/
* SchemaOrgData_UrlHelper-/SchemaOrgData_DataSplitHelper-Instanzen,
* $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures des
* Plugins.
*
***************************************************************/
final class FormRendererComponentTest extends TestCase {

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function weekdayLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/cms_language_deDE.txt');
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function localBusinessSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
    }

    // -----------------------------------------------------------
    // renderRequiredBadge() / renderInheritedBadge()
    // -----------------------------------------------------------

    function testRenderRequiredBadgeLeerBeiOptionalemFeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $this->assertSame('', $renderer->renderRequiredBadge(false, $this->adminLang()));
    }

    function testRenderRequiredBadgeEnthaeltSternchenBeiPflichtfeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $this->assertStringContainsString('schemaOrgData-required', $renderer->renderRequiredBadge(true, $this->adminLang()));
    }

    function testRenderInheritedBadgeLeerOhneOriginLabel(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $this->assertSame('', $renderer->renderInheritedBadge(null, $this->adminLang()));
    }

    function testRenderInheritedBadgeEnthaeltTooltipMitOriginLabel(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderInheritedBadge('Global', $this->adminLang());

        $this->assertStringContainsString('schemaOrgData-inherited', $html);
        $this->assertStringContainsString('Global', $html);
    }

    // -----------------------------------------------------------
    // renderValidationFeedback()
    // -----------------------------------------------------------

    function testRenderValidationFeedbackLeerBeiNullStatus(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $this->assertSame('', $renderer->renderValidationFeedback(['status' => null, 'message' => null], null));
    }

    function testRenderValidationFeedbackMitFeedbackId(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderValidationFeedback(['status' => 'error', 'message' => 'Fehler'], 'foo_feedback');

        $this->assertStringContainsString('id="foo_feedback"', $html);
        $this->assertStringContainsString('schemaOrgData-feedback--error', $html);
    }

    // -----------------------------------------------------------
    // renderTextWidget() / renderTextareaWidget() / renderSelectWidget()
    // -----------------------------------------------------------

    function testRenderTextWidgetEnthaeltValueUndExtraAttrs(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderTextWidget('fid', 'fname', [], 'Muster GmbH', ['data-validate' => 'required']);

        $this->assertStringContainsString('id="fid"', $html);
        $this->assertStringContainsString('value="Muster GmbH"', $html);
        $this->assertStringContainsString('data-validate="required"', $html);
    }

    function testRenderTextareaWidgetEnthaeltValue(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderTextareaWidget('fid', 'fname', [], 'Beschreibungstext');

        $this->assertStringContainsString('<textarea id="fid"', $html);
        $this->assertStringContainsString('Beschreibungstext', $html);
    }

    function testRenderSelectWidgetNutztEnumLabelsFuerAktuelleSprache(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'enum' => ['DE', 'AT'],
            'ui:enumLabels' => ['deDE' => ['DE' => 'Deutschland', 'AT' => 'Österreich']],
            'ui:required' => true,
        ];

        $html = $renderer->renderSelectWidget('fid', 'fname', $fieldSchema, 'DE', $this->adminLang(), 'deDE');

        $this->assertMatchesRegularExpression('/<option value="DE" selected="selected">Deutschland<\/option>/', $html);
        $this->assertStringContainsString('Österreich', $html);
    }

    // -----------------------------------------------------------
    // renderPostalAddressWidget()
    // -----------------------------------------------------------

    function testRenderPostalAddressWidgetEnthaeltAlleFuenfFelderUndDeDefault(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $addressSchema = $this->schemaRepository()->resolveSchemaRef($schema['properties']['address'], $schema);

        $html = $renderer->renderPostalAddressWidget(
            'global', 'address', $addressSchema, [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(), 'deDE',
        );

        foreach(['streetAddress', 'postalCode', 'addressLocality', 'addressRegion', 'addressCountry'] as $field) {
            $this->assertStringContainsString('schemaOrgData_global_address_'.$field, $html);
        }
        $this->assertMatchesRegularExpression('/<option value="DE" selected="selected">[^<]*<\/option>/', $html);
    }

    function testRenderPostalAddressWidgetZeigtGeerbtenPlatzhalterUndBadge(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $addressSchema = $this->schemaRepository()->resolveSchemaRef($schema['properties']['address'], $schema);
        $inherited = ['streetAddress' => 'Globalweg 1', 'addressLocality' => 'Globalstadt', 'addressCountry' => 'DE'];

        $html = $renderer->renderPostalAddressWidget(
            'category', 'address', $addressSchema, [], 'cat_testkat', $inherited, 'Global',
            $this->adminLang(), new \SchemaOrgData_Validator(), 'deDE',
        );

        $this->assertStringContainsString('placeholder="Globalweg 1"', $html);
        $this->assertStringContainsString('schemaOrgData-inherited', $html);
    }

    // -----------------------------------------------------------
    // renderOpeningHoursWidget()
    // -----------------------------------------------------------

    function testRenderOpeningHoursWidgetEnthaeltAlleSiebenWochentage(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderOpeningHoursWidget(
            'global', 'openingHours', $schema['properties']['openingHours'], [], null,
            $this->adminLang(), $this->weekdayLang(), new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
        );

        foreach(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $day) {
            $this->assertStringContainsString('schemaOrgData_global_openingHours_'.$day.'_from', $html);
            $this->assertStringContainsString('schemaOrgData_global_openingHours_'.$day.'_to', $html);
        }
    }

    // -----------------------------------------------------------
    // renderFaqListWidget()
    // -----------------------------------------------------------

    function testRenderFaqListWidgetRendertBestehendeEintraegePlusLeereZeile(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'items' => ['properties' => [
                'name' => ['ui:label' => 'label_faq_question', 'ui:required' => true],
                'acceptedAnswer' => ['properties' => ['text' => ['ui:label' => 'label_faq_answer']]],
            ]],
        ];
        $value = [['name' => 'Frage 1', 'acceptedAnswer' => ['text' => 'Antwort 1']]];

        $html = $renderer->renderFaqListWidget('global', 'mainEntity', $fieldSchema, $value, null, $this->adminLang());

        $this->assertStringContainsString('value="Frage 1"', $html);
        $this->assertStringContainsString('Antwort 1', $html);
        // zusätzliche leere Zeile (index 1) zum Anlegen eines neuen Eintrags
        $this->assertStringContainsString('schemaOrgData_global_mainEntity_1_name', $html);
    }

    // -----------------------------------------------------------
    // renderExtensionFieldWidget()
    // -----------------------------------------------------------

    function testRenderExtensionFieldWidgetBautSchemaUrlAusPluginSelfUrl(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderExtensionFieldWidget(
            'global', 'LocalBusiness', '{"geo":{}}', null, $this->adminLang(), 'https://example.com/plugins/schemaOrgData/',
        );

        $this->assertStringContainsString(
            'data-schema-url="https://example.com/plugins/schemaOrgData/schemas/LocalBusiness.json"', $html
        );
        $this->assertStringContainsString('{&quot;geo&quot;:{}}', $html);
    }

    // -----------------------------------------------------------
    // buildValidationAttrs()
    // -----------------------------------------------------------

    function testBuildValidationAttrsRequiredMessageFuerPflichtfeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['ui:required' => true, 'ui:label' => 'label_name'];

        $attrs = $renderer->buildValidationAttrs('global', 'name', $fieldSchema, null, $this->adminLang());

        $this->assertSame('required', $attrs['data-validate']);
        $this->assertArrayHasKey('data-required-message', $attrs);
    }

    function testBuildValidationAttrsTelephoneNutztCountryFieldAusIdPrefix(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $attrs = $renderer->buildValidationAttrs('global', 'telephone', [], 'cat_testkat', $this->adminLang());

        $this->assertSame('telephone', $attrs['data-validate']);
        $this->assertSame('schemaOrgData_cat_testkat_address_addressCountry', $attrs['data-country-field']);
    }

    // -----------------------------------------------------------
    // renderFieldFeedback()
    // -----------------------------------------------------------

    function testRenderFieldFeedbackUrlFormat(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderFieldFeedback(
            'url', ['format' => 'uri'], 'https://example.com', [], 'fid_feedback',
            new \SchemaOrgData_Validator(), $this->adminLang(),
        );

        $this->assertStringContainsString('schemaOrgData-feedback--ok', $html);
    }

    function testRenderFieldFeedbackTelephoneNutztAddressCountryAusAllData(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderFieldFeedback(
            'telephone', [], '+49 89 12345678', ['address' => ['addressCountry' => 'DE']], 'fid_feedback',
            new \SchemaOrgData_Validator(), $this->adminLang(),
        );

        $this->assertStringContainsString('schemaOrgData-feedback--ok', $html);
    }

    // -----------------------------------------------------------
    // renderField() - Required-/Inherited-Badge-Integration
    // -----------------------------------------------------------

    function testRenderFieldPflichtfeldZeigtRequiredBadgeDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderField(
            'global', 'name', $schema['properties']['name'], '', $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('schemaOrgData-required', $html);
        $this->assertStringNotContainsString('schemaOrgData-optional', $html);
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
    function testRenderFieldGeerbtesFeldBleibtLeerZeigtPlatzhalterUndBadgeDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderField(
            'category', 'telephone', $schema['properties']['telephone'], '', $schema, [], null,
            '+49 89 12345678', 'Global',
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value=""', $html);
        $this->assertStringContainsString('placeholder="+49 89 12345678"', $html);
        $this->assertStringContainsString('schemaOrgData-inherited', $html);
        $this->assertStringContainsString('&Uuml;bernommen von: Global', $html);
    }

    function testRenderFieldEigenerWertZeigtKeinInheritedBadgeDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderField(
            'category', 'telephone', $schema['properties']['telephone'], '+49 30 99999999', $schema, [], null,
            '+49 89 12345678', 'Global',
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value="+49 30 99999999"', $html);
        $this->assertStringNotContainsString('schemaOrgData-inherited', $html);
    }

    // -----------------------------------------------------------
    // renderField() - bedingter Pflichtfeld-Hinweis am Adress-Fieldset
    // -----------------------------------------------------------

    function testRenderFieldAdresseZeigtBedingtenPflichtfeldHinweisDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderField(
            'global', 'address', $schema['properties']['address'], [], $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('schemaOrgData-hint', $html);
        $this->assertStringContainsString('Adressfeld', $html);
    }

    // -----------------------------------------------------------
    // renderOpeningHoursWidget() - Regressionstest Vorbefüllung zweiter Zeitraum
    // -----------------------------------------------------------

    function testRenderOpeningHoursWidgetVorbefuelltZweitenZeitraumDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $value = ['Mo-Fr 08:00-12:00', 'Mo-Fr 13:00-17:00'];

        $html = $renderer->renderOpeningHoursWidget(
            'global', 'openingHours', $schema['properties']['openingHours'], $value, null,
            $this->adminLang(), $this->weekdayLang(), new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString('value="08:00"', $html);
        $this->assertStringContainsString('value="12:00"', $html);
        $this->assertStringContainsString('value="13:00"', $html);
        $this->assertStringContainsString('value="17:00"', $html);
        $this->assertStringContainsString('schemaOrgData_global_openingHours_Mo_from2', $html);
        $this->assertStringContainsString('schemaOrgData_global_openingHours_Mo_to2', $html);
    }

    // -----------------------------------------------------------
    // renderSelectWidget() - bekannter/unbekannter Enum-Wert (NGO)
    // -----------------------------------------------------------

    function testRenderSelectWidgetBekannterEnumWertIstSelectedDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'NGO');
        $field = $schema['properties']['nonprofitStatus'];

        $html = $renderer->renderSelectWidget('ngo_status', 'ngo[status]', $field, 'DEFoundationCharity', $this->adminLang(), 'deDE');

        $this->assertStringContainsString(
            '<option value="DEFoundationCharity" selected="selected">Gemeinnützige Stiftung</option>',
            $html
        );
    }

    function testRenderSelectWidgetUnbekannterEnumWertIstNichtSelectedDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'NGO');
        $field = $schema['properties']['nonprofitStatus'];

        $html = $renderer->renderSelectWidget('ngo_status', 'ngo[status]', $field, 'NichtVorhandenerStatus', $this->adminLang(), 'deDE');

        $this->assertStringNotContainsString('NichtVorhandenerStatus', $html);
        $this->assertStringNotContainsString('selected="selected"', $html);
    }

    // -----------------------------------------------------------
    // renderTypeFields()
    // -----------------------------------------------------------

    function testRenderTypeFieldsRendertFelderUndErweiterungsfeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderTypeFields(
            'global', 'LocalBusiness', $schema, ['name' => 'Muster GmbH', 'customProp' => 'x'], null, null,
            ['data' => [], 'originLabel' => []], new \SchemaOrgData_DataSplitHelper(), $this->adminLang(),
            $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE', 'https://example.com/plugins/schemaOrgData/',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('name="schemaOrgData[global][data][name]"', $html);
        $this->assertStringContainsString('value="Muster GmbH"', $html);
        // unbekannte Property landet im Erweiterungsfeld, nicht als eigenes Formularfeld
        $this->assertStringContainsString('schemaOrgData-extension-field', $html);
        $this->assertStringContainsString('customProp', $html);
    }

    // -----------------------------------------------------------
    // renderField() - id_reference_or_literal
    // -----------------------------------------------------------

    function testRenderFieldIdReferenceOrLiteralNutztDurchgereichteAvailableFragments(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'ui:widget' => 'id_reference_or_literal',
            'ui:literalFields' => ['name'],
            'ui:literalFieldLabels' => ['name' => 'label_name'],
        ];
        $availableFragments = ['organization' => 'Organization — Muster GmbH'];

        $html = $renderer->renderField(
            'page', 'organizer', $fieldSchema, [], ['properties' => ['organizer' => $fieldSchema]], [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), $availableFragments,
        );

        $this->assertStringContainsString('schemaOrgData-idrl-container', $html);
        $this->assertStringContainsString('Organization — Muster GmbH', $html);
    }
}
