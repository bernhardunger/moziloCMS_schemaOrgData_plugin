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

    function testRenderTextareaWidgetUndExtensionFieldTeilenSichBreitenKlasse(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $textareaHtml = $renderer->renderTextareaWidget('fid', 'fname', [], 'Beschreibungstext');
        $extensionHtml = $renderer->renderExtensionFieldWidget(
            'global', 'LocalBusiness', '', null, $this->adminLang(), 'https://example.com/plugins/schemaOrgData/',
        );

        $this->assertStringContainsString('schemaOrgData-wide-textarea', $textareaHtml);
        $this->assertStringContainsString('schemaOrgData-wide-textarea', $extensionHtml);
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
    // renderGeoWidget()
    // -----------------------------------------------------------

    function testRenderGeoWidgetEnthaeltLatitudeUndLongitudeFelder(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderGeoWidget(
            'global', 'geo', $schema['properties']['geo'], [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString('schemaOrgData_global_geo_latitude', $html);
        $this->assertStringContainsString('schemaOrgData_global_geo_longitude', $html);
        $this->assertStringContainsString('schemaOrgData[global][data][geo][latitude]', $html);
        $this->assertStringContainsString('schemaOrgData[global][data][geo][longitude]', $html);
    }

    function testRenderGeoWidgetZeigtGeerbtenPlatzhalterUndBadge(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $inherited = ['latitude' => '48.12567', 'longitude' => '11.64278'];

        $html = $renderer->renderGeoWidget(
            'category', 'geo', $schema['properties']['geo'], [], 'cat_testkat', $inherited, 'Global',
            $this->adminLang(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString('placeholder="48.12567"', $html);
        $this->assertStringContainsString('placeholder="11.64278"', $html);
        $this->assertStringContainsString('schemaOrgData-inherited', $html);
    }

    function testRenderGeoWidgetOhneWerteZeigtKeinenFehler(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderGeoWidget(
            'global', 'geo', $schema['properties']['geo'], [], null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringNotContainsString($this->adminLang()->getLanguageValue('error_geo_incomplete'), $html);
    }

    function testRenderGeoWidgetZeigtIncompleteFehlerBeiNurEinemGefuelltenFeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $value = ['latitude' => '48.12567', 'longitude' => ''];

        $html = $renderer->renderGeoWidget(
            'global', 'geo', $schema['properties']['geo'], $value, null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString($this->adminLang()->getLanguageValue('error_geo_incomplete'), $html);
    }

    function testRenderGeoWidgetZeigtWertebereichsfehlerBeiBeidenGefuelltUndUngueltigemWert(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $value = ['latitude' => '999', 'longitude' => '11.64278'];

        $html = $renderer->renderGeoWidget(
            'global', 'geo', $schema['properties']['geo'], $value, null, null, null,
            $this->adminLang(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString($this->adminLang()->getLanguageValue('error_geo_latitude'), $html);
        $this->assertStringNotContainsString($this->adminLang()->getLanguageValue('error_geo_incomplete'), $html);
    }

    // -----------------------------------------------------------
    // resolveGeoFieldFeedback()
    // -----------------------------------------------------------

    function testResolveGeoFieldFeedbackNullBeiBeidenLeer(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $result = $renderer->resolveGeoFieldFeedback('', '', true, new \SchemaOrgData_Validator(), $this->adminLang());

        $this->assertNull($result['status']);
    }

    function testResolveGeoFieldFeedbackErrorBeiFehlendemGegenstueck(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $result = $renderer->resolveGeoFieldFeedback('', '11.64278', false, new \SchemaOrgData_Validator(), $this->adminLang());

        $this->assertSame('error', $result['status']);
        $this->assertSame($this->adminLang()->getLanguageValue('error_geo_incomplete'), $result['message']);
    }

    function testResolveGeoFieldFeedbackOkBeiBeidenGueltigGefuellt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $result = $renderer->resolveGeoFieldFeedback('48.12567', '11.64278', true, new \SchemaOrgData_Validator(), $this->adminLang());

        $this->assertSame('ok', $result['status']);
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

    function testRenderOpeningHoursWidgetZeigtUeberlappungsfehlerBeiRedisplay(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $value = ['Mo' => ['from' => '08:00', 'to' => '12:00', 'from2' => '07:00', 'to2' => '09:00']];

        $html = $renderer->renderOpeningHoursWidget(
            'global', 'openingHours', $schema['properties']['openingHours'], $value, null,
            $this->adminLang(), $this->weekdayLang(), new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringContainsString($this->adminLang()->getLanguageValue('error_opening_hours_overlap'), $html);
    }

    function testRenderOpeningHoursWidgetZeigtKeinenUeberlappungsfehlerOhneUeberlappung(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();
        $value = ['Mo' => ['from' => '08:00', 'to' => '12:00', 'from2' => '13:00', 'to2' => '17:00']];

        $html = $renderer->renderOpeningHoursWidget(
            'global', 'openingHours', $schema['properties']['openingHours'], $value, null,
            $this->adminLang(), $this->weekdayLang(), new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
        );

        $this->assertStringNotContainsString($this->adminLang()->getLanguageValue('error_opening_hours_overlap'), $html);
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
    // renderOrgRelationsWidget()
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Sichtbarkeits-Fix: eine bereits gespeicherte Relation muss auch
    * dann als Zeile im Formular erscheinen, wenn aktuell 0 aktive
    * Personen in der Registry vorhanden sind (z. B. weil die
    * referenzierte Person inzwischen inaktiv gesetzt oder gelöscht
    * wurde) - vorher blendete das Widget in diesem Fall die komplette
    * Entries-Liste aus, der Hinweistext erschien stattdessen als
    * vermeintlicher Ersatz.
    *
    ***************************************************************/
    function testRenderOrgRelationsWidgetZeigtGespeicherteRelationOhneAktivePersonen(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $orgRelations = [['person' => 'inaktive-person', 'role' => 'employee']];

        $html = $renderer->renderOrgRelationsWidget('global', $orgRelations, null, $this->adminLang(), []);

        $this->assertStringContainsString('value="inaktive-person" selected="selected"', $html);
        $this->assertStringContainsString('value="employee" selected="selected"', $html);
        $this->assertStringContainsString(
            $this->adminLang()->getLanguageHtml('label_org_relation_person_unavailable', 'inaktive-person'), $html
        );
        // Der Hinweistext bleibt als zusätzliche Erklärung sichtbar, warum
        // keine neue Auswahl möglich ist - er ersetzt die Entries-Liste
        // nicht mehr.
        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('hint_org_relations_no_persons'), $html);
    }

    /***************************************************************
    *
    * Regressionstest für den echten Leerfall (weder gespeicherte
    * Relationen noch aktive Personen): bleibt inhaltlich unverändert
    * beim bisherigen Verhalten - der Hinweistext erscheint, keine
    * Fallback-Option und keine ausgewählte Person, da die einzige
    * verbleibende Zeile die stets angehängte leere Anlege-Zeile ist
    * (leerer $personValue, siehe Docblock in renderOrgRelationsWidget()).
    *
    ***************************************************************/
    function testRenderOrgRelationsWidgetZeigtNurHinweisBeiEchtemLeerfall(): void {
        $renderer = new \SchemaOrgData_FormRenderer();

        $html = $renderer->renderOrgRelationsWidget('global', [], null, $this->adminLang(), []);

        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('hint_org_relations_no_persons'), $html);
        $this->assertStringNotContainsString('selected="selected"', $html);
        $this->assertStringNotContainsString('label_org_relation_person_unavailable', $html);
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
        $this->assertStringContainsString('rows="12"', $html);
    }

    // -----------------------------------------------------------
    // buildValidationAttrs()
    // -----------------------------------------------------------

    function testBuildValidationAttrsRequiredMessageFuerPflichtfeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['ui:required' => true, 'ui:label' => 'label_name'];

        $attrs = $renderer->buildValidationAttrs('global', 'name', $fieldSchema, [], null, $this->adminLang());

        $this->assertSame('required', $attrs['data-validate']);
        $this->assertArrayHasKey('data-required-message', $attrs);
    }

    function testBuildValidationAttrsTelephoneNutztCountryFieldAusIdPrefixWennAddressImSchema(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $rootSchema = ['properties' => ['telephone' => [], 'address' => ['ui:widget' => 'postal_address']]];

        $attrs = $renderer->buildValidationAttrs('global', 'telephone', [], $rootSchema, 'cat_testkat', $this->adminLang());

        $this->assertSame('telephone', $attrs['data-validate']);
        $this->assertSame('schemaOrgData_cat_testkat_address_addressCountry', $attrs['data-country-field']);
    }

    /***************************************************************
    *
    * Schemas ohne address-Property (Person,
    * Organization) dürfen kein data-country-field setzen - das
    * Attribut zeigte zuvor auf ein nie gerendertes Element.
    *
    ***************************************************************/
    function testBuildValidationAttrsTelephoneOhneCountryFieldWennKeinAddressImSchema(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $rootSchema = ['properties' => ['telephone' => []]];

        $attrs = $renderer->buildValidationAttrs('global', 'telephone', [], $rootSchema, 'global', $this->adminLang());

        $this->assertSame('telephone', $attrs['data-validate']);
        $this->assertArrayNotHasKey('data-country-field', $attrs);
    }

    function testBuildValidationAttrsDateTimeEndDateVerweistAufStartDateFeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time'];

        $attrs = $renderer->buildValidationAttrs('page', 'endDate', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertSame('date-time', $attrs['data-validate']);
        $this->assertSame('schemaOrgData_page_kat_seite_startDate', $attrs['data-range-start-field']);
    }

    function testBuildValidationAttrsDateTimeStartDateOhneRangeStartField(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time', 'ui:required' => true, 'ui:label' => 'label_startDate'];

        $attrs = $renderer->buildValidationAttrs('page', 'startDate', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertSame('date-time', $attrs['data-validate']);
        $this->assertArrayNotHasKey('data-range-start-field', $attrs);
        $this->assertArrayHasKey('data-required-message', $attrs);
        $this->assertSame('1', $attrs['data-check-past']);
    }

    /***************************************************************
    *
    * Der Vergangenheits-Hinweis (validator.js, isEventDateInPast())
    * ist ausschließlich für Event.startDate vorgesehen - andere
    * date-time-Felder wie JobPosting.datePosted liegen ihrer Natur
    * nach regelmäßig in der Vergangenheit und dürfen data-check-past
    * nicht erhalten.
    *
    ***************************************************************/
    function testBuildValidationAttrsDateTimeDatePostedOhneCheckPast(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time', 'ui:required' => true, 'ui:label' => 'label_date_posted'];

        $attrs = $renderer->buildValidationAttrs('page', 'datePosted', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertArrayNotHasKey('data-check-past', $attrs);
    }

    function testBuildValidationAttrsDateTimeEndDateOhneCheckPast(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time'];

        $attrs = $renderer->buildValidationAttrs('page', 'endDate', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertArrayNotHasKey('data-check-past', $attrs);
    }

    function testBuildValidationAttrsDateTimeValidThroughVerweistAufDatePostedFeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time'];

        $attrs = $renderer->buildValidationAttrs('page', 'validThrough', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertSame('date-time', $attrs['data-validate']);
        $this->assertSame('schemaOrgData_page_kat_seite_datePosted', $attrs['data-range-start-field']);
    }

    function testBuildValidationAttrsDateTimeDatePostedOhneRangeStartField(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = ['format' => 'date-time', 'ui:required' => true, 'ui:label' => 'label_date_posted'];

        $attrs = $renderer->buildValidationAttrs('page', 'datePosted', $fieldSchema, [], 'page_kat_seite', $this->adminLang());

        $this->assertSame('date-time', $attrs['data-validate']);
        $this->assertArrayNotHasKey('data-range-start-field', $attrs);
        $this->assertArrayHasKey('data-required-message', $attrs);
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

    function testRenderFieldFeedbackDateTimeAkzeptiertDeutschesFormat(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderFieldFeedback(
            'startDate', ['format' => 'date-time'], '24.12.2026 18:00', [], 'fid_feedback',
            new \SchemaOrgData_Validator(), $this->adminLang(),
        );

        $this->assertStringContainsString('schemaOrgData-feedback--ok', $html);
    }

    function testRenderFieldFeedbackDateTimeMeldetUngueltigesDatum(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $html = $renderer->renderFieldFeedback(
            'startDate', ['format' => 'date-time'], '31.02.2026', [], 'fid_feedback',
            new \SchemaOrgData_Validator(), $this->adminLang(),
        );

        $this->assertStringContainsString('schemaOrgData-feedback--error', $html);
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
    // renderField() - geo
    // -----------------------------------------------------------

    function testRenderFieldGeoZeigtBedingtenPflichtfeldHinweisDirekt(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderField(
            'global', 'geo', $schema['properties']['geo'], [], $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('schemaOrgData-hint', $html);
        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('hint_geo_conditional_required'), $html);
        $this->assertStringContainsString('schemaOrgData_global_geo_latitude', $html);
        $this->assertStringContainsString('schemaOrgData_global_geo_longitude', $html);
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

    /***************************************************************
    *
    * renderTypeFields()
    * rendert die Pflichtfeld-Legende nur, wenn der Type mindestens
    * ein ui:required-Feld hat.
    *
    ***************************************************************/
    function testRenderTypeFieldsZeigtPflichtfeldLegendeBeiRequiredFeldern(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->localBusinessSchema();

        $html = $renderer->renderTypeFields(
            'global', 'LocalBusiness', $schema, [], null, null,
            ['data' => [], 'originLabel' => []], new \SchemaOrgData_DataSplitHelper(), $this->adminLang(),
            $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE', 'https://example.com/plugins/schemaOrgData/',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('schemaOrgData-required-legend', $html);
    }

    function testRenderTypeFieldsZeigtKeineLegendeOhneRequiredFelder(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = [
            'properties' => [
                'name' => ['type' => 'string', 'ui:widget' => 'text', 'ui:label' => 'label_name', 'ui:required' => false],
            ],
        ];

        $html = $renderer->renderTypeFields(
            'global', 'TestType', $schema, [], null, null,
            ['data' => [], 'originLabel' => []], new \SchemaOrgData_DataSplitHelper(), $this->adminLang(),
            $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE', 'https://example.com/plugins/schemaOrgData/',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringNotContainsString('schemaOrgData-required-legend', $html);
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

    function testRenderFieldIdReferenceOrLiteralOhneEinschraenkungZeigtBeideModi(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'ui:widget' => 'id_reference_or_literal',
            'ui:literalFields' => ['name'],
            'ui:literalFieldLabels' => ['name' => 'label_name'],
        ];
        $availableFragments = [
            'organization' => 'Organization — Muster GmbH',
            'person-jane-doe' => 'Jane Doe',
        ];

        $html = $renderer->renderField(
            'page', 'organizer', $fieldSchema, [], ['properties' => ['organizer' => $fieldSchema]], [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), $availableFragments,
        );

        $this->assertStringContainsString('Organization — Muster GmbH', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Manuell eintragen', $html);
        $this->assertStringContainsString('schemaOrgData-idrl-radio', $html);
    }

    function testRenderFieldIdReferenceOrLiteralMitReferenceTargetsUndOhneLiteral(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'ui:widget' => 'id_reference_or_literal',
            'ui:literalFields' => ['name'],
            'ui:literalFieldLabels' => ['name' => 'label_name'],
            'ui:referenceTargets' => ['persons'],
            'ui:allowLiteral' => false,
        ];
        $availableFragments = [
            'organization' => 'Organization — Muster GmbH',
            'person-jane-doe' => 'Jane Doe',
        ];

        $html = $renderer->renderField(
            'page', 'mainEntity', $fieldSchema, [], ['properties' => ['mainEntity' => $fieldSchema]], [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), $availableFragments,
        );

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringNotContainsString('Organization — Muster GmbH', $html);
        $this->assertStringNotContainsString('Manuell eintragen', $html);
        $this->assertStringNotContainsString('schemaOrgData-idrl-radio', $html);
        // Kein Umschalter -> kein Literal-Feldname im POST-Namensraum.
        $this->assertStringNotContainsString('[mainEntity][name]', $html);
    }

    // -----------------------------------------------------------
    // renderIdReferenceOrLiteralWidget() - Anfangszustand des Markups
    // -----------------------------------------------------------

    private function idRlWidgetSchema(bool $allowLiteral = true): array {
        return [
            'ui:widget' => 'id_reference_or_literal',
            'ui:literalFields' => ['name'],
            'ui:literalFieldLabels' => ['name' => 'label_name'],
            'ui:allowLiteral' => $allowLiteral,
        ];
    }

    private function renderIdRlWidget(array $fieldSchema, array $value): string {
        $renderer = new \SchemaOrgData_FormRenderer();

        return $renderer->renderIdReferenceOrLiteralWidget(
            'global', 'author', $fieldSchema, $value, 'global',
            $this->adminLang(), ['organization' => 'Organization — Muster GmbH'],
        );
    }

    function testIdRlWidgetImReferenzModusZeigtReferenzSektionUndSetztModusFeld(): void {
        $html = $this->renderIdRlWidget($this->idRlWidgetSchema(), ['_mode' => 'reference', '_fragment' => 'organization']);

        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-reference"\s*>/', $html);
        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-literal" style="display:none"/', $html);
        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-mode-field"[^>]*value="reference"/', $html);
    }

    function testIdRlWidgetImLiteralModusZeigtLiteralSektionUndSetztModusFeld(): void {
        $html = $this->renderIdRlWidget($this->idRlWidgetSchema(), ['_mode' => 'literal', 'name' => 'Gast Autor']);

        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-literal"\s*>/', $html);
        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-reference" style="display:none"/', $html);
        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-mode-field"[^>]*value="literal"/', $html);
    }

    function testIdRlWidgetOhneAllowLiteralRendertNurDieReferenzSektion(): void {
        $html = $this->renderIdRlWidget($this->idRlWidgetSchema(false), ['_mode' => 'reference']);

        $this->assertStringNotContainsString('schemaOrgData-idrl-radio', $html);
        $this->assertStringNotContainsString('data-action="idrl-toggle"', $html);
        $this->assertStringNotContainsString('schemaOrgData-idrl-literal', $html);
        $this->assertMatchesRegularExpression('/schemaOrgData-idrl-reference"\s*>/', $html);
    }

    function testIdRlWidgetGibtKeinInlineScriptAus(): void {
        $html = $this->renderIdRlWidget($this->idRlWidgetSchema(), ['_mode' => 'reference']);

        $this->assertStringNotContainsString('<script', $html);
    }

    // -----------------------------------------------------------
    // renderPlaceWidget() / renderField() - place (Event.location)
    // -----------------------------------------------------------

    private function eventSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Event');
    }

    function testRenderPlaceWidgetRendertNameUndVerschachtelteAdressfelder(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->eventSchema();
        $locationSchema = $schema['properties']['location'];
        $value = ['name' => 'Stadtpark', 'address' => ['addressLocality' => 'Musterstadt', 'addressCountry' => 'DE']];

        $html = $renderer->renderPlaceWidget(
            'page', 'location', $locationSchema, $value, $schema, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_Validator(), 'deDE'
        );

        $this->assertStringContainsString('name="schemaOrgData[page][data][location][name]"', $html);
        $this->assertStringContainsString('value="Stadtpark"', $html);
        $this->assertStringContainsString('name="schemaOrgData[page][data][location][address][addressLocality]"', $html);
        $this->assertStringContainsString('value="Musterstadt"', $html);
    }

    function testRenderFieldDispatchtPlaceWidgetAlsFieldset(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->eventSchema();
        $locationSchema = $schema['properties']['location'];

        $html = $renderer->renderField(
            'page', 'location', $locationSchema, ['name' => 'Stadtpark'], $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('<fieldset class="schemaOrgData-fieldset">', $html);
        $this->assertStringContainsString('name="schemaOrgData[page][data][location][name]"', $html);
        $this->assertStringContainsString('name="schemaOrgData[page][data][location][address][addressCountry]"', $html);
    }

    // -----------------------------------------------------------
    // renderField() - date-time-Redisplay
    // -----------------------------------------------------------

    function testRenderFieldZeigtGespeichertesIsoDatumAlsDeutschesDatumOhneUhrzeit(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->eventSchema();

        $html = $renderer->renderField(
            'page', 'startDate', $schema['properties']['startDate'], '2026-09-15', $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value="15.09.2026"', $html);
        $this->assertStringNotContainsString('value="2026-09-15"', $html);
    }

    function testRenderFieldZeigtGespeichertesIsoDatumMitUhrzeitAlsDeutschesDatumMitUhrzeit(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->eventSchema();

        $html = $renderer->renderField(
            'page', 'startDate', $schema['properties']['startDate'], '2026-09-15T19:00:00+02:00', $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value="15.09.2026 19:00"', $html);
        $this->assertStringContainsString('schemaOrgData-feedback--ok', $html);
    }

    function testRenderFieldZeigtGeerbtesIsoDatumAlsDeutschesDatumImPlaceholder(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->eventSchema();

        $html = $renderer->renderField(
            'page', 'startDate', $schema['properties']['startDate'], '', $schema, [], null,
            '2026-09-15T19:00:00+02:00', 'Kategorie',
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value=""', $html);
        $this->assertStringContainsString('placeholder="15.09.2026 19:00"', $html);
        $this->assertStringNotContainsString('2026-09-15T19:00:00', $html);
    }

    // -----------------------------------------------------------
    // renderField() - date-time-Redisplay Article.datePublished /
    // renderSelectWidget() - JobPosting.employmentType-Labels
    // -----------------------------------------------------------

    private function articleSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Article');
    }

    private function jobPostingSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'JobPosting');
    }

    function testRenderFieldZeigtGespeichertesIsoDatumBeiArticleDatePublishedAlsDeutschesDatum(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->articleSchema();

        $html = $renderer->renderField(
            'category', 'datePublished', $schema['properties']['datePublished'], '2026-03-15', $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString('value="15.03.2026"', $html);
        $this->assertStringNotContainsString('value="2026-03-15"', $html);
    }

    function testRenderSelectWidgetZeigtUebersetztesLabelFuerJobPostingEmploymentType(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = $this->jobPostingSchema()['properties']['employmentType'];

        $html = $renderer->renderSelectWidget('fid', 'fname', $fieldSchema, 'https://schema.org/FULL_TIME', $this->adminLang(), 'deDE');

        $this->assertMatchesRegularExpression('#<option value="https://schema\.org/FULL_TIME" selected="selected">Vollzeit</option>#', $html);
        $this->assertStringNotContainsString('>https://schema.org/FULL_TIME<', $html);
    }

    // -----------------------------------------------------------
    // renderField() / Schema - JobPosting.hiringOrganization Placeholder
    // -----------------------------------------------------------

    function testJobPostingHiringOrganizationHatLiteralFieldPlaceholder(): void {
        $hiringOrganization = $this->jobPostingSchema()['properties']['hiringOrganization'];

        $this->assertSame('placeholder_hiring_organization_name', $hiringOrganization['ui:literalFieldPlaceholders']['name']);
    }

    function testRenderFieldZeigtPlaceholderFuerHiringOrganizationLiteralNameFeld(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $schema = $this->jobPostingSchema();
        $lang = $this->adminLang();

        $html = $renderer->renderField(
            'page', 'hiringOrganization', $schema['properties']['hiringOrganization'], [], $schema, [], null, null, null,
            $lang, $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(), [],
        );

        $this->assertStringContainsString(
            'placeholder="'.htmlspecialchars($lang->getLanguageValue('placeholder_hiring_organization_name'), ENT_QUOTES, CHARSET).'"',
            $html
        );
    }

    // -----------------------------------------------------------
    // data-action-Verdrahtung (js/validator.js, initDataActions())
    // -----------------------------------------------------------

    function testIdReferenceOrLiteralRadiosTragenDataActionStattInlineHandler(): void {
        $renderer = new \SchemaOrgData_FormRenderer();
        $fieldSchema = [
            'ui:widget' => 'id_reference_or_literal',
            'ui:literalFields' => ['name'],
            'ui:literalFieldLabels' => ['name' => 'label_name'],
        ];

        $html = $renderer->renderField(
            'page', 'organizer', $fieldSchema, [], ['properties' => ['organizer' => $fieldSchema]], [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(), $this->weekdayLang(),
            ['organization' => 'Organization — Muster GmbH'],
        );

        $this->assertMatchesRegularExpression('/value="reference"[^>]*data-action="idrl-toggle"/', $html);
        $this->assertMatchesRegularExpression('/value="literal"[^>]*data-action="idrl-toggle"/', $html);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|keyup|input)=/i', $html);
    }

    function testOrgRelationsWidgetUmschalterTraegtDataActionStattInlineHandler(): void {
        $renderer = new \SchemaOrgData_FormRenderer();

        $html = $renderer->renderOrgRelationsWidget('global', [], null, $this->adminLang(), []);

        $this->assertStringContainsString('data-action="persons-open"', $html);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|keyup|input)=/i', $html);
    }
}
