'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Baut das Markup des "Neue Person"-Formulars (Namens-/Slug-Feld samt
 * data-pair-Verknüpfung) sowie eine bestehende Personenliste nach -
 * exakt das Muster von SchemaOrgData_PersonsAdminRenderer::renderPersonForm()/
 * renderListView(): die Personenliste liegt bereits vollständig im DOM,
 * kein Server-Request nötig (siehe runPersonSlugValidation()).
 *
 * @param {Array<{slug: string, name: string}>} existingPersons
 * @returns {string}
 */
function buildFixture(existingPersons) {
    var rows = existingPersons.map(function (person) {
        return '<tr data-slug="' + person.slug + '" data-slug-name="' + person.name + '"></tr>';
    }).join('');

    return ''
        + '<table><tbody>' + rows + '</tbody></table>'
        + '<form>'
        + '<input type="text" id="schemaOrgData_persons_new_name" name="schemaOrgData_persons_data[name]" value="" '
        + 'data-validate="person_slug" data-pair="schemaOrgData_persons_new_slug">'
        + '<input type="text" id="schemaOrgData_persons_new_slug" name="schemaOrgData_persons_data[slug]" value="" '
        + 'data-validate="person_slug" data-pair="schemaOrgData_persons_new_name">'
        + '</form>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var ID = {
    name: 'schemaOrgData_persons_new_name',
    slug: 'schemaOrgData_persons_new_slug'
};

describe('Personen-Registry - Slug-Live-Kollisionsprüfung (runPersonSlugValidation)', function () {
    var validator;

    function setup(existingPersons) {
        document.body.innerHTML = adminContainer.buildAdminContainer({
            personSlugCollision: 'Die Kennung ist bereits vergeben (vorhandene Person: "{PARAM1}").'
        }, buildFixture(existingPersons));
        validator = loadPluginScripts.loadValidator();
        validator.initAdminForm();
    }

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function feedbackFor(id) {
        return document.getElementById(id + '_feedback');
    }

    test('Kollision bei explizit eingetipptem Slug wird am Slug-Feld gemeldet', function () {
        setup([{ slug: 'max', name: 'Max Mustermann' }]);

        setValue(ID.slug, 'Max');
        fire(document.getElementById(ID.slug), 'blur');

        expect(feedbackFor(ID.slug).className).toContain('schemaOrgData-feedback--error');
        expect(feedbackFor(ID.slug).textContent).toContain('Max Mustermann');
    });

    // Regression zu B5-06: String.replace() las das $& im Namen als
    // Ersetzungsmuster und setzte den Treffer "{PARAM1}" selbst ein.
    test('ein Personenname mit $& erscheint literal in der Kollisionsmeldung (B5-06)', function () {
        setup([{ slug: 'max', name: 'Max $& Mustermann' }]);

        setValue(ID.slug, 'Max');
        fire(document.getElementById(ID.slug), 'blur');

        expect(feedbackFor(ID.slug).textContent).toContain('Max $& Mustermann');
        expect(feedbackFor(ID.slug).textContent).not.toContain('{PARAM1}');
    });

    test('Kollision bei aus dem Namen abgeleitetem Slug (leeres Slug-Feld) wird am Slug-Feld gemeldet', function () {
        setup([{ slug: 'max-mustermann', name: 'Max Mustermann' }]);

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        expect(feedbackFor(ID.slug).className).toContain('schemaOrgData-feedback--error');
        expect(feedbackFor(ID.slug).textContent).toContain('Max Mustermann');
    });

    test('kein Treffer erzeugt kein Fehler-Feedback', function () {
        setup([{ slug: 'max', name: 'Max Mustermann' }]);

        setValue(ID.slug, 'julia-weber');
        fire(document.getElementById(ID.slug), 'blur');

        expect(feedbackFor(ID.slug)).toBeNull();
    });

    test('beide Felder leer erzeugt kein Feedback', function () {
        setup([{ slug: 'max', name: 'Max Mustermann' }]);

        fire(document.getElementById(ID.name), 'blur');

        expect(feedbackFor(ID.slug)).toBeNull();
    });

    test('Korrektur des Slug-Werts entfernt den Kollisionshinweis sofort', function () {
        setup([{ slug: 'max', name: 'Max Mustermann' }]);

        setValue(ID.slug, 'max');
        fire(document.getElementById(ID.slug), 'blur');
        expect(feedbackFor(ID.slug).className).toContain('schemaOrgData-feedback--error');

        setValue(ID.slug, 'max-2');
        fire(document.getElementById(ID.slug), 'blur');

        expect(feedbackFor(ID.slug).className).not.toContain('schemaOrgData-feedback--error');
    });
});
