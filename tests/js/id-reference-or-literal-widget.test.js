'use strict';

/**
 * Testet die Umschaltlogik des Widgets id_reference_or_literal
 * (window.schemaOrgDataIdRlToggle) am Beispiel von Article.author -
 * Sichtbarkeit des Literal-name-Feldes ausschließlich bei ausgewähltem
 * "Gast-Autor" (Literal-Modus), Referenz-Dropdown ausschließlich im
 * Referenz-Modus, sowie Nachführung des versteckten _mode-Feldes.
 *
 * Die Toggle-Funktion wird nicht über js/validator.js ausgeliefert,
 * sondern von SchemaOrgData_FormRenderer::renderIdReferenceOrLiteralWidget()
 * inline als <script> in die Formularseite eingebettet (siehe Docblock
 * dort: "Einmalig definierte Toggle-Funktion (idempotent via
 * window-Guard)"). Diese Testdatei baut das Markup exakt nach diesem
 * Muster nach (analog zu buildAddressFixture() in
 * address-required-widget.test.js für das PostalAddress-Widget) und
 * definiert die Funktion 1:1 wie im PHP-Quelltext.
 */

/**
 * Baut das Markup des id_reference_or_literal-Widgets exakt nach dem
 * Muster von renderIdReferenceOrLiteralWidget() nach (Container,
 * verstecktes _mode-Feld, Referenz-Radio+Dropdown, Literal-Radio+
 * name-Feld).
 *
 * @param {string} containerId z. B. "schemaOrgData_page_author_idrl"
 * @param {string} radioGroupName
 * @param {string} modeFieldName
 * @param {'reference'|'literal'} storedMode
 * @returns {string}
 */
function buildIdReferenceOrLiteralFixture(containerId, radioGroupName, modeFieldName, storedMode) {
    var refChecked = storedMode !== 'literal' ? ' checked' : '';
    var litChecked = storedMode === 'literal' ? ' checked' : '';
    var refHidden = storedMode === 'literal' ? ' style="display:none"' : '';
    var litHidden = storedMode !== 'literal' ? ' style="display:none"' : '';

    return ''
        + '<div class="schemaOrgData-idrl-container" id="' + containerId + '">'
        + '<input type="hidden" class="schemaOrgData-idrl-mode-field" name="' + modeFieldName + '" '
        + 'value="' + (storedMode === 'literal' ? 'literal' : 'reference') + '">'
        + '<label><input type="radio" class="schemaOrgData-idrl-radio" name="' + radioGroupName + '" '
        + 'value="reference"' + refChecked + ' onchange="schemaOrgDataIdRlToggle(this)"></label>'
        + '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-reference"' + refHidden + '>'
        + '<select name="author_fragment"><option value="organization">Organization</option></select>'
        + '</div>'
        + '<label><input type="radio" class="schemaOrgData-idrl-radio" name="' + radioGroupName + '" '
        + 'value="literal"' + litChecked + ' onchange="schemaOrgDataIdRlToggle(this)"></label>'
        + '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-literal"' + litHidden + '>'
        + '<input type="text" id="schemaOrgData_page_author_lf_name" name="author_name" value="">'
        + '</div>'
        + '</div>';
}

/**
 * Definiert window.schemaOrgDataIdRlToggle 1:1 wie im emittierten
 * <script>-Block von renderIdReferenceOrLiteralWidget() (nur ohne
 * die window-Guard-Bedingung, da hier je Test frisch geladen).
 */
function installToggleFunction() {
    window.schemaOrgDataIdRlToggle = function (r) {
        var c = r.closest('.schemaOrgData-idrl-container');
        c.querySelectorAll('.schemaOrgData-idrl-section').forEach(function (s) { s.style.display = 'none'; });
        c.querySelector('.schemaOrgData-idrl-' + r.value).style.display = '';
        var h = c.querySelector('.schemaOrgData-idrl-mode-field');
        if (h) { h.value = r.value; }
    };
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

var CONTAINER_ID = 'schemaOrgData_page_author_idrl';
var RADIO_GROUP_NAME = 'schemaOrgData_idrl_page_author_mode';
var MODE_FIELD_NAME = 'schemaOrgData[page][data][author][_mode]';

function referenceSection() {
    return document.querySelector('.schemaOrgData-idrl-reference');
}

function literalSection() {
    return document.querySelector('.schemaOrgData-idrl-literal');
}

function literalNameField() {
    return document.getElementById('schemaOrgData_page_author_lf_name');
}

function modeHiddenField() {
    return document.querySelector('.schemaOrgData-idrl-mode-field');
}

function referenceRadio() {
    return document.querySelector('input[value="reference"]');
}

function literalRadio() {
    return document.querySelector('input[value="literal"]');
}

describe('id_reference_or_literal-Widget (Article.author) - schemaOrgDataIdRlToggle', function () {
    beforeEach(function () {
        delete window.schemaOrgDataIdRlToggle;
        installToggleFunction();
    });

    test('Referenz-Modus (Standard): Referenz-Dropdown sichtbar, Literal-name-Feld verborgen', function () {
        document.body.innerHTML = '<form>'
            + buildIdReferenceOrLiteralFixture(CONTAINER_ID, RADIO_GROUP_NAME, MODE_FIELD_NAME, 'reference')
            + '</form>';

        expect(referenceSection().style.display).toBe('');
        expect(literalSection().style.display).toBe('none');
    });

    test('Literal-Modus (Gast-Autor, vorausgewählt): Literal-name-Feld sichtbar, Referenz-Dropdown verborgen', function () {
        document.body.innerHTML = '<form>'
            + buildIdReferenceOrLiteralFixture(CONTAINER_ID, RADIO_GROUP_NAME, MODE_FIELD_NAME, 'literal')
            + '</form>';

        expect(literalSection().style.display).toBe('');
        expect(referenceSection().style.display).toBe('none');
        expect(literalNameField()).not.toBeNull();
    });

    test('Umschalten von Referenz auf Gast-Autor (literal) blendet das name-Feld ein und das Dropdown aus', function () {
        document.body.innerHTML = '<form>'
            + buildIdReferenceOrLiteralFixture(CONTAINER_ID, RADIO_GROUP_NAME, MODE_FIELD_NAME, 'reference')
            + '</form>';

        literalRadio().checked = true;
        fire(literalRadio(), 'change');

        expect(literalSection().style.display).toBe('');
        expect(referenceSection().style.display).toBe('none');
    });

    test('Umschalten von Gast-Autor (literal) zurück auf Referenz blendet das Dropdown ein und das name-Feld aus', function () {
        document.body.innerHTML = '<form>'
            + buildIdReferenceOrLiteralFixture(CONTAINER_ID, RADIO_GROUP_NAME, MODE_FIELD_NAME, 'literal')
            + '</form>';

        referenceRadio().checked = true;
        fire(referenceRadio(), 'change');

        expect(referenceSection().style.display).toBe('');
        expect(literalSection().style.display).toBe('none');
    });

    test('Umschalten führt das versteckte _mode-Feld nach (tatsächlich gespeicherter Wert)', function () {
        document.body.innerHTML = '<form>'
            + buildIdReferenceOrLiteralFixture(CONTAINER_ID, RADIO_GROUP_NAME, MODE_FIELD_NAME, 'reference')
            + '</form>';

        expect(modeHiddenField().value).toBe('reference');

        literalRadio().checked = true;
        fire(literalRadio(), 'change');

        expect(modeHiddenField().value).toBe('literal');
    });
});
