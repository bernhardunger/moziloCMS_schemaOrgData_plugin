'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Baut das Markup eines PostalAddress-Widgets exakt nach dem Muster von
 * SchemaOrgData_FormRenderer::renderAddressSubField() nach: alle Sub-Felder
 * ohne "default" (streetAddress, postalCode, addressLocality, addressRegion)
 * tragen dieselbe data-address-group-Id; addressLocality zusätzlich
 * data-validate="address_required" + data-required-message.
 *
 * @param {string} groupId z. B. "schemaOrgData_global_address"
 * @returns {string}
 */
function buildAddressFixture(groupId) {
    return ''
        + '<input type="text" id="' + groupId + '_streetAddress" data-address-group="' + groupId + '" value="">'
        + '<input type="text" id="' + groupId + '_postalCode" data-address-group="' + groupId + '" value="">'
        + '<input type="text" id="' + groupId + '_addressLocality" data-address-group="' + groupId + '" '
        + 'data-validate="address_required" data-required-message="REQUIRED_ERROR" value="">'
        + '<input type="text" id="' + groupId + '_addressRegion" data-address-group="' + groupId + '" value="">';
}

/**
 * Baut zusätzlich das Geschwisterfeld "name" des place-Widgets
 * (Event.location) nach - siehe renderPlaceWidget(): trägt dieselbe
 * data-address-group-Id wie die verschachtelte Adresse.
 *
 * @param {string} nameId z. B. "schemaOrgData_page_location_name"
 * @param {string} groupId
 * @returns {string}
 */
function buildPlaceNameFixture(nameId, groupId) {
    return '<input type="text" id="' + nameId + '" data-address-group="' + groupId + '" value="">';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var GROUP_ID = 'schemaOrgData_global_address';
var LOCALITY_ID = GROUP_ID + '_addressLocality';
var STREET_ID = GROUP_ID + '_streetAddress';

describe('PostalAddress-Widget - gruppen-bedingte Live-Pflicht (checkAddressRequiredField, Regressionsfall 8.12)', function () {
    var validator;

    beforeEach(function () {
        window.schemaOrgDataMessages = {};
        validator = loadPluginScripts.loadValidator();
    });

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('komplett leere Adresse zeigt keinen Pflichtfeld-Fehler an addressLocality (Regression 8.12)', function () {
        document.body.innerHTML = '<form>' + buildAddressFixture(GROUP_ID) + '</form>';
        validator.initAdminForm();

        fire(document.getElementById(LOCALITY_ID), 'blur');

        var feedback = feedbackFor(LOCALITY_ID);
        expect(feedback === null || feedback.textContent === '').toBe(true);
    });

    test('ein anderes Adressfeld befüllt erzwingt den Pflichtfeld-Fehler an addressLocality', function () {
        document.body.innerHTML = '<form>' + buildAddressFixture(GROUP_ID) + '</form>';
        validator.initAdminForm();

        document.getElementById(STREET_ID).value = 'Musterstraße 1';
        fire(document.getElementById(LOCALITY_ID), 'blur');

        expect(feedbackFor(LOCALITY_ID).textContent).toContain('REQUIRED_ERROR');
    });

    test('addressLocality selbst befüllt zeigt keinen Fehler, unabhängig von den übrigen Feldern', function () {
        document.body.innerHTML = '<form>' + buildAddressFixture(GROUP_ID) + '</form>';
        validator.initAdminForm();

        document.getElementById(LOCALITY_ID).value = 'Musterstadt';
        fire(document.getElementById(LOCALITY_ID), 'blur');

        var feedback = feedbackFor(LOCALITY_ID);
        expect(feedback === null || feedback.className.indexOf('schemaOrgData-feedback--error') === -1).toBe(true);
    });

    test('place-Widget: befülltes Geschwisterfeld "name" erzwingt den Pflichtfeld-Fehler an addressLocality', function () {
        var placeGroupId = 'schemaOrgData_page_location_address';
        var placeLocalityId = placeGroupId + '_addressLocality';
        var nameId = 'schemaOrgData_page_location_name';

        document.body.innerHTML = '<form>'
            + buildPlaceNameFixture(nameId, placeGroupId)
            + buildAddressFixture(placeGroupId)
            + '</form>';
        validator.initAdminForm();

        document.getElementById(nameId).value = 'Stadtpark';
        fire(document.getElementById(placeLocalityId), 'blur');

        expect(feedbackFor(placeLocalityId).textContent).toContain('REQUIRED_ERROR');
    });

    test('place-Widget: komplett leer (Name UND Adresse) zeigt keinen Fehler', function () {
        var placeGroupId = 'schemaOrgData_page_location_address';
        var placeLocalityId = placeGroupId + '_addressLocality';
        var nameId = 'schemaOrgData_page_location_name';

        document.body.innerHTML = '<form>'
            + buildPlaceNameFixture(nameId, placeGroupId)
            + buildAddressFixture(placeGroupId)
            + '</form>';
        validator.initAdminForm();

        fire(document.getElementById(placeLocalityId), 'blur');

        var feedback = feedbackFor(placeLocalityId);
        expect(feedback === null || feedback.textContent === '').toBe(true);
    });
});
