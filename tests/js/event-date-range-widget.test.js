'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Baut das Markup von Event.startDate/endDate exakt nach dem Muster von
 * SchemaOrgData_FormRenderer::buildValidationAttrs() nach: beide Felder
 * tragen data-validate="date-time", nur endDate trägt zusätzlich
 * data-range-start-field mit der ID von startDate (siehe
 * findDateRangeCounterpart() in validator.js).
 *
 * @param {string} idPrefix z. B. "schemaOrgData_page_kat_seite"
 * @returns {string}
 */
function buildEventDateFixture(idPrefix) {
    var startId = idPrefix + '_startDate';
    var endId = idPrefix + '_endDate';

    return ''
        + '<input type="text" id="' + startId + '" name="' + startId + '" value="" data-validate="date-time" data-check-past="1">'
        + '<input type="text" id="' + endId + '" name="' + endId + '" value="" data-validate="date-time" '
        + 'data-range-start-field="' + startId + '">';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var ID_PREFIX = 'schemaOrgData_page_kat_termin';
var ID = {
    startDate: ID_PREFIX + '_startDate',
    endDate: ID_PREFIX + '_endDate'
};

describe('Event.startDate/endDate - Live-Validierung (runEventDateValidation)', function () {
    var validator;

    beforeEach(function () {
        document.body.innerHTML = '<form>' + adminContainer.buildAdminContainer({
            dateInvalid: 'DATE_INVALID',
            dateRangeInvalid: 'DATE_RANGE_INVALID',
            dateInPast: 'DATE_IN_PAST'
        }, buildEventDateFixture(ID_PREFIX)) + '</form>';
        validator = loadPluginScripts.loadValidator();
        validator.initAdminForm();
    });

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('endDate vor startDate meldet dateRangeInvalid am endDate-Feld', function () {
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');

        setValue(ID.endDate, '09.07.2026');
        fire(document.getElementById(ID.endDate), 'blur');

        var feedback = feedbackFor(ID.endDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--error');
        expect(feedback.textContent).toContain('DATE_RANGE_INVALID');
    });

    test('endDate nach startDate erzeugt kein Fehler-Feedback', function () {
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');

        setValue(ID.endDate, '11.07.2026');
        fire(document.getElementById(ID.endDate), 'blur');

        expect(feedbackFor(ID.endDate).className).toContain('schemaOrgData-feedback--ok');
    });

    test('gleicher Zeitpunkt (startDate === endDate) gilt als gültig', function () {
        setValue(ID.startDate, '10.07.2026 09:00');
        fire(document.getElementById(ID.startDate), 'blur');

        setValue(ID.endDate, '10.07.2026 09:00');
        fire(document.getElementById(ID.endDate), 'blur');

        expect(feedbackFor(ID.endDate).className).toContain('schemaOrgData-feedback--ok');
    });

    test('eine nachträgliche Korrektur von endDate auf einen gültigen Wert räumt den zuvor gesetzten Bereichsfehler auf (Regression: sofortige Honorierung)', function () {
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');
        setValue(ID.endDate, '09.07.2026');
        fire(document.getElementById(ID.endDate), 'blur');
        expect(feedbackFor(ID.endDate).className).toContain('schemaOrgData-feedback--error');

        setValue(ID.endDate, '12.07.2026');
        fire(document.getElementById(ID.endDate), 'blur');

        expect(feedbackFor(ID.endDate).className).toContain('schemaOrgData-feedback--ok');
    });

    test('der Listener ist an BEIDE Felder gebunden: Ändern von startDate (nicht endDate) aktualisiert ebenfalls das Feedback des bereits befüllten endDate-Feldes', function () {
        // endDate zuerst befüllen, während startDate noch leer ist - checkDateRange()
        // liefert dann bewusst kein Feedback (siehe Docblock), da der Bereich mangels
        // Gegenwert noch nicht geprüft werden kann.
        setValue(ID.endDate, '05.07.2026');
        fire(document.getElementById(ID.endDate), 'blur');
        expect(feedbackFor(ID.endDate)).toBeNull();

        // startDate wird NACH endDate gesetzt - nur startDate wird angefasst/geblurt.
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.endDate);
        expect(feedback).not.toBeNull();
        expect(feedback.className).toContain('schemaOrgData-feedback--error');
        expect(feedback.textContent).toContain('DATE_RANGE_INVALID');
    });

    test('eigener Formatfehler von endDate hat Vorrang vor dem Bereichsfehler', function () {
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');

        setValue(ID.endDate, '31.02.2026');
        fire(document.getElementById(ID.endDate), 'blur');

        var feedback = feedbackFor(ID.endDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--error');
        expect(feedback.textContent).toContain('DATE_INVALID');
        expect(feedback.textContent).not.toContain('DATE_RANGE_INVALID');
    });

    test('startDate ohne endDate zeigt nur das eigene Format-Feedback (ok), keinen Bereichsfehler', function () {
        // Bewusst weit in der Zukunft (statt eines nahen Datums) - sonst würde der neue
        // Vergangenheits-Hinweis (siehe unten) dieses Datum irgendwann selbst einholen.
        setValue(ID.startDate, '10.07.2099');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.startDate);
        expect(feedback).not.toBeNull();
        expect(feedback.className).toContain('schemaOrgData-feedback--ok');
    });

    test('startDate in der Vergangenheit erzeugt einen nicht-blockierenden warning-Zustand statt eines Fehlers', function () {
        setValue(ID.startDate, '01.01.2020');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.startDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--warning');
        expect(feedback.textContent).toContain('DATE_IN_PAST');
    });

    test('startDate in der Zukunft bleibt weiterhin ok, kein Vergangenheits-Hinweis', function () {
        setValue(ID.startDate, '01.01.2099');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.startDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--ok');
        expect(feedback.textContent).not.toContain('DATE_IN_PAST');
    });

    test('ein Formatfehler bei startDate hat Vorrang vor dem Vergangenheits-Hinweis', function () {
        setValue(ID.startDate, '31.02.2020');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.startDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--error');
        expect(feedback.textContent).toContain('DATE_INVALID');
        expect(feedback.textContent).not.toContain('DATE_IN_PAST');
    });

    test('endDate erhält keinen Vergangenheits-Hinweis (kein data-check-past, nur Event.startDate betroffen)', function () {
        // Beide Felder bewusst in der Vergangenheit, aber als gültiger Bereich
        // (endDate nach startDate) - ohne data-check-past auf endDate darf hier
        // trotz des vergangenen Datums kein warning-Zustand entstehen.
        setValue(ID.startDate, '01.01.2020');
        fire(document.getElementById(ID.startDate), 'blur');

        setValue(ID.endDate, '02.01.2020');
        fire(document.getElementById(ID.endDate), 'blur');

        var feedback = feedbackFor(ID.endDate);
        expect(feedback.className).toContain('schemaOrgData-feedback--ok');
        expect(feedback.textContent).not.toContain('DATE_IN_PAST');
    });
});

/**
 * Dieselbe Mechanik (findDateRangeCounterpart() liest ausschließlich das
 * data-range-start-field-Attribut, keine hartcodierten Feldnamen), jetzt am
 * Beispiel des zweiten Feldpaars JobPosting.datePosted/validThrough -
 * Regressionstest für die Verallgemeinerung in
 * SchemaOrgData_FormRenderer::buildValidationAttrs().
 */
var JOBPOSTING_ID_PREFIX = 'schemaOrgData_page_kat_stelle';
var JOBPOSTING_ID = {
    datePosted: JOBPOSTING_ID_PREFIX + '_datePosted',
    validThrough: JOBPOSTING_ID_PREFIX + '_validThrough'
};

function buildJobPostingDateFixture(idPrefix) {
    var startId = idPrefix + '_datePosted';
    var endId = idPrefix + '_validThrough';

    return ''
        + '<input type="text" id="' + startId + '" name="' + startId + '" value="" data-validate="date-time">'
        + '<input type="text" id="' + endId + '" name="' + endId + '" value="" data-validate="date-time" '
        + 'data-range-start-field="' + startId + '">';
}

describe('JobPosting.datePosted/validThrough - Live-Validierung (runEventDateValidation)', function () {
    var validator;

    beforeEach(function () {
        document.body.innerHTML = '<form>' + adminContainer.buildAdminContainer({
            dateInvalid: 'DATE_INVALID',
            dateRangeInvalid: 'DATE_RANGE_INVALID'
        }, buildJobPostingDateFixture(JOBPOSTING_ID_PREFIX)) + '</form>';
        validator = loadPluginScripts.loadValidator();
        validator.initAdminForm();
    });

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('validThrough vor datePosted meldet dateRangeInvalid am validThrough-Feld', function () {
        setValue(JOBPOSTING_ID.datePosted, '14.07.2026');
        fire(document.getElementById(JOBPOSTING_ID.datePosted), 'blur');

        setValue(JOBPOSTING_ID.validThrough, '01.01.2026');
        fire(document.getElementById(JOBPOSTING_ID.validThrough), 'blur');

        var feedback = feedbackFor(JOBPOSTING_ID.validThrough);
        expect(feedback.className).toContain('schemaOrgData-feedback--error');
        expect(feedback.textContent).toContain('DATE_RANGE_INVALID');
    });

    test('validThrough nach datePosted erzeugt kein Fehler-Feedback', function () {
        setValue(JOBPOSTING_ID.datePosted, '14.07.2026');
        fire(document.getElementById(JOBPOSTING_ID.datePosted), 'blur');

        setValue(JOBPOSTING_ID.validThrough, '01.09.2026');
        fire(document.getElementById(JOBPOSTING_ID.validThrough), 'blur');

        expect(feedbackFor(JOBPOSTING_ID.validThrough).className).toContain('schemaOrgData-feedback--ok');
    });
});
