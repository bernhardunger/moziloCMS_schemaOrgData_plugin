'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Prüft die Verdrahtung der data-action-Attribute (initDataActions(),
 * validator.js). Anders als in den übrigen Suiten stammt das Markup hier
 * nicht aus einem Renderer, sondern bildet den data-action-Kontrakt selbst
 * ab: Noch gibt keine PHP-Ausgabestelle data-action aus, die Attribute
 * entstehen erst mit der Umstellung der heutigen on*-Attribute. Sobald das
 * geschehen ist, ist die PHP-Ausgabe die Referenz für diese Fixtures.
 */

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true, cancelable: true }));
}

/**
 * Dispatcht ein abbrechbares Ereignis und meldet, ob ein Handler
 * preventDefault() gesetzt hat - jsdom führt den Submit selbst nicht aus,
 * das Verhindern ist deshalb nur am Ereignis ablesbar.
 *
 * @param {Element} el
 * @param {string} eventType
 * @returns {boolean} true, wenn preventDefault() gesetzt wurde
 */
function fireAndReportPrevented(el, eventType) {
    var event = new window.Event(eventType, { bubbles: true, cancelable: true });
    el.dispatchEvent(event);
    return event.defaultPrevented;
}

/**
 * Scope-Container und Personen-Container mit den beiden Umschaltern -
 * Ausgangslage wie beim Seitenaufbau: Scope sichtbar, Personen aus.
 *
 * @returns {string}
 */
function buildContainerFixture() {
    return ''
        + '<div id="schemaOrgData_scope_container">'
        + '<button type="button" data-action="persons-open">Personen verwalten</button>'
        + '</div>'
        + '<div id="schemaOrgData_persons_container" style="display:none">'
        + '<button type="button" data-action="persons-back">Zurück</button>'
        + '</div>';
}

/**
 * Die drei Personen-Unteransichten (Liste, Anlegen, Bearbeiten) mit je einem
 * Formularfeld sowie den Umschalt-Buttons.
 *
 * @param {string} targetAttr vollständiges data-persons-target-Attribut des
 *                            Umschalters (leer = Attribut fehlt)
 * @returns {string}
 */
function buildPersonsViewFixture(targetAttr) {
    return ''
        + '<div id="schemaOrgData_persons_list" data-persons-view>'
        + '<input type="text" name="schemaOrgData_persons_data[list]" />'
        + '<button type="button" ' + targetAttr + ' data-action="persons-show">Umschalten</button>'
        + '</div>'
        + '<div id="schemaOrgData_persons_new" data-persons-view style="display:none">'
        + '<input type="text" name="schemaOrgData_persons_data[name]" disabled />'
        + '<select name="schemaOrgData_persons_data[status]" disabled><option value="draft">Entwurf</option></select>'
        + '</div>'
        + '<div id="schemaOrgData_persons_edit_meier" data-persons-view style="display:none">'
        + '<textarea name="schemaOrgData_persons_data[description]" disabled></textarea>'
        + '</div>';
}

/**
 * Referenz-/Literal-Widget: verstecktes Modus-Feld, zwei Radios und die
 * beiden zugehörigen Sektionen.
 *
 * @param {string} mode "reference" oder "literal"
 * @returns {string}
 */
function buildIdRlFixture(mode) {
    var refChecked = (mode === 'reference') ? ' checked="checked"' : '';
    var litChecked = (mode === 'literal') ? ' checked="checked"' : '';
    var refHidden = (mode === 'reference') ? '' : ' style="display:none"';
    var litHidden = (mode === 'literal') ? '' : ' style="display:none"';

    return ''
        + '<div class="schemaOrgData-idrl-container" id="schemaOrgData_idrl_global_author">'
        + '<input type="hidden" class="schemaOrgData-idrl-mode-field" name="schemaOrgData_data[author][_mode]" value="' + mode + '" />'
        + '<label><input type="radio" class="schemaOrgData-idrl-radio" name="schemaOrgData_idrl_global_author_mode"'
        + ' value="reference"' + refChecked + ' data-action="idrl-toggle" /> Referenz</label>'
        + '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-reference"' + refHidden + '>'
        + '<select name="schemaOrgData_data[author][_fragment]"><option value="person-meier">Meier</option></select>'
        + '</div>'
        + '<label><input type="radio" class="schemaOrgData-idrl-radio" name="schemaOrgData_idrl_global_author_mode"'
        + ' value="literal"' + litChecked + ' data-action="idrl-toggle" /> Literal</label>'
        + '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-literal"' + litHidden + '>'
        + '<input type="text" name="schemaOrgData_data[author][name]" />'
        + '</div>'
        + '</div>';
}

function idrlRadio(value) {
    return document.querySelector('.schemaOrgData-idrl-radio[value="' + value + '"]');
}

describe('data-action-Verdrahtung (initDataActions) - Personen-Container', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
        document.body.innerHTML = '<form>' + buildContainerFixture() + '</form>';
    });

    test('persons-open blendet den Scope-Container aus und den Personen-Container ein', function () {
        validator.initDataActions();

        fire(document.querySelector('[data-action="persons-open"]'), 'click');

        expect(document.getElementById('schemaOrgData_scope_container').style.display).toBe('none');
        expect(document.getElementById('schemaOrgData_persons_container').style.display).toBe('');
    });

    test('persons-back blendet den Personen-Container aus und den Scope-Container ein', function () {
        validator.initDataActions();

        fire(document.querySelector('[data-action="persons-back"]'), 'click');

        expect(document.getElementById('schemaOrgData_persons_container').style.display).toBe('none');
        expect(document.getElementById('schemaOrgData_scope_container').style.display).toBe('');
    });
});

describe('data-action-Verdrahtung (initDataActions) - Personen-Unteransichten', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
    });

    test('persons-show schaltet auf die Zielansicht um und aktiviert deren Felder', function () {
        document.body.innerHTML = '<form>'
            + buildPersonsViewFixture('data-persons-target="schemaOrgData_persons_new"')
            + '</form>';

        validator.initDataActions();
        fire(document.querySelector('[data-action="persons-show"]'), 'click');

        var target = document.getElementById('schemaOrgData_persons_new');
        expect(target.style.display).toBe('');
        expect(target.querySelector('input').disabled).toBe(false);
        expect(target.querySelector('select').disabled).toBe(false);

        expect(document.getElementById('schemaOrgData_persons_list').style.display).toBe('none');
        expect(document.querySelector('#schemaOrgData_persons_list input').disabled).toBe(true);
        expect(document.getElementById('schemaOrgData_persons_edit_meier').style.display).toBe('none');
        expect(document.querySelector('#schemaOrgData_persons_edit_meier textarea').disabled).toBe(true);
    });

    test('persons-show ohne data-persons-target lässt alle Ansichten unverändert', function () {
        document.body.innerHTML = '<form>' + buildPersonsViewFixture('') + '</form>';

        validator.initDataActions();

        expect(function () {
            fire(document.querySelector('[data-action="persons-show"]'), 'click');
        }).not.toThrow();

        expect(document.getElementById('schemaOrgData_persons_list').style.display).toBe('');
        expect(document.getElementById('schemaOrgData_persons_new').style.display).toBe('none');
        expect(document.querySelector('#schemaOrgData_persons_new input').disabled).toBe(true);
    });

    test('persons-show mit leerem data-persons-target lässt alle Ansichten unverändert', function () {
        document.body.innerHTML = '<form>' + buildPersonsViewFixture('data-persons-target=""') + '</form>';

        validator.initDataActions();

        expect(function () {
            fire(document.querySelector('[data-action="persons-show"]'), 'click');
        }).not.toThrow();

        expect(document.getElementById('schemaOrgData_persons_list').style.display).toBe('');
        expect(document.getElementById('schemaOrgData_persons_new').style.display).toBe('none');
        expect(document.querySelector('#schemaOrgData_persons_new input').disabled).toBe(true);
    });
});

describe('data-action-Verdrahtung (initDataActions) - Löschbestätigung', function () {
    var validator;
    var originalConfirm;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
        // window.confirm existiert in jsdom, ist aber nicht interaktiv und
        // liefert dort immer undefined - für die Entscheidung des Handlers
        // muss der Rückgabewert deshalb gesetzt werden.
        originalConfirm = window.confirm;
    });

    afterEach(function () {
        window.confirm = originalConfirm;
    });

    test('bestätigte Rückfrage lässt den Submit laufen', function () {
        document.body.innerHTML = '<form><button type="submit" data-action="confirm"'
            + ' data-confirm="Person wirklich löschen?">Löschen</button></form>';
        window.confirm = jest.fn(function () { return true; });

        validator.initDataActions();
        var prevented = fireAndReportPrevented(document.querySelector('[data-action="confirm"]'), 'click');

        expect(window.confirm).toHaveBeenCalledWith('Person wirklich löschen?');
        expect(prevented).toBe(false);
    });

    test('abgelehnte Rückfrage verhindert den Submit', function () {
        document.body.innerHTML = '<form><button type="submit" data-action="confirm"'
            + ' data-confirm="Person wirklich löschen?">Löschen</button></form>';
        window.confirm = jest.fn(function () { return false; });

        validator.initDataActions();
        var prevented = fireAndReportPrevented(document.querySelector('[data-action="confirm"]'), 'click');

        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(prevented).toBe(true);
    });

    test('fehlendes data-confirm fragt nicht nach und lässt den Submit laufen', function () {
        document.body.innerHTML = '<form><button type="submit" data-action="confirm">Löschen</button></form>';
        window.confirm = jest.fn(function () { return false; });

        validator.initDataActions();
        var prevented = fireAndReportPrevented(document.querySelector('[data-action="confirm"]'), 'click');

        expect(window.confirm).not.toHaveBeenCalled();
        expect(prevented).toBe(false);
    });
});

describe('data-action-Verdrahtung (initDataActions) - Referenz-/Literal-Umschalter', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
    });

    test('Wechsel von Referenz auf Literal schaltet die Sektionen um und führt das Modus-Feld nach', function () {
        document.body.innerHTML = '<form>' + buildIdRlFixture('reference') + '</form>';

        validator.initDataActions();
        var literalRadio = idrlRadio('literal');
        literalRadio.checked = true;
        fire(literalRadio, 'change');

        expect(document.querySelector('.schemaOrgData-idrl-literal').style.display).toBe('');
        expect(document.querySelector('.schemaOrgData-idrl-reference').style.display).toBe('none');
        expect(document.querySelector('.schemaOrgData-idrl-mode-field').value).toBe('literal');
    });

    test('Wechsel zurück auf Referenz schaltet die Sektionen zurück und führt das Modus-Feld nach', function () {
        document.body.innerHTML = '<form>' + buildIdRlFixture('literal') + '</form>';

        validator.initDataActions();
        var referenceRadio = idrlRadio('reference');
        referenceRadio.checked = true;
        fire(referenceRadio, 'change');

        expect(document.querySelector('.schemaOrgData-idrl-reference').style.display).toBe('');
        expect(document.querySelector('.schemaOrgData-idrl-literal').style.display).toBe('none');
        expect(document.querySelector('.schemaOrgData-idrl-mode-field').value).toBe('reference');
    });
});

describe('data-action-Verdrahtung (initDataActions) - Randfälle der Verdrahtung', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
    });

    test('unbekannter data-action-Wert wird übersprungen', function () {
        document.body.innerHTML = '<form><button type="button" data-action="gibt-es-nicht">Knopf</button></form>';

        validator.initDataActions();

        expect(function () {
            fire(document.querySelector('[data-action="gibt-es-nicht"]'), 'click');
        }).not.toThrow();
    });

    test('zwei Elemente mit derselben Aktion werden unabhängig voneinander verdrahtet', function () {
        document.body.innerHTML = '<form>'
            + '<div id="schemaOrgData_scope_container">'
            + '<button type="button" id="toggle_top" data-action="persons-open">Oben</button>'
            + '<button type="button" id="toggle_bottom" data-action="persons-open">Unten</button>'
            + '</div>'
            + '<div id="schemaOrgData_persons_container" style="display:none"></div>'
            + '</form>';

        validator.initDataActions();

        fire(document.getElementById('toggle_bottom'), 'click');
        expect(document.getElementById('schemaOrgData_persons_container').style.display).toBe('');

        // Zurücksetzen und den zweiten Auslöser prüfen - beide müssen für sich
        // allein wirken, nicht nur der zuerst verdrahtete.
        document.getElementById('schemaOrgData_persons_container').style.display = 'none';
        document.getElementById('schemaOrgData_scope_container').style.display = '';

        fire(document.getElementById('toggle_top'), 'click');
        expect(document.getElementById('schemaOrgData_persons_container').style.display).toBe('');
        expect(document.getElementById('schemaOrgData_scope_container').style.display).toBe('none');
    });

    test('ein Dokument ohne data-action kehrt ohne Ausnahme zurück', function () {
        document.body.innerHTML = '<form><button type="button">Ohne Aktion</button></form>';

        expect(function () {
            validator.initDataActions();
        }).not.toThrow();
    });
});
