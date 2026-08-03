'use strict';

/**
 * Testet die Type-Umschaltung je Geltungsbereich (initTypeSwitcher) gegen
 * das ausgelieferte plugins/schemaOrgData/js/validator.js - Initialzustand,
 * Umschalten der Feldgruppen und die Abgrenzung zwischen mehreren
 * Scope-Sektionen.
 *
 * Im Unterschied zu id-reference-or-literal-widget.test.js, wo die
 * Toggle-Logik selbst nachgebaut ist (sie wird von PHP inline eingebettet),
 * stammt hier ausschließlich das Markup aus einer Nachbildung - die
 * geprüfte Logik kommt aus der ausgelieferten Datei. Die Fixture folgt
 * SchemaOrgData_AdminController::renderScopeSection()
 * (.schemaOrgData-scope, .schemaOrgData-type-fields mit data-schema-type)
 * und SchemaOrgData_AdminPageRenderer::renderTypeSelector()
 * (select.schemaOrgData-type-select samt Option "kein Schema").
 */

var loadPluginScripts = require('./helpers/load-plugin-scripts');

var TYPES = ['LocalBusiness', 'WebSite', 'Event'];

/**
 * Baut eine Scope-Sektion mit Type-Auswahl und je Type einer Feldgruppe.
 * PHP rendert inaktive Feldgruppen mit style="display:none" und deren
 * Felder disabled; eine inaktive Sektion ist zusätzlich als Ganzes
 * ausgeblendet und vollständig deaktiviert.
 *
 * @param {{scope: string, idPrefix: string, selectedType: string,
 *          active: boolean}} opts
 * @returns {string}
 */
function buildScopeSection(opts) {
    var html = ''
        + '<div class="schemaOrgData-scope card mb" data-scope="' + opts.scope + '"'
        + ' data-scope-cat="" data-scope-page=""'
        + (opts.active ? '' : ' style="display:none"') + '>'
        + '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
        + '<div class="mo-in-li-r"><div class="mo-select-div flex">'
        + '<select id="schemaOrgData_' + opts.idPrefix + '_type" name="schemaOrgData[' + opts.scope + '][type]"'
        + ' class="mo-select flex-100 schemaOrgData-type-select">'
        + '<option value=""' + (opts.selectedType === '' ? ' selected="selected"' : '') + '>– kein Schema –</option>';

    TYPES.forEach(function (type) {
        html += '<option value="' + type + '"' + (opts.selectedType === type ? ' selected="selected"' : '') + '>' + type + '</option>';
    });

    html += '</select></div></div></div>';

    TYPES.forEach(function (type) {
        var typeActive = (type === opts.selectedType);
        html += '<div class="schemaOrgData-type-fields" data-schema-type="' + type + '"'
            + (typeActive ? '' : ' style="display:none"') + '>'
            + '<input type="text" id="schemaOrgData_' + opts.idPrefix + '_' + type + '_name"'
            + ' name="schemaOrgData[' + opts.scope + '][data][name]" value=""'
            + (typeActive && opts.active ? '' : ' disabled="disabled"') + ' />'
            + '</div>';
    });

    return html + '</div>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

function typeSelect(idPrefix) {
    return document.getElementById('schemaOrgData_' + idPrefix + '_type');
}

function group(idPrefix, type) {
    var found = null;
    document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
        if (section.querySelector('.schemaOrgData-type-select').id !== 'schemaOrgData_' + idPrefix + '_type') {
            return;
        }
        section.querySelectorAll('.schemaOrgData-type-fields').forEach(function (candidate) {
            if (candidate.getAttribute('data-schema-type') === type) {
                found = candidate;
            }
        });
    });
    return found;
}

/**
 * Liefert je Feldgruppe der Sektion, ob sie sichtbar ist und ob ihr
 * Textfeld aktiv ist - so lassen sich die Zustände aller Types einer
 * Sektion in einer Zusicherung vergleichen.
 *
 * @param {string} idPrefix
 * @returns {object}
 */
function groupStates(idPrefix) {
    var states = {};
    TYPES.forEach(function (type) {
        var el = group(idPrefix, type);
        states[type] = {
            visible: el.style.display !== 'none',
            enabled: !el.querySelector('input').disabled
        };
    });
    return states;
}

describe('Type-Umschaltung (initTypeSwitcher)', function () {
    var validator;

    function setup(html) {
        document.body.innerHTML = html;
        validator = loadPluginScripts.loadValidator();
        return validator;
    }

    beforeEach(function () {
        document.body.innerHTML = '';
    });

    it('aktiviert bei sichtbarer Sektion nur die Feldgruppe des gewählten Types', function () {
        setup(buildScopeSection({ scope: 'global', idPrefix: 'global', selectedType: 'WebSite', active: true }));

        validator.initTypeSwitcher();

        expect(groupStates('global')).toEqual({
            LocalBusiness: { visible: false, enabled: false },
            WebSite: { visible: true, enabled: true },
            Event: { visible: false, enabled: false }
        });
    });

    it('lässt die Feldgruppen einer ausgeblendeten Sektion unverändert', function () {
        setup(buildScopeSection({ scope: 'cat', idPrefix: 'cat_Aktuelles', selectedType: 'Event', active: false }));
        var before = groupStates('cat_Aktuelles');

        validator.initTypeSwitcher();

        expect(groupStates('cat_Aktuelles')).toEqual(before);
        expect(before.Event).toEqual({ visible: true, enabled: false });
    });

    it('aktiviert bei einem Wechsel die neue Feldgruppe und deaktiviert die vorherige', function () {
        setup(buildScopeSection({ scope: 'global', idPrefix: 'global', selectedType: 'WebSite', active: true }));
        validator.initTypeSwitcher();

        typeSelect('global').value = 'Event';
        fire(typeSelect('global'), 'change');

        expect(groupStates('global')).toEqual({
            LocalBusiness: { visible: false, enabled: false },
            WebSite: { visible: false, enabled: false },
            Event: { visible: true, enabled: true }
        });
    });

    it('lässt bei der Auswahl "kein Schema" keine Feldgruppe aktiv', function () {
        setup(buildScopeSection({ scope: 'global', idPrefix: 'global', selectedType: 'WebSite', active: true }));
        validator.initTypeSwitcher();

        typeSelect('global').value = '';
        fire(typeSelect('global'), 'change');

        expect(groupStates('global')).toEqual({
            LocalBusiness: { visible: false, enabled: false },
            WebSite: { visible: false, enabled: false },
            Event: { visible: false, enabled: false }
        });
    });

    it('überspringt eine Type-Auswahl ohne umschließende Scope-Sektion', function () {
        setup(''
            + '<div class="mo-select-div flex">'
            + '<select id="schemaOrgData_lose_type" class="mo-select flex-100 schemaOrgData-type-select">'
            + '<option value="">– kein Schema –</option>'
            + '<option value="Event" selected="selected">Event</option>'
            + '</select></div>'
            + '<div class="schemaOrgData-type-fields" data-schema-type="Event"></div>');

        expect(function () { validator.initTypeSwitcher(); }).not.toThrow();

        var loose = document.getElementById('schemaOrgData_lose_type');
        loose.value = '';
        fire(loose, 'change');

        // Ohne .schemaOrgData-scope wird kein Listener registriert - die
        // Feldgruppe außerhalb einer Sektion bleibt unberührt.
        expect(document.querySelector('.schemaOrgData-type-fields').style.display).toBe('');
    });

    it('wirkt bei zwei Sektionen jeweils nur innerhalb der eigenen Sektion', function () {
        setup(''
            + buildScopeSection({ scope: 'global', idPrefix: 'global', selectedType: 'WebSite', active: true })
            + buildScopeSection({ scope: 'cat', idPrefix: 'cat_Aktuelles', selectedType: 'LocalBusiness', active: true }));
        validator.initTypeSwitcher();
        var otherBefore = groupStates('cat_Aktuelles');

        typeSelect('global').value = 'Event';
        fire(typeSelect('global'), 'change');

        expect(groupStates('global').Event).toEqual({ visible: true, enabled: true });
        expect(groupStates('cat_Aktuelles')).toEqual(otherBefore);
        expect(otherBefore.LocalBusiness).toEqual({ visible: true, enabled: true });
    });
});
