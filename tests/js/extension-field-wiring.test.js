'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Baut das Markup eines Erweiterungsfelds exakt nach dem Muster von
 * SchemaOrgData_FormRenderer::renderExtensionFieldWidget() nach: Textarea
 * mit data-schema-url plus fest verankertem, immer vorhandenem
 * Feedback-<div> ("<fieldId>_feedback"), das initExtensionFieldValidation()
 * per getElementById() anspricht.
 *
 * @param {string} fieldId z. B. "schemaOrgData_global_extension"
 * @param {string} schemaUrl
 * @param {boolean} [personSuggestionContext] setzt data-person-suggestions,
 *        das Gegenstück zum Kontext-Parameter von
 *        renderExtensionFieldWidget() (B5-10). Anwesenheit ist die Aussage,
 *        das Attribut trägt keinen Wert.
 * @returns {string}
 */
function buildExtensionFieldFixture(fieldId, schemaUrl, personSuggestionContext) {
    return ''
        + '<textarea id="' + fieldId + '" name="schemaOrgData[x][extension][Type]" '
        + 'class="mo-input-text schemaOrgData-extension-field" rows="12" '
        + 'data-schema-url="' + schemaUrl + '"'
        + (personSuggestionContext ? ' data-person-suggestions' : '') + '></textarea>'
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
    var messages;

    beforeEach(function () {
        loadPluginScripts.loadAjv();

        fetchMock = jest.fn(function (url) {
            var schema = url.indexOf('SchemaB') !== -1 ? SCHEMA_B : SCHEMA_A;
            return Promise.resolve({ ok: true, json: function () { return Promise.resolve(schema); } });
        });
        global.fetch = fetchMock;

        messages = {
            unknownProperty: 'UNBEKANNTE_PROPERTY {PARAM1}',
            personSuggestionCandidate: 'PERSONEN_KANDIDAT {PARAM1}'
        };

        validator = loadPluginScripts.loadValidator();
    });

    afterEach(function () {
        delete global.fetch;
    });

    function feedbackFor(fieldId) {
        return document.getElementById(fieldId + '_feedback');
    }

    /**
     * Setzt das Fixture in den Formular-Container, an dem getMessages()
     * die Texte liest. Erst hier - nicht schon in beforeEach() - steht
     * der endgültige Inhalt von messages fest: zwei Tests ergänzen
     * vorher noch extensionSchemaUnavailable.
     *
     * @param {string} innerHtml
     * @returns {string}
     */
    function adminFixture(innerHtml) {
        return '<form>' + adminContainer.buildAdminContainer(messages, innerHtml) + '</form>';
    }

    test('lädt das Schema über data-schema-url und schreibt das Ergebnis in das per ID-Konvention gefundene Feedback-Element', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
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

    // Regression zu B5-06: String.replace() las das $& im Property-Namen als
    // Ersetzungsmuster und setzte den Treffer "{PARAM1}" selbst ein.
    test('ein Property-Name mit $& erscheint literal in der Warnung (B5-06)', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"$&": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.textContent).toContain('UNBEKANNTE_PROPERTY $&');
        expect(feedback.textContent).not.toContain('{PARAM1}');
    });

    test('gültige, bekannte Properties erzeugen genau ein OK-Feedback (Klasse schemaOrgData-feedback--ok)', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"name": "Muster GmbH", "count": 3}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.children.length).toBe(1);
        expect(feedback.querySelector('.schemaOrgData-feedback--ok')).not.toBeNull();
    });

    test('Formatverletzung (falscher Typ) erzeugt Feedback mit Klasse schemaOrgData-feedback--error', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"count": "keine-zahl"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--error')).not.toBeNull();
    });

    test('ein Personen-Suggestion-Kandidat (employee mit @type Person) erhält im Kontext Info- statt Warnung-Feedback', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json', true));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"employee": {"@type": "Person", "name": "Julia Weber"}}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--info')).not.toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).toBeNull();
        expect(feedback.textContent).toContain('PERSONEN_KANDIDAT employee');
    });

    // Im Kontext gerendert, damit die Warnung an der Form scheitert und nicht
    // schon am fehlenden Kontext (B5-10) - sonst prüfte der Test seinen
    // eigenen Gegenstand nicht mehr.
    test('ein Array unter employee bleibt eine gewöhnliche Unbekannt-Warnung (kein Personen-Suggestion-Kandidat)', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json', true));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"employee": [{"@type": "Person", "name": "Julia Weber"}]}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).not.toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--info')).toBeNull();
        expect(feedback.textContent).toContain('UNBEKANNTE_PROPERTY employee');
    });

    // B5-10: Ohne data-person-suggestions am Feld ist der Info-Hinweis falsch -
    // die Serverseite sucht Übernahme-Kandidaten ausschließlich im globalen
    // Geltungsbereich eines Organisations-Identity-Types. Dieselbe Eingabe wie
    // im Kontext-Test darüber, nur ohne das Attribut.
    test('ohne data-person-suggestions erhält dasselbe Personen-Literal die gewöhnliche Unbekannt-Warnung statt des Info-Hinweises (B5-10)', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"employee": {"@type": "Person", "name": "Julia Weber"}}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).not.toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--info')).toBeNull();
        expect(feedback.textContent).toContain('UNBEKANNTE_PROPERTY employee');
    });

    test('mehrere Erweiterungsfelder verschiedener Scopes bleiben unabhängig - Blur eines Feldes aktualisiert nicht das Feedback des anderen Feldes', async function () {
        document.body.innerHTML = adminFixture(
            buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json')
            + buildExtensionFieldFixture(CATEGORY_ID, '/schemas/SchemaB.json')
        );
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        expect(feedbackFor(GLOBAL_ID).children.length).toBeGreaterThan(0);
        expect(feedbackFor(CATEGORY_ID).children.length).toBe(0);
    });

    test('kein globaler document.body-Fallback - Feedback landet ausschließlich im vorgesehenen Feedback-Element', async function () {
        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
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

    // B5-02: Vor dem Fix zeigte ein fehlgeschlagener Schema-Abruf fälschlich
    // ein grünes "--ok"-Feedback (schema blieb null, checkUnknownProperties()/
    // checkFormats() liefern dann beide [] statt eines Hinweises). Die
    // Erwartung ist invertiert: kein Fehlerwurf bleibt weiterhin garantiert,
    // aber das Feedback zeigt jetzt eine Warnung ("nur Syntax geprüft").
    test('Schema-Ladefehler (fetch schlägt fehl) wirft keinen Fehler - ohne geladenes Schema zeigt das Feedback eine Warnung statt eines grünen Hakens (schema bleibt null, siehe checkUnknownProperties()/checkFormats())', async function () {
        global.fetch = jest.fn(function () { return Promise.reject(new Error('network down')); });
        messages.extensionSchemaUnavailable = 'SCHEMA_NICHT_LADBAR';

        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        expect(function () {
            fire(document.getElementById(GLOBAL_ID), 'blur');
        }).not.toThrow();

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.children.length).toBe(1);
        expect(feedback.querySelector('.schemaOrgData-feedback--ok')).toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).not.toBeNull();
        expect(feedback.textContent).toContain('SCHEMA_NICHT_LADBAR');
    });

    test('Schema-Abruf mit HTTP-Fehlerstatus (response.ok === false) zeigt ebenfalls eine Warnung statt eines grünen Hakens', async function () {
        global.fetch = jest.fn(function () {
            return Promise.resolve({ ok: false, status: 404, json: function () { return Promise.resolve({}); } });
        });
        messages.extensionSchemaUnavailable = 'SCHEMA_NICHT_LADBAR';

        document.body.innerHTML = adminFixture(buildExtensionFieldFixture(GLOBAL_ID, '/schemas/SchemaA.json'));
        validator.initAdminForm();
        await flushPromises();

        document.getElementById(GLOBAL_ID).value = '{"foo": "bar"}';
        fire(document.getElementById(GLOBAL_ID), 'blur');

        var feedback = feedbackFor(GLOBAL_ID);
        expect(feedback.querySelector('.schemaOrgData-feedback--ok')).toBeNull();
        expect(feedback.querySelector('.schemaOrgData-feedback--warning')).not.toBeNull();
        expect(feedback.textContent).toContain('SCHEMA_NICHT_LADBAR');
    });
});
