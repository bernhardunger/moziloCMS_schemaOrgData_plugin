'use strict';

/**
 * Testet den zweistufigen Scope-Selektor (initScopeSelector) gegen das
 * ausgelieferte plugins/schemaOrgData/js/validator.js - Vorbelegung beim
 * Init, Umschalten der Sektionen, Nachführen der Hidden-Felder und der
 * Speichern-Beschriftungen sowie den Hinweis auf ungespeicherte Eingaben.
 *
 * Im Unterschied zu id-reference-or-literal-widget.test.js, wo die
 * Toggle-Logik selbst nachgebaut ist (sie wird von PHP inline eingebettet),
 * stammt hier ausschließlich das Markup aus einer Nachbildung - die
 * geprüfte Logik kommt aus der ausgelieferten Datei. Die Fixtures folgen
 * den erzeugenden Renderern:
 * SchemaOrgData_AdminPageRenderer::renderScopeSelector() (Select-Paar samt
 * data-pages), SchemaOrgData_AdminController::renderScopeSection()
 * (.schemaOrgData-scope mit data-scope-cat/-page/-label/-save-label) und
 * SchemaOrgData_AdminController::renderAdminPage() (.schemaOrgData-admin,
 * Hidden-Felder, die beiden Speichern-Leisten).
 */

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

var UNSAVED_TEMPLATE = 'Im Bereich {PARAM1} liegen ungespeicherte Eingaben vor.';

/**
 * Seiten-Map wie renderScopeSelector() sie als data-pages hinterlegt:
 * "value" trägt den rohen (URL-kodierten) moziloCMS-Bezeichner, "label"
 * die dekodierte Anzeigeform.
 */
var PAGES_BY_CAT = {
    'Aktuelles': [
        { value: 'neuigkeiten', label: 'neuigkeiten' },
        { value: 'kontakt', label: 'kontakt' }
    ],
    'Service': [
        { value: '%C3%9Cber-uns', label: 'Über-uns' }
    ]
};

function escapeAttr(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/**
 * Baut das Select-Paar nach renderScopeSelector(): Stufe 1 mit "Global"
 * plus Kategorien, Stufe 2 mit der Platzhalter-Option und der data-pages-
 * Map. Die Sichtbarkeit von Stufe 2 richtet sich serverseitig danach, ob
 * bereits eine Kategorie aktiv ist.
 *
 * @param {string} pagesJson
 * @param {boolean} pageHidden
 * @returns {string}
 */
function buildScopeSelector(pagesJson, pageHidden) {
    return ''
        + '<div class="schemaOrgData-scope-selector">'
        + '<label class="schemaOrgData-scope-selector__label" for="schemaOrgData_scope_cat">Geltungsbereich</label>'
        + '<select id="schemaOrgData_scope_cat" class="mo-select schemaOrgData-scope-selector__select">'
        + '<option value="">Global</option>'
        + '<option value="Aktuelles">Aktuelles</option>'
        + '<option value="Service">Service</option>'
        + '</select>'
        + '<select id="schemaOrgData_scope_page" class="mo-select schemaOrgData-scope-selector__select"'
        + ' data-pages="' + escapeAttr(pagesJson) + '"' + (pageHidden ? ' style="display:none"' : '') + '>'
        + '<option value="">— Kategorie —</option>'
        + '</select>'
        + '</div>';
}

/**
 * Baut eine Geltungsbereichs-Sektion nach renderScopeSection(): Wrapper
 * mit den vier data-Attributen, darin die Type-Auswahl und je Type ein
 * .schemaOrgData-type-fields-Block mit einem Textfeld. Inaktive Sektionen
 * rendert PHP mit style="display:none".
 *
 * @param {{cat: string, page: string, label: string, saveLabel: string,
 *          idPrefix: string, scope: string, selectedType: string,
 *          active: boolean}} opts
 * @returns {string}
 */
function buildScopeSection(opts) {
    var types = ['LocalBusiness', 'WebSite'];
    var html = ''
        + '<div class="schemaOrgData-scope card mb" data-scope="' + opts.scope + '"'
        + ' data-scope-cat="' + escapeAttr(opts.cat) + '" data-scope-page="' + escapeAttr(opts.page) + '"'
        + ' data-scope-label="' + escapeAttr(opts.label) + '" data-save-label="' + escapeAttr(opts.saveLabel) + '"'
        + (opts.active ? '' : ' style="display:none"') + '>'
        + '<h3>' + escapeAttr(opts.label) + '</h3>'
        + '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
        + '<div class="mo-in-li-l"><label for="schemaOrgData_' + opts.idPrefix + '_type">Schema-Type</label></div>'
        + '<div class="mo-in-li-r"><div class="mo-select-div flex">'
        + '<select id="schemaOrgData_' + opts.idPrefix + '_type" name="schemaOrgData[' + opts.scope + '][type]"'
        + ' class="mo-select flex-100 schemaOrgData-type-select">'
        + '<option value=""' + (opts.selectedType === '' ? ' selected="selected"' : '') + '>– kein Schema –</option>';

    types.forEach(function (type) {
        html += '<option value="' + type + '"' + (opts.selectedType === type ? ' selected="selected"' : '') + '>' + type + '</option>';
    });

    html += '</select></div></div></div>';

    types.forEach(function (type) {
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

/**
 * Baut die vollständige Admin-Seite nach renderAdminPage(): Wrapper,
 * Speichern-Leiste oben, Scope-Selektor, die drei Sektionen (Global,
 * Kategorie, Seite), die beiden Hidden-Felder und die untere
 * Speichern-Leiste.
 *
 * @param {{active: 'global'|'cat'|'page', pagesJson?: string,
 *          hiddenCat?: string, hiddenPage?: string,
 *          saveNotice?: boolean}} opts
 * @returns {string}
 */
function buildAdminPage(opts) {
    var active = opts.active;
    var pagesJson = (opts.pagesJson !== undefined) ? opts.pagesJson : JSON.stringify(PAGES_BY_CAT);
    var saveLabel = 'Global speichern';

    return adminContainer.buildAdminContainer({ unsavedChanges: UNSAVED_TEMPLATE }, ''
        + (opts.saveNotice ? '<div id="schemaOrgData_save_notice" class="schemaOrgData-notice schemaOrgData-notice--success">Gespeichert.</div>' : '')
        + '<div id="schemaOrgData_scope_container">'
        + '<div class="schemaOrgData-admin-toolbar">'
        + '<div class="schemaOrgData-save-bar schemaOrgData-save-bar--top">'
        + '<button type="submit" class="mo-btn mo-btn--primary">' + saveLabel + '</button>'
        + '</div></div>'
        + buildScopeSelector(pagesJson, active === 'global')
        + buildScopeSection({
            scope: 'global', cat: '', page: '', label: 'Global',
            saveLabel: saveLabel, idPrefix: 'global',
            selectedType: 'LocalBusiness', active: (active === 'global')
        })
        + buildScopeSection({
            scope: 'cat', cat: 'Aktuelles', page: '', label: 'Kategorie Aktuelles',
            saveLabel: 'Kategorie Aktuelles speichern', idPrefix: 'cat_Aktuelles',
            selectedType: 'WebSite', active: (active === 'cat')
        })
        + buildScopeSection({
            scope: 'page', cat: 'Aktuelles', page: 'kontakt', label: 'Seite kontakt',
            saveLabel: 'Seite kontakt speichern', idPrefix: 'page_Aktuelles_kontakt',
            selectedType: 'LocalBusiness', active: (active === 'page')
        })
        + '<input type="hidden" id="schemaOrgData_hidden_cat" name="schemaOrgData_cat" value="'
        + escapeAttr(opts.hiddenCat || '') + '" />'
        + '<input type="hidden" id="schemaOrgData_hidden_page" name="schemaOrgData_page" value="'
        + escapeAttr(opts.hiddenPage || '') + '" />'
        + '<div class="schemaOrgData-save-bar">'
        + '<button type="submit" class="mo-btn mo-btn--primary">' + saveLabel + '</button>'
        + '</div>'
        + '</div>');
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

function catSelect() {
    return document.getElementById('schemaOrgData_scope_cat');
}

function pageSelect() {
    return document.getElementById('schemaOrgData_scope_page');
}

function hiddenCat() {
    return document.getElementById('schemaOrgData_hidden_cat');
}

function hiddenPage() {
    return document.getElementById('schemaOrgData_hidden_page');
}

function sectionFor(cat, page) {
    var found = null;
    document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
        if (section.getAttribute('data-scope-cat') === cat
            && section.getAttribute('data-scope-page') === page) {
            found = section;
        }
    });
    return found;
}

function optionValues(select) {
    return Array.prototype.map.call(select.options, function (option) {
        return option.value;
    });
}

function unsavedNotice() {
    return document.getElementById('schemaOrgData_unsaved_notice');
}

describe('Scope-Selektor (initScopeSelector)', function () {
    var validator;

    function setup(html) {
        document.body.innerHTML = html;
        validator = loadPluginScripts.loadValidator();
        return validator;
    }

    beforeEach(function () {
        document.body.innerHTML = '';
    });

    describe('Initialzustand', function () {
        it('belegt bei sichtbarer Global-Sektion beide Selects und die Hidden-Felder leer vor', function () {
            setup(buildAdminPage({ active: 'global', hiddenCat: 'Aktuelles', hiddenPage: 'kontakt' }));

            validator.initScopeSelector();

            expect(catSelect().value).toBe('');
            expect(hiddenCat().value).toBe('');
            expect(hiddenPage().value).toBe('');
            expect(pageSelect().style.display).toBe('none');
            expect(optionValues(pageSelect())).toEqual(['']);
        });

        it('belegt bei sichtbarer Kategorie-Sektion Stufe 1 vor und befüllt Stufe 2 sichtbar', function () {
            setup(buildAdminPage({ active: 'cat' }));

            validator.initScopeSelector();

            expect(catSelect().value).toBe('Aktuelles');
            expect(optionValues(pageSelect())).toEqual(['', 'neuigkeiten', 'kontakt']);
            expect(pageSelect().style.display).toBe('');
            expect(pageSelect().value).toBe('');
            expect(hiddenCat().value).toBe('Aktuelles');
            expect(hiddenPage().value).toBe('');
        });

        it('wählt bei sichtbarer Seiten-Sektion zusätzlich die Seite in Stufe 2 vor', function () {
            setup(buildAdminPage({ active: 'page' }));

            validator.initScopeSelector();

            expect(catSelect().value).toBe('Aktuelles');
            expect(pageSelect().value).toBe('kontakt');
            expect(hiddenCat().value).toBe('Aktuelles');
            expect(hiddenPage().value).toBe('kontakt');
        });

        it('kehrt ohne Ausnahme und ohne DOM-Änderung zurück, wenn eines der beiden Selects fehlt', function () {
            setup(buildAdminPage({ active: 'cat' }));
            pageSelect().remove();
            var withoutPage = document.body.innerHTML;

            expect(function () { validator.initScopeSelector(); }).not.toThrow();
            expect(document.body.innerHTML).toBe(withoutPage);

            document.body.innerHTML = buildAdminPage({ active: 'cat' });
            catSelect().remove();
            var withoutCat = document.body.innerHTML;

            expect(function () { validator.initScopeSelector(); }).not.toThrow();
            expect(document.body.innerHTML).toBe(withoutCat);
        });

        it('behandelt ein defektes data-pages als leere Map, ohne zu scheitern', function () {
            setup(buildAdminPage({ active: 'cat', pagesJson: '{kein gültiges JSON' }));

            // Ohne den Spy schriebe der Diagnosezweig bei jedem Lauf in die
            // Testausgabe; setup() initialisiert nicht selbst, der Aufruf
            // unten ist der einzige.
            var warnSpy = jest.spyOn(console, 'warn').mockImplementation(function () {});

            expect(function () { validator.initScopeSelector(); }).not.toThrow();

            expect(optionValues(pageSelect())).toEqual(['']);
            expect(pageSelect().style.display).toBe('');
            expect(warnSpy).toHaveBeenCalledTimes(1);

            warnSpy.mockRestore();
        });
    });

    describe('Wechsel des Geltungsbereichs', function () {
        function switchCatTo(value) {
            catSelect().value = value;
            fire(catSelect(), 'change');
        }

        it('blendet die passende Sektion ein, alle anderen aus und deaktiviert deren Felder', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            switchCatTo('Aktuelles');

            expect(sectionFor('Aktuelles', '').style.display).toBe('');
            expect(sectionFor('', '').style.display).toBe('none');
            expect(sectionFor('Aktuelles', 'kontakt').style.display).toBe('none');

            sectionFor('', '').querySelectorAll('input, select, textarea').forEach(function (el) {
                expect(el.disabled).toBe(true);
            });
            expect(sectionFor('Aktuelles', '').querySelector('.schemaOrgData-type-select').disabled).toBe(false);
        });

        it('befüllt Stufe 2 neu und blendet sie nur bei nicht-leerer Kategorie ein', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            switchCatTo('Service');
            expect(optionValues(pageSelect())).toEqual(['', '%C3%9Cber-uns']);
            expect(pageSelect().style.display).toBe('');

            switchCatTo('');
            expect(optionValues(pageSelect())).toEqual(['']);
            expect(pageSelect().style.display).toBe('none');
        });

        it('trägt den neuen Geltungsbereich in die Hidden-Felder ein', function () {
            setup(buildAdminPage({ active: 'page', hiddenCat: 'Aktuelles', hiddenPage: 'kontakt' }));
            validator.initScopeSelector();

            switchCatTo('Aktuelles');
            expect(hiddenCat().value).toBe('Aktuelles');
            expect(hiddenPage().value).toBe('');

            pageSelect().value = 'kontakt';
            fire(pageSelect(), 'change');
            expect(hiddenCat().value).toBe('Aktuelles');
            expect(hiddenPage().value).toBe('kontakt');
        });

        it('zieht die Beschriftung beider Speichern-Buttons aus data-save-label der aktiven Sektion nach', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            switchCatTo('Aktuelles');

            var labels = Array.prototype.map.call(
                document.querySelectorAll('.schemaOrgData-save-bar button'),
                function (btn) { return btn.textContent; }
            );
            expect(labels).toEqual(['Kategorie Aktuelles speichern', 'Kategorie Aktuelles speichern']);
        });

        it('entfernt die serverseitige Save-Ergebnis-Box', function () {
            setup(buildAdminPage({ active: 'global', saveNotice: true }));
            validator.initScopeSelector();
            expect(document.getElementById('schemaOrgData_save_notice')).not.toBeNull();

            switchCatTo('Aktuelles');

            expect(document.getElementById('schemaOrgData_save_notice')).toBeNull();
        });
    });

    describe('Hinweis auf ungespeicherte Eingaben', function () {
        function switchCatTo(value) {
            catSelect().value = value;
            fire(catSelect(), 'change');
        }

        function globalNameField() {
            return document.getElementById('schemaOrgData_global_LocalBusiness_name');
        }

        it('zeigt beim Verlassen einer geänderten Sektion deren data-scope-label an', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            globalNameField().value = 'Muster GmbH';
            switchCatTo('Aktuelles');

            expect(unsavedNotice()).not.toBeNull();
            expect(unsavedNotice().textContent).toBe('Im Bereich Global liegen ungespeicherte Eingaben vor.');
        });

        it('entfernt den Hinweis beim Rückwechsel in die markierte Sektion', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            globalNameField().value = 'Muster GmbH';
            switchCatTo('Aktuelles');
            expect(unsavedNotice()).not.toBeNull();

            switchCatTo('');

            expect(unsavedNotice()).toBeNull();
        });

        it('zeigt beim Verlassen einer unveränderten Sektion keinen Hinweis', function () {
            setup(buildAdminPage({ active: 'global' }));
            validator.initScopeSelector();

            switchCatTo('Aktuelles');

            expect(unsavedNotice()).toBeNull();
        });
    });
});
