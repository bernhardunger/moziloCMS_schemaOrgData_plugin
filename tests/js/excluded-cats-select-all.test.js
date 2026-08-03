'use strict';

/**
 * Testet den "Alle Kategorien"-Toggle der Ausschlussliste
 * (initExcludedCatsSelectAll) gegen das ausgelieferte
 * plugins/schemaOrgData/js/validator.js - Setzen/Leeren aller
 * Kategorie-Checkboxen, Nachführen des Toggle-Zustands bei Einzelauswahl
 * und der indeterminate-Zustand bei Teilauswahl.
 *
 * Im Unterschied zu id-reference-or-literal-widget.test.js, wo die
 * Toggle-Logik selbst nachgebaut ist (sie wird von PHP inline eingebettet),
 * stammt hier ausschließlich das Markup aus einer Nachbildung - die
 * geprüfte Logik kommt aus der ausgelieferten Datei. Die Fixture folgt
 * SchemaOrgData_AdminPageRenderer::renderExcludedCatsField(): je Kategorie
 * eine Checkbox mit dem name-Attribut der Ausschlussliste, dazu der
 * name-lose Toggle mit data-select-all.
 *
 * Der name-Wert ist bewusst der echte - er enthält eckige Klammern und
 * landet im Code in einem Attribut-Selektor.
 */

var loadPluginScripts = require('./helpers/load-plugin-scripts');

var CATS_NAME = 'schemaOrgData[global][excluded_cats][]';
var CATS = ['Aktuelles', 'Service', 'Kontakt'];

/**
 * Baut die Ausschlussliste nach renderExcludedCatsField(). Der Toggle
 * trägt bewusst kein name-Attribut (rein clientseitig, ohne Einfluss auf
 * saveConfig()). Einziger Unterschied zur PHP-Ausgabe: die Checkbox-IDs
 * tragen den Kategorienamen statt dessen md5() - der Code liest die
 * Checkboxen ausschließlich über name, die ID dient hier nur dem Zugriff
 * aus den Tests.
 *
 * @param {string[]} preChecked vorbelegte Kategorien
 * @param {string} togglesName Wert des data-select-all-Attributs
 * @returns {string}
 */
function buildExcludedCatsFixture(preChecked, togglesName) {
    var html = '<fieldset class="schemaOrgData-fieldset"><legend>Ausgeschlossene Kategorien</legend>';

    CATS.forEach(function (cat) {
        var fieldId = 'schemaOrgData_global_excluded_cats_' + cat;
        var checked = (preChecked.indexOf(cat) !== -1) ? ' checked="checked"' : '';
        html += '<label class="schemaOrgData-checkbox" for="' + fieldId + '">'
            + '<input type="checkbox" id="' + fieldId + '" name="' + CATS_NAME + '"'
            + ' value="' + cat + '"' + checked + ' /> ' + cat + '</label>';
    });

    html += '<label class="schemaOrgData-checkbox schemaOrgData-checkbox--all" for="schemaOrgData_global_excluded_cats_all">'
        + '<input type="checkbox" id="schemaOrgData_global_excluded_cats_all"'
        + ' data-select-all="' + togglesName + '" /> Alle Kategorien</label>';

    return html + '</fieldset>';
}

function toggle() {
    return document.getElementById('schemaOrgData_global_excluded_cats_all');
}

function catBox(cat) {
    return document.getElementById('schemaOrgData_global_excluded_cats_' + cat);
}

function checkedCats() {
    return CATS.filter(function (cat) {
        return catBox(cat).checked;
    });
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

describe('Ausschlussliste - "Alle Kategorien"-Toggle (initExcludedCatsSelectAll)', function () {
    var validator;

    function setup(preChecked, togglesName) {
        document.body.innerHTML = buildExcludedCatsFixture(
            preChecked,
            (togglesName === undefined) ? CATS_NAME : togglesName
        );
        validator = loadPluginScripts.loadValidator();
        validator.initExcludedCatsSelectAll();
        return validator;
    }

    beforeEach(function () {
        document.body.innerHTML = '';
    });

    it('setzt beim Anhaken alle Kategorie-Checkboxen', function () {
        setup([]);

        toggle().click();

        expect(checkedCats()).toEqual(CATS);
        expect(toggle().indeterminate).toBe(false);
    });

    it('leert beim Abhaken alle Kategorie-Checkboxen', function () {
        setup(CATS);
        expect(toggle().checked).toBe(true);

        toggle().click();

        expect(checkedCats()).toEqual([]);
        expect(toggle().indeterminate).toBe(false);
    });

    it('setzt den Toggle bei einer abgewählten Kategorie auf indeterminate', function () {
        setup(CATS);

        catBox('Service').checked = false;
        fire(catBox('Service'), 'change');

        expect(toggle().checked).toBe(false);
        expect(toggle().indeterminate).toBe(true);
    });

    it('hakt den Toggle an, sobald alle Kategorien einzeln gesetzt sind', function () {
        setup([]);

        CATS.forEach(function (cat) {
            catBox(cat).checked = true;
            fire(catBox(cat), 'change');
        });

        expect(toggle().checked).toBe(true);
        expect(toggle().indeterminate).toBe(false);
    });

    it('steht bei teilweise vorbelegten Checkboxen schon nach dem Init auf indeterminate', function () {
        setup(['Aktuelles']);

        expect(toggle().checked).toBe(false);
        expect(toggle().indeterminate).toBe(true);
    });

    it('überspringt einen Toggle ohne passende Checkboxen', function () {
        expect(function () {
            setup(CATS, 'schemaOrgData[global][gibt_es_nicht][]');
        }).not.toThrow();

        toggle().click();

        // Ohne passende Checkboxen wird kein Listener registriert - die
        // Kategorie-Checkboxen bleiben unberührt.
        expect(checkedCats()).toEqual(CATS);
    });
});
