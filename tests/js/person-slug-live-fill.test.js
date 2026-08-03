'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Baut das Markup des "Neue Person"-Formulars nach (Namens-/Slug-Feld samt
 * data-pair-Verknüpfung) - exakt das Muster von
 * SchemaOrgData_PersonsAdminRenderer::renderPersonForm() im Neu-Fall.
 *
 * @returns {string}
 */
function buildFixture() {
    return ''
        + '<table><tbody></tbody></table>'
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

describe('Personen-Registry - Slug-Live-Fill (initPersonSlugLiveFill)', function () {
    var validator;

    function setup() {
        document.body.innerHTML = adminContainer.buildAdminContainer({
            personSlugCollision: 'Die Kennung ist bereits vergeben (vorhandene Person: "{PARAM1}").'
        }, buildFixture());
        validator = loadPluginScripts.loadValidator();
    }

    function setValue(id, value) {
        document.getElementById(id).value = value;
    }

    function slugField() {
        return document.getElementById(ID.slug);
    }

    /**
     * Ergänzt die Personenliste des Fixtures um eine bereits vorhandene
     * Person - Zeilenformat exakt wie
     * SchemaOrgData_PersonsAdminRenderer::renderListView() es rendert
     * (data-slug + data-slug-name), da runPersonSlugValidation() genau
     * diese beiden Attribute liest.
     *
     * @param {string} slug
     * @param {string} name
     */
    function addExistingPerson(slug, name) {
        var row = document.createElement('tr');
        row.setAttribute('data-slug', slug);
        row.setAttribute('data-slug-name', name);
        document.querySelector('tbody').appendChild(row);
    }

    function slugFeedback() {
        return document.getElementById(ID.slug + '_feedback');
    }

    test('leeres Slug-Feld wird beim Verlassen des Namensfelds befüllt und markiert', function () {
        setup();
        validator.initPersonSlugLiveFill();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('max-mustermann');
        expect(slugField().hasAttribute('data-slug-autofilled')).toBe(true);
    });

    test('automatisch gefülltes Slug-Feld folgt einer Namenskorrektur', function () {
        setup();
        validator.initPersonSlugLiveFill();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        setValue(ID.name, 'Maxi Musterfrau');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('maxi-musterfrau');
    });

    test('manuelle Slug-Eingabe beendet die Ableitung dauerhaft', function () {
        setup();
        validator.initPersonSlugLiveFill();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        setValue(ID.slug, 'mm');
        fire(slugField(), 'input');

        setValue(ID.name, 'Maxi Musterfrau');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('mm');
        expect(slugField().hasAttribute('data-slug-autofilled')).toBe(false);
    });

    test('vorbefülltes Slug-Feld ohne Markierung bleibt unangetastet', function () {
        setup();
        setValue(ID.slug, 'redisplay-wert');
        validator.initPersonSlugLiveFill();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('redisplay-wert');
    });

    test('leerer Vorschlag lässt das Slug-Feld unverändert, statt es zu leeren', function () {
        setup();
        validator.initPersonSlugLiveFill();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        setValue(ID.name, '###');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('max-mustermann');
    });

    test('Kollisions-Feedback bewertet nach einer Namenskorrektur den neuen Slug', function () {
        setup();
        addExistingPerson('maxi-musterfrau', 'Maxi Musterfrau');
        // initAdminForm() statt initPersonSlugLiveFill() allein: nur so
        // greift die produktive Listener-Reihenfolge, in der die
        // Kollisionsprüfung aus initFieldValidation() vor dem Befüllen läuft.
        validator.initAdminForm();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugFeedback()).toBeNull();

        setValue(ID.name, 'Maxi Musterfrau');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('maxi-musterfrau');
        expect(slugFeedback()).not.toBeNull();
        expect(slugFeedback().className).toContain('schemaOrgData-feedback--error');
        expect(slugFeedback().textContent).toContain('Maxi Musterfrau');
    });

    test('initAdminForm() verdrahtet den Live-Fill mit', function () {
        setup();
        validator.initAdminForm();

        setValue(ID.name, 'Max Mustermann');
        fire(document.getElementById(ID.name), 'blur');

        expect(slugField().value).toBe('max-mustermann');
    });
});
