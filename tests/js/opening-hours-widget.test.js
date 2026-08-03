'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Baut das Markup für einen Wochentag des Öffnungszeiten-Widgets exakt
 * nach dem Muster von SchemaOrgData_FormRenderer::renderOpeningHoursWidget()
 * nach (IDs, data-pair-Verknüpfung, Gruppen-Wrapper für die Feedback-
 * Verankerung über input.closest('.schemaOrgData-opening-hours-group')).
 *
 * @param {string} baseId z. B. "schemaOrgData_global_openingHours_Mo"
 * @returns {string}
 */
function buildOpeningHoursDayFixture(baseId) {
    var fromId = baseId + '_from';
    var toId = baseId + '_to';
    var from2Id = baseId + '_from2';
    var to2Id = baseId + '_to2';

    return ''
        + '<div class="schemaOrgData-opening-hours-group">'
        + '<input type="text" id="' + fromId + '" name="' + fromId + '" value="" '
        + 'data-validate="opening_hours" data-pair="' + toId + '" maxlength="5">'
        + '<input type="text" id="' + toId + '" name="' + toId + '" value="" '
        + 'data-validate="opening_hours" data-pair="' + fromId + '" maxlength="5">'
        + '</div>'
        + '<div class="schemaOrgData-opening-hours-group schemaOrgData-opening-hours-second">'
        + '<input type="text" id="' + from2Id + '" name="' + from2Id + '" value="" '
        + 'data-validate="opening_hours" data-pair="' + to2Id + '" maxlength="5">'
        + '<input type="text" id="' + to2Id + '" name="' + to2Id + '" value="" '
        + 'data-validate="opening_hours" data-pair="' + from2Id + '" maxlength="5">'
        + '</div>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var BASE_ID = 'schemaOrgData_global_openingHours_Mo';
var ID = {
    from: BASE_ID + '_from',
    to: BASE_ID + '_to',
    from2: BASE_ID + '_from2',
    to2: BASE_ID + '_to2'
};

describe('Öffnungszeiten-Widget - Live-Validierung (runOpeningHoursValidation)', function () {
    var validator;

    beforeEach(function () {
        document.body.innerHTML = '<form>' + adminContainer.buildAdminContainer({
            openingHoursOverlap: 'PAUSE_OVERLAP',
            openingHoursOrder: 'ORDER_ERROR',
            openingHoursFormat: 'FORMAT_ERROR',
            openingHoursIncomplete: 'INCOMPLETE_ERROR'
        }, buildOpeningHoursDayFixture(BASE_ID)) + '</form>';
        validator = loadPluginScripts.loadValidator();
        validator.initAdminForm();
    });

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('Regression: Hauptzeitraum-„Bis"-Änderung löst die Pausen-Überlappungsprüfung erneut aus', function () {
        // Ausgangslage: Hauptzeitraum 08:00-12:00, Pause 13:00-17:00 - keine Überlappung.
        setValue(ID.from, '08:00');
        fire(document.getElementById(ID.from), 'blur');
        setValue(ID.to, '12:00');
        fire(document.getElementById(ID.to), 'blur');
        setValue(ID.from2, '13:00');
        fire(document.getElementById(ID.from2), 'blur');
        setValue(ID.to2, '17:00');
        fire(document.getElementById(ID.to2), 'blur');

        expect(feedbackFor(ID.from2).className).not.toMatch(/schemaOrgData-feedback--error/);

        // Hauptzeitraum-Ende nachträglich auf 14:00 verschoben - überlappt jetzt mit
        // dem Pausenbeginn (13:00), ohne dass die Pausenfelder selbst angefasst wurden.
        setValue(ID.to, '14:00');
        fire(document.getElementById(ID.to), 'blur');

        var pauseFeedback = feedbackFor(ID.from2);
        expect(pauseFeedback).not.toBeNull();
        expect(pauseFeedback.className).toMatch(/schemaOrgData-feedback--error/);
        expect(pauseFeedback.textContent).toContain('PAUSE_OVERLAP');
    });

    test('Regressionsschutz (94ef495): direkte Pausenfeld-Änderung erkennt Überlappung weiterhin', function () {
        setValue(ID.to, '14:00');
        fire(document.getElementById(ID.to), 'blur');

        setValue(ID.from2, '13:00');
        fire(document.getElementById(ID.from2), 'blur');
        setValue(ID.to2, '17:00');
        fire(document.getElementById(ID.to2), 'blur');

        expect(feedbackFor(ID.from2).className).toMatch(/schemaOrgData-feedback--error/);
        expect(feedbackFor(ID.from2).textContent).toContain('PAUSE_OVERLAP');
    });

    test('Regressionsschutz (94ef495): _from2 wird korrekt als „Von" erkannt, nicht mit „Bis" vertauscht', function () {
        setValue(ID.from2, '09:00');
        setValue(ID.to2, '17:00');
        fire(document.getElementById(ID.from2), 'blur');

        // Würde der Code from2 fälschlich als "Bis" behandeln, ergäbe sich
        // 17:00 (Von, eigentlich to2) >= 09:00 (Bis, eigentlich from2) -> Fehler.
        // Korrekt erkannt bleibt das Paar 09:00-17:00 gültig.
        expect(feedbackFor(ID.from2)).not.toBeNull();
        expect(feedbackFor(ID.from2).className).toMatch(/schemaOrgData-feedback--ok/);
    });

    test('Eigener Format-/Reihenfolgefehler der Pause hat Vorrang vor der Überlappungsprüfung', function () {
        setValue(ID.to, '18:00');
        fire(document.getElementById(ID.to), 'blur');

        // Pause selbst in falscher Reihenfolge (from2 > to2) UND würde zusätzlich
        // mit dem Hauptzeitraum überlappen (10:00 < 18:00) - der eigene
        // Reihenfolgefehler muss trotzdem gewinnen, nicht der Überlappungsfehler.
        setValue(ID.from2, '10:00');
        setValue(ID.to2, '09:00');
        fire(document.getElementById(ID.to2), 'blur');

        expect(feedbackFor(ID.from2).className).toMatch(/schemaOrgData-feedback--error/);
        expect(feedbackFor(ID.from2).textContent).toContain('ORDER_ERROR');
    });

    test('Geschlossen-Fall (beide Pausenfelder leer) erzeugt keinen Fehler', function () {
        setValue(ID.to, '18:00');
        fire(document.getElementById(ID.to), 'blur');

        fire(document.getElementById(ID.from2), 'blur');
        fire(document.getElementById(ID.to2), 'blur');

        expect(feedbackFor(ID.from2)).toBeNull();
    });
});
