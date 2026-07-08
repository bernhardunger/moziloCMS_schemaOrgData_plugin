'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

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
        + '<input type="text" id="' + startId + '" name="' + startId + '" value="" data-validate="date-time">'
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
        document.body.innerHTML = '<form>' + buildEventDateFixture(ID_PREFIX) + '</form>';
        window.schemaOrgDataMessages = {
            dateInvalid: 'DATE_INVALID',
            dateRangeInvalid: 'DATE_RANGE_INVALID'
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
        setValue(ID.startDate, '10.07.2026');
        fire(document.getElementById(ID.startDate), 'blur');

        var feedback = feedbackFor(ID.startDate);
        expect(feedback).not.toBeNull();
        expect(feedback.className).toContain('schemaOrgData-feedback--ok');
    });
});
