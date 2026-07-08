'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Baut das Markup eines Erweiterungsfelds exakt nach dem Muster von
 * SchemaOrgData_FormRenderer::renderExtensionFieldWidget() nach: Textarea
 * mit data-schema-url plus fest verankertem, immer vorhandenem
 * Feedback-<div> ("<fieldId>_feedback"), das initExtensionFieldValidation()
 * per getElementById() anspricht.
 *
 * @param {string} fieldId z. B. "schemaOrgData_global_extension"
 * @param {string} schemaUrl
 * @returns {string}
 */
function buildExtensionFieldFixture(fieldId, schemaUrl) {
    return ''
        + '<textarea id="' + fieldId + '" name="schemaOrgData[x][extension][Type]" '
        + 'class="mo-input-text schemaOrgData-extension-field" rows="12" '
        + 'data-schema-url="' + schemaUrl + '"></textarea>'
        + '<div id="' + fieldId + '_feedback" class="schemaOrgData-extension-feedback"></div>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

/**
 * Wartet, bis die fetch().then().then()-Kette aus
 * initExtensionFieldValidation() vollständig abgearbeitet ist.
 * jsdom kennt kein setImmediate() - eine feste Anzahl Microtask-Ticks
 * (mehr als die zwei .then()-Stufen im Produktionscode) reicht aus.
 */
function flushPromises() {
    return Promise.resolve().then(function () {}).then(function () {}).then(function () {});
}

var SCHEMA_A = {
    '$schema': 'http://json-schema.org/draft-07/schema#',
    title: 'SchemaA',
    type: 'object',
    properties: {
        name: { type: 'string' },
        count: { type: 'integer' }
    }
};

var SCHEMA_B = {
    '$schema': 'http://json-schema.org/draft-07/schema#',
    title: 'SchemaB',
    type: 'object',
    properties: {
        legalName: { type: 'string' }
    }
};

var GLOBAL_ID = 'schemaOrgData_global_extension';
var CATEGORY_ID = 'schemaOrgData_category_extension';

describe('Erweiterungsfeld - DOM-Wiring (initExtensionFieldValidation)', function () {
    var validator;
    var fetchMock;

    beforeEach(function () {
        loadPluginScripts.loadAjv();

        fetchMock = jest.fn(function (url) {
            var schema = url.indexOf('SchemaB') !== -1 ? SCHEMA_B : SCHEMA_A;
            return Promise.resolve({ json: function () { return Promise.resolve(schema); } });
        });
        global.fetch = fetchMock;

        window.schemaOrgDataMessages = {
            unknownProperty: 'UNBEKANNTE_PROPERTY {PARAM1}'
        };

        validator = loadPluginScripts.loadValidator();
    });

    afterEach(function () {
        delete global.fetch;
    });

    function feedbackFor(fieldId) {
        return document.getElementById(fieldId + '_feedback');
    }

    test('lädt das Schema über data-schema-url und schreibt das Ergebnis in das per ID-Konvention gefundene Feedback-Element', async function () {
        document.body.innerHTML = '<form>' + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json') + '</form>';
        validator.initAdminForm();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/schemas/SchemaA.json');

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback).not.toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).not.toBeNull();
        expect(feedback.textContent).toContain('UNBEKANNTE_PROPERTY foo');
    });

    test('gültige, bekannte Properties erzeugen genau ein OK-Feedback (Klasse schemaOrgData-feedback--ok)', async function () {
        document.body.innerHTML = '<form>' + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json') + '</form>';
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"name": "Muster GmbH", "count": 3}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.children.length).toBe(1);
        expect(feedback.querySelector('.schemaOrgData-feedback--ok')).not.toBeNull();
    });

    test('Formatverletzung (falscher Typ) erzeugt Feedback mit Klasse schemaOrgData-feedback--error', async function () {
        document.body.innerHTML = '<form>' + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json') + '</form>';
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"count": "keine-zahl"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--error')).not.toBeNull();
    });

    test('mehrere Erweiterungsfelder verschiedener Scopes bleiben unabhängig - Blur eines Feldes aktualisiert nicht das Feedback des anderen Feldes', async function () {
        document.body.innerHTML = '<form>'
            + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json')
            + buildExtensionFieldFixture(CATEGORY_ID, '/schemas/SchemaB.json')
            + '</form>';
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        expect(feedbackFor(GLOBAL_ID).children.length).toBeGreaterThan(0);
        expect(feedbackFor(CATEGORY_ID).children.length).toBe(0);
    });

    test('kein globaler document.body-Fallback - Feedback landet ausschließlich im vorgesehenen Feedback-Element', async function () {
        document.body.innerHTML = '<form>' + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json') + '</form>';
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var directBodyChildren = Array.prototype.slice.call(document.body.children);
        var strayFeedback = directBodyChildren.filter(function (el) {
            return el.classList.contains('schemaOrgData-feedback');
        });
        expect(strayFeedback.length).toBe(0);
        expect(feedbackFor(GLOBAL_ID).children.length).toBeGreaterThan(0);
    });

    test('Schema-Ladefehler (fetch schlägt fehl) wirft keinen Fehler - ohne geladenes Schema wird weder auf Unbekannt- noch auf Format-Verletzungen geprüft (schema bleibt null, siehe checkUnknownProperties()/checkFormats())', async function () {
        global.fetch = jest.fn(function () { return Promise.reject(new Error('network down')); });

        document.body.innerHTML = '<form>' + buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json') + '</form>';
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        expect(function () {
            fire(document.getElementById(GLOBAL_ID), 'blur');
        }).not.toThrow();

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.children.length).toBe(1);
        expect(feedback.querySelector('.schemaOrgData-feedback--ok')).not.toBeNull();
    });
});
