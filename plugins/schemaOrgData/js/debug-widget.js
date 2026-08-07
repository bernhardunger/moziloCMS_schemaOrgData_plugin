/**
 * schemaOrgData - debug-widget.js
 *
 * Baut das Debug-Widget der Frontend-Ausgabe zur Laufzeit auf:
 * Trigger-Button, <dialog> mit einer Vorschau je JSON-LD-Block und
 * eine Kopierschaltfläche je Block. Sichtbar nur bei eingeschaltetem
 * debug_output (siehe README.md, Abschnitt "JSON-LD-Ausgabe") - ohne
 * diesen Schalter gibt der Renderer weder diese Datei noch den
 * Datenblock aus.
 *
 * Die Nutzlast reist über einen <script type="application/json">-Block
 * mit der ID "schemaOrgData-debug-data". Der Browser führt ihn nicht
 * aus, und der Wert durchläuft genau einen Kodierungskontext statt
 * zweier ineinander liegender.
 *
 * Alle Styles stehen inline am jeweiligen Element: Das Widget landet
 * auf der echten Frontend-Seite und darf dort keine globale CSS-Klasse
 * beanspruchen. Alle IDs sind mit "schemaOrgData-debug-" präfixiert.
 *
 * Die sichtbaren Texte stehen literal und laufen nicht über die
 * Sprachdateien: Das Widget ist ein Entwickler-Werkzeug hinter dem
 * debug_output-Schalter, und die Pluralbildung des Block-Zählers ist
 * sprachstrukturell an das Deutsche gebunden - bloßes Auslagern der
 * Zeichenketten löste sie nicht auf.
 */
(function (window) {
    'use strict';

    var DATA_ELEMENT_ID = 'schemaOrgData-debug-data';

    /**
     * Liest die Nutzlast aus dem JSON-Datenblock.
     *
     * Fehlt der Block oder ist sein Inhalt unparsbar, liefert die
     * Funktion null und das Widget baut nichts auf. Ein
     * Entwickler-Werkzeug darf den Aufbau der Seite nicht mit einer
     * Ausnahme unterbrechen.
     *
     * @returns {object|null}
     */
    function readDebugData() {
        var holder = document.getElementById(DATA_ELEMENT_ID);
        if (!holder) {
            return null;
        }

        try {
            return JSON.parse(holder.textContent || '');
        } catch (e) {
            return null;
        }
    }

    /**
     * @param {string} label Beschriftung aus der Nutzlast, z. B. "2 JSON-LD-Blöcke"
     * @returns {HTMLButtonElement}
     */
    function createTrigger(label) {
        var trigger = document.createElement('button');
        trigger.id = 'schemaOrgData-debug-trigger';
        trigger.type = 'button';
        trigger.style.cssText = 'position:fixed;bottom:1em;right:1em;z-index:9999;background:#1a73e8;'
            + 'color:#fff;border:none;border-radius:4px;padding:.5em 1em;font-size:14px;cursor:pointer;'
            + 'box-shadow:0 2px 8px rgba(0,0,0,.3);';
        trigger.textContent = '🔧 Debug: ' + label;

        return trigger;
    }

    /**
     * @returns {HTMLElement}
     */
    function createDialog() {
        var dialog = document.createElement('dialog');
        dialog.id = 'schemaOrgData-debug-dialog';
        dialog.style.cssText = 'max-width:800px;width:90vw;max-height:85vh;overflow:auto;'
            + 'border-radius:6px;border:1px solid #ccc;box-shadow:0 4px 24px rgba(0,0,0,.2);padding:1.5em;';

        return dialog;
    }

    /**
     * Baut die Kopfzeile des Dialogs samt Validator-Link und
     * Schließen-Schaltfläche.
     *
     * @returns {{header: HTMLElement, closeBtn: HTMLButtonElement}}
     */
    function createHeader() {
        var header = document.createElement('div');
        header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;'
            + 'margin-bottom:1em;border-bottom:1px solid #eee;padding-bottom:.75em;';

        var title = document.createElement('strong');
        title.style.fontSize = '1.1em';
        title.textContent = '🔧 Schema.org JSON-LD Debug';

        var actions = document.createElement('div');
        actions.style.cssText = 'display:flex;gap:.5em;align-items:center;';

        var validatorLink = document.createElement('a');
        validatorLink.href = 'https://validator.schema.org';
        validatorLink.target = '_blank';
        validatorLink.rel = 'noopener';
        validatorLink.style.cssText = 'font-size:.85em;color:#1a73e8;text-decoration:none;'
            + 'border:1px solid #1a73e8;border-radius:3px;padding:.2em .6em;';
        validatorLink.textContent = 'validator.schema.org öffnen ↗';

        var closeBtn = document.createElement('button');
        closeBtn.id = 'schemaOrgData-debug-close';
        closeBtn.type = 'button';
        closeBtn.style.cssText = 'background:none;border:none;font-size:1.3em;cursor:pointer;'
            + 'color:#666;padding:.1em .4em;';
        closeBtn.setAttribute('aria-label', 'Schließen');
        closeBtn.textContent = '✕';

        actions.appendChild(validatorLink);
        actions.appendChild(closeBtn);
        header.appendChild(title);
        header.appendChild(actions);

        return { header: header, closeBtn: closeBtn };
    }

    /**
     * Kopiert über eine Hilfs-Textarea, wenn die Clipboard-API nicht
     * zur Verfügung steht.
     *
     * Die Textarea hängt an dialog, nicht an document.body:
     * dialog.showModal() macht alles außerhalb des Dialogs inert - ein
     * an document.body gehängtes Hilfselement liegt dann im inerten
     * Teilbaum, ta.focus() schlägt still fehl und die Selection bleibt
     * leer, während execCommand("copy") trotzdem true zurückliefert
     * (kopiert die leere Selection statt text). Innerhalb von dialog
     * ist nichts inert.
     *
     * @param {HTMLElement} dialog
     * @param {string} text
     * @returns {boolean}
     */
    function fallbackCopy(dialog, text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        dialog.appendChild(ta);
        ta.focus();
        ta.select();

        var success = false;
        try {
            success = document.execCommand('copy');
        } catch (e) {
            success = false;
        }
        dialog.removeChild(ta);

        return success;
    }

    /**
     * Verdrahtet die Kopierschaltfläche eines Blocks.
     *
     * @param {HTMLButtonElement} copyBtn
     * @param {HTMLElement} pre
     * @param {HTMLElement} dialog
     */
    function attachCopyHandler(copyBtn, pre, dialog) {
        copyBtn.addEventListener('click', function () {
            var text = pre.textContent || pre.innerText;
            var orig = copyBtn.textContent;

            function ok() {
                copyBtn.textContent = 'Kopiert!';
                setTimeout(function () { copyBtn.textContent = orig; }, 1500);
            }

            function fail() {
                copyBtn.textContent = 'Fehler beim Kopieren';
                setTimeout(function () { copyBtn.textContent = orig; }, 1500);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(ok).catch(function () {
                    if (fallbackCopy(dialog, text)) { ok(); } else { fail(); }
                });
            } else {
                if (fallbackCopy(dialog, text)) { ok(); } else { fail(); }
            }
        });
    }

    /**
     * Baut die Vorschau eines einzelnen JSON-LD-Blocks.
     *
     * @param {{scope: string, type: string, json: string}} block
     * @param {number} index Laufindex, speist die Block-IDs
     * @param {HTMLElement} dialog
     * @returns {HTMLElement}
     */
    function createBlockSection(block, index, dialog) {
        var section = document.createElement('div');
        section.style.marginBottom = '1.5em';

        var blockHeader = document.createElement('div');
        blockHeader.style.cssText = 'display:flex;justify-content:space-between;'
            + 'align-items:center;margin-bottom:.4em;';

        var h3 = document.createElement('h3');
        h3.style.cssText = 'margin:0;font-size:.95em;color:#333;';
        h3.textContent = block.scope + ' — ' + block.type;

        var copyBtn = document.createElement('button');
        copyBtn.id = 'schemaOrgData-debug-copy-' + index;
        copyBtn.type = 'button';
        copyBtn.style.cssText = 'font-size:.8em;background:#f5f5f5;border:1px solid #ccc;'
            + 'border-radius:3px;padding:.2em .6em;cursor:pointer;';
        copyBtn.textContent = 'JSON kopieren';

        blockHeader.appendChild(h3);
        blockHeader.appendChild(copyBtn);

        var pre = document.createElement('pre');
        pre.id = 'schemaOrgData-debug-pre-' + index;
        pre.style.cssText = 'background:#f8f8f8;border:1px solid #ddd;border-radius:4px;'
            + 'padding:.75em;overflow:auto;font-size:.8em;white-space:pre-wrap;margin:0;';
        pre.textContent = block.json;

        section.appendChild(blockHeader);
        section.appendChild(pre);
        attachCopyHandler(copyBtn, pre, dialog);

        return section;
    }

    /**
     * Baut Trigger und Dialog auf und hängt sie an document.body.
     *
     * Ohne lesbare Nutzlast passiert nichts - der Aufruf bleibt dann
     * folgenlos statt fehlerhaft.
     */
    function init() {
        var data = readDebugData();
        if (!data || !data.blocks) {
            return;
        }

        var trigger = createTrigger(data.label);
        var dialog = createDialog();
        var headerParts = createHeader();
        dialog.appendChild(headerParts.header);

        data.blocks.forEach(function (block, i) {
            dialog.appendChild(createBlockSection(block, i, dialog));
        });

        document.body.appendChild(trigger);
        document.body.appendChild(dialog);

        if (trigger && dialog && dialog.showModal) {
            trigger.addEventListener('click', function () { dialog.showModal(); });
        }
        headerParts.closeBtn.addEventListener('click', function () { dialog.close(); });
    }

    // Öffentliche API
    window.schemaOrgDataDebugWidget = {
        init: init
    };

    // Selbstauslösung: Das Skript baut das Widget selbst auf, sobald das
    // Dokument geladen ist - der Präsenz-Guard hält es von jeder Seite
    // fern, die den Datenblock nicht führt. Bewusst ausschließlich am
    // Ereignis DOMContentLoaded und ohne Auswertung von
    // document.readyState: Der Renderer bindet die Datei am Platzhalter
    // {schemaOrgData} im <head> ein, dort läuft sie immer vor dem
    // Ereignis. Ein readyState-Zweig würde zusätzlich dann aufbauen, wenn
    // das DOM beim Laden des Skripts bereits steht, und damit in
    // Testumgebungen, die ihr Fixture vor dem Skript aufbauen und danach
    // selbst initialisieren, doppelt einhängen.
    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById(DATA_ELEMENT_ID)) {
            init();
        }
    });

})(window);
