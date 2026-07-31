'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Baut das Markup des Geo-Widgets exakt nach dem Muster von
 * SchemaOrgData_FormRenderer::renderGeoWidget() nach (IDs,
 * data-pair-Verknüpfung latitude<->longitude).
 *
 * @param {string} baseId z. B. "schemaOrgData_global_geo"
 * @returns {string}
 */
function buildGeoFixture(baseId) {
    var latId = baseId + '_latitude';
    var lonId = baseId + '_longitude';

    return ''
        + '<input type="text" id="' + latId + '" name="' + latId + '" value="" '
        + 'data-validate="geo" data-pair="' + lonId + '">'
        + '<input type="text" id="' + lonId + '" name="' + lonId + '" value="" '
        + 'data-validate="geo" data-pair="' + latId + '">';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var BASE_ID = 'schemaOrgData_global_geo';
var ID = {
    latitude: BASE_ID + '_latitude',
    longitude: BASE_ID + '_longitude'
};

describe('Geo-Widget - Live-Validierung (runGeoValidation)', function () {
    var validator;

    beforeEach(function () {
        document.body.innerHTML = '<form>' + buildGeoFixture(BASE_ID) + '</form>';
        window.schemaOrgDataMessages = {
            geoLatitude: 'LATITUDE_ERROR',
            geoLongitude: 'LONGITUDE_ERROR',
            geoIncomplete: 'INCOMPLETE_ERROR'
        };
        validator = loadPluginScripts.loadValidator();
        validator.initAdminForm();
    });

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('beide Felder leer erzeugt kein Feedback', function () {
        fire(document.getElementById(ID.latitude), 'blur');

        expect(feedbackFor(ID.latitude)).toBeNull();
        expect(feedbackFor(ID.longitude)).toBeNull();
    });

    test('nur latitude befüllt meldet Paar-Pflicht-Fehler am leeren longitude-Feld', function () {
        setValue(ID.latitude, '48.12567');
        fire(document.getElementById(ID.latitude), 'blur');

        expect(feedbackFor(ID.latitude).textContent).not.toContain('INCOMPLETE_ERROR');
        expect(feedbackFor(ID.longitude).textContent).toContain('INCOMPLETE_ERROR');
    });

    test('nur longitude befüllt meldet Paar-Pflicht-Fehler am leeren latitude-Feld', function () {
        setValue(ID.longitude, '11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.latitude).textContent).toContain('INCOMPLETE_ERROR');
        expect(feedbackFor(ID.longitude).textContent).not.toContain('INCOMPLETE_ERROR');
    });

    test('beide gültig befüllt erzeugt kein Fehler-Feedback', function () {
        setValue(ID.latitude, '48.12567');
        fire(document.getElementById(ID.latitude), 'blur');
        setValue(ID.longitude, '11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.latitude).className).toContain('schemaOrgData-feedback--ok');
        expect(feedbackFor(ID.longitude).className).toContain('schemaOrgData-feedback--ok');
    });

    test('beide befüllt, latitude außerhalb des Wertebereichs meldet Wertebereichsfehler', function () {
        setValue(ID.latitude, '999');
        fire(document.getElementById(ID.latitude), 'blur');
        setValue(ID.longitude, '11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.latitude).textContent).toContain('LATITUDE_ERROR');
    });

    // B5-05: "Number()" liest Hex-Literale ("0x1A" -> 26) - is_numeric()
    // serverseitig (SchemaOrgData_Validator::validateGeoCoordinate()) nicht.
    // Ohne diese Ablehnung wäre "0x1A" (innerhalb -90..90) fälschlich als
    // gültige latitude durchgegangen.
    test('Hex-Literal wird abgelehnt statt als Zahl interpretiert ("0x1A")', function () {
        setValue(ID.latitude, '0x1A');
        fire(document.getElementById(ID.latitude), 'blur');
        setValue(ID.longitude, '11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.latitude).className).toContain('schemaOrgData-feedback--error');
        expect(feedbackFor(ID.latitude).textContent).toContain('LATITUDE_ERROR');
    });

    test('reguläre Dezimalwerte inklusive Vorzeichen und Exponent bleiben gültig (B5-05)', function () {
        setValue(ID.latitude, '4.55e1');
        fire(document.getElementById(ID.latitude), 'blur');
        setValue(ID.longitude, '+11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.latitude).className).toContain('schemaOrgData-feedback--ok');
        expect(feedbackFor(ID.longitude).className).toContain('schemaOrgData-feedback--ok');
    });

    test('Korrektur des fehlenden Gegenstücks entfernt den Paar-Pflicht-Fehler sofort (Regression: sofortige Honorierung)', function () {
        setValue(ID.latitude, '48.12567');
        fire(document.getElementById(ID.latitude), 'blur');
        expect(feedbackFor(ID.longitude).textContent).toContain('INCOMPLETE_ERROR');

        setValue(ID.longitude, '11.64278');
        fire(document.getElementById(ID.longitude), 'blur');

        expect(feedbackFor(ID.longitude).className).toContain('schemaOrgData-feedback--ok');
        expect(feedbackFor(ID.latitude).className).toContain('schemaOrgData-feedback--ok');
    });
});
