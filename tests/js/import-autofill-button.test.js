'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Baut das Markup des Import-Bereichs exakt nach dem Muster von
 * SchemaOrgData_AdminPageRenderer::renderExistingJsonLdNotice() nach:
 * Autofill-Button (.schemaOrgData-autofill-btn) mit data-target/
 * data-existing-content, Import-Textarea und der bestehende
 * Submit-Button (name="schemaOrgData_import_action"), alle innerhalb
 * desselben <details>-Blocks.
 *
 * @param {string} scope z. B. "global"
 * @param {string} rawContent Rohwert für data-existing-content, roher
 *        (nicht HTML-escapter) JSON-Text - wird hier wie im echten
 *        htmlspecialchars()-Output escaped
 * @param {boolean} [withSubmitButton] Submit-Button weglassen, um den
 *        Fallback-Pfad ohne gefundenen Submit-Button zu testen
 * @returns {string}
 */
function buildImportNoticeFixture(scope, rawContent, withSubmitButton) {
    var textareaId = 'schemaOrgData_import_' + scope;
    var escapedContent = rawContent.replace(/"/g, '&quot;');
    var submitButton = withSubmitButton === false
        ? ''
        : '<button type="submit" name="schemaOrgData_import_action" value="' + scope + '">Importieren</button>';

    return ''
        + '<details open="open">'
        + '<button type="button" class="mo-btn schemaOrgData-autofill-btn"'
        + ' data-target="' + textareaId + '" data-existing-content="' + escapedContent + '">Erkannten Block importieren</button>'
        + '<textarea id="' + textareaId + '" name="' + textareaId + '"></textarea>'
        + submitButton
        + '</details>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

describe('Import-Autofill-Button (initAutofillButton) - Ein-Klick-Import', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
    });

    test('befüllt das Import-Textarea aus dataset.existingContent UND löst den bestehenden Import-Submit-Button aus', function () {
        document.body.innerHTML = '<form>' + buildImportNoticeFixture('global', '{"@type":"LocalBusiness"}') + '</form>';
        validator.initAutofillButton();

        var submitBtn = document.querySelector('button[name="schemaOrgData_import_action"]');
        var submitSpy = jest.fn(function (event) { event.preventDefault(); });
        submitBtn.addEventListener('click', submitSpy);

        fire(document.querySelector('.schemaOrgData-autofill-btn'), 'click');

        expect(document.getElementById('schemaOrgData_import_global').value).toBe('{"@type":"LocalBusiness"}');
        expect(submitSpy).toHaveBeenCalledTimes(1);
    });

    test('mehrere Import-Bereiche verschiedener Scopes bleiben unabhängig - Klick in einem Scope löst nicht den Submit-Button des anderen Scopes aus', function () {
        document.body.innerHTML = '<form>'
            + buildImportNoticeFixture('global', '{"a":1}')
            + buildImportNoticeFixture('page', '{"b":2}')
            + '</form>';
        validator.initAutofillButton();

        var globalSubmitSpy = jest.fn(function (event) { event.preventDefault(); });
        var pageSubmitSpy = jest.fn(function (event) { event.preventDefault(); });
        document.querySelectorAll('button[name="schemaOrgData_import_action"]')[0].addEventListener('click', globalSubmitSpy);
        document.querySelectorAll('button[name="schemaOrgData_import_action"]')[1].addEventListener('click', pageSubmitSpy);

        fire(document.querySelectorAll('.schemaOrgData-autofill-btn')[0], 'click');

        expect(globalSubmitSpy).toHaveBeenCalledTimes(1);
        expect(pageSubmitSpy).not.toHaveBeenCalled();
        expect(document.getElementById('schemaOrgData_import_page').value).toBe('');
    });

    test('fehlender Submit-Button (Randfall) bricht die Textarea-Befüllung nicht ab', function () {
        document.body.innerHTML = '<form>' + buildImportNoticeFixture('global', '{"a":1}', false) + '</form>';
        validator.initAutofillButton();

        expect(function () {
            fire(document.querySelector('.schemaOrgData-autofill-btn'), 'click');
        }).not.toThrow();
        expect(document.getElementById('schemaOrgData_import_global').value).toBe('{"a":1}');
    });
});
