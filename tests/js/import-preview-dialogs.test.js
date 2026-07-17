'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

/**
 * Baut das Markup eines Block-Vorschau-Bereichs exakt nach dem Muster von
 * SchemaOrgData_AdminPageRenderer::renderExistingJsonLdNotice() nach:
 * Vollansicht-Trigger (.schemaOrgData-preview-trigger-btn, data-dialog)
 * und der zugehörige <dialog> mit Schließen-Button (data-dialog-close) -
 * seit dem Umbau auf den serverseitigen Pro-Block-Import (initPreviewDialogs(),
 * validator.js) ersetzt dies das frühere Inline-<script> je Block.
 *
 * @param {string} scope z. B. "global"
 * @param {number} blockIndex
 * @returns {string}
 */
function buildPreviewFixture(scope, blockIndex) {
    var dialogId = 'schemaOrgData-preview-dialog-' + scope + '-' + blockIndex;

    return ''
        + '<pre class="schemaOrgData-jsonld-preview">{"@type":"LocalBusiness"}</pre>'
        + '<button type="button" class="mo-btn schemaOrgData-preview-trigger-btn" data-dialog="' + dialogId + '">Vollansicht</button>'
        + '<dialog id="' + dialogId + '" class="schemaOrgData-jsonld-preview-dialog">'
        + '<div class="schemaOrgData-jsonld-preview-dialog__header">'
        + '<strong>Erkannter JSON-LD-Block (Vollansicht)</strong>'
        + '<button type="button" class="schemaOrgData-jsonld-preview-dialog__close" data-dialog-close="' + dialogId + '" aria-label="&#x2715;">&#x2715;</button>'
        + '</div>'
        + '<pre class="schemaOrgData-jsonld-preview-full">{"@type":"LocalBusiness"}</pre>'
        + '</dialog>'
        + '<button type="submit" name="schemaOrgData_import_action" value="' + scope + ':' + blockIndex + '" class="mo-btn">Diesen Block importieren</button>';
}

function fire(el, eventType) {
    el.dispatchEvent(new window.Event(eventType, { bubbles: true }));
}

describe('Import-Vorschau-Dialoge (initPreviewDialogs) - Vollansicht Öffnen/Schließen', function () {
    var validator;

    beforeEach(function () {
        validator = loadPluginScripts.loadValidator();
    });

    test('Klick auf den Vollansicht-Trigger öffnet den zugehörigen Dialog', function () {
        document.body.innerHTML = '<form>' + buildPreviewFixture('global', 0) + '</form>';
        var dialog = document.getElementById('schemaOrgData-preview-dialog-global-0');
        dialog.showModal = jest.fn();

        validator.initPreviewDialogs();
        fire(document.querySelector('[data-dialog]'), 'click');

        expect(dialog.showModal).toHaveBeenCalledTimes(1);
    });

    test('Klick auf den Schließen-Button schließt den zugehörigen Dialog', function () {
        document.body.innerHTML = '<form>' + buildPreviewFixture('global', 0) + '</form>';
        var dialog = document.getElementById('schemaOrgData-preview-dialog-global-0');
        dialog.showModal = jest.fn();
        dialog.close = jest.fn();

        validator.initPreviewDialogs();
        fire(document.querySelector('[data-dialog-close]'), 'click');

        expect(dialog.close).toHaveBeenCalledTimes(1);
    });

    test('mehrere Blöcke desselben Scopes bleiben unabhängig - Klick auf Block 0 öffnet nicht den Dialog von Block 1', function () {
        document.body.innerHTML = '<form>'
            + buildPreviewFixture('global', 0)
            + buildPreviewFixture('global', 1)
            + '</form>';
        var dialog0 = document.getElementById('schemaOrgData-preview-dialog-global-0');
        var dialog1 = document.getElementById('schemaOrgData-preview-dialog-global-1');
        dialog0.showModal = jest.fn();
        dialog1.showModal = jest.fn();

        validator.initPreviewDialogs();
        fire(document.querySelectorAll('[data-dialog]')[0], 'click');

        expect(dialog0.showModal).toHaveBeenCalledTimes(1);
        expect(dialog1.showModal).not.toHaveBeenCalled();
    });

    test('fehlendes showModal (jsdom-Fallback ohne <dialog>-Unterstützung) wirft nicht', function () {
        document.body.innerHTML = '<form>' + buildPreviewFixture('global', 0) + '</form>';
        // showModal absichtlich nicht gemockt - deckt den "d && d.showModal"-Guard ab.

        validator.initPreviewDialogs();

        expect(function () {
            fire(document.querySelector('[data-dialog]'), 'click');
        }).not.toThrow();
    });
});
