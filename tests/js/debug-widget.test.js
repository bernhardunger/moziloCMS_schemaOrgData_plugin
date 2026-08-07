'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

var DATA_ID = 'schemaOrgData-debug-data';

/**
 * Baut den JSON-Datenblock exakt nach dem Muster von
 * SchemaOrgData_FrontendRenderer::buildDebugWidget(): ein
 * <script type="application/json"> mit der ID, die debug-widget.js liest.
 *
 * @param {object} payload
 * @returns {string}
 */
function buildDataBlock(payload) {
    return '<script type="application/json" id="' + DATA_ID + '">'
        + JSON.stringify(payload)
        + '</script>';
}

function samplePayload() {
    return {
        label: '2 JSON-LD-Blöcke',
        blocks: [
            {
                scope: 'global',
                type: 'LocalBusiness',
                json: '{\n  "@type": "LocalBusiness",\n  "name": "Müller & Partner"\n}'
            },
            {
                scope: 'page_leistungen_beratung',
                type: 'Event',
                json: '{\n  "@type": "Event"\n}'
            }
        ]
    };
}

function setDataBlock(payloadOrRaw) {
    document.head.innerHTML = typeof payloadOrRaw === 'string'
        ? '<script type="application/json" id="' + DATA_ID + '">' + payloadOrRaw + '</script>'
        : buildDataBlock(payloadOrRaw);
}

function click(el) {
    el.dispatchEvent(new window.Event('click', { bubbles: true }));
}

describe('Debug-Widget - Aufbau aus dem JSON-Datenblock (debug-widget.js)', function () {
    var widget;

    beforeEach(function () {
        document.head.innerHTML = '';
        document.body.innerHTML = '';

        // jsdom (Fassung hinter jest-environment-jsdom 29) kennt
        // HTMLDialogElement, implementiert aber weder showModal() noch
        // close(). Ohne diese Attrappen bliebe der Trigger-Zweig
        // strukturell unerreichbar - debug-widget.js haengt den
        // Klick-Listener nur, wenn dialog.showModal existiert.
        window.HTMLDialogElement.prototype.showModal = jest.fn();
        window.HTMLDialogElement.prototype.close = jest.fn();

        widget = loadPluginScripts.loadDebugWidget();
    });

    function trigger() {
        return document.getElementById('schemaOrgData-debug-trigger');
    }

    function dialog() {
        return document.getElementById('schemaOrgData-debug-dialog');
    }

    test('das Skript initialisiert sich beim Laden nicht selbst', function () {
        setDataBlock(samplePayload());

        // Der Präsenz-Guard hängt ausschließlich an DOMContentLoaded, das
        // im bereits geladenen Testdokument nicht mehr feuert. Feuerte es,
        // wäre der Aufbaumoment aus der Suite heraus nicht steuerbar.
        expect(loadPluginScripts.loadDebugWidget()).toBeDefined();
        expect(document.body.children.length).toBe(0);
    });

    test('ohne Datenblock baut init() nichts auf', function () {
        widget.init();

        expect(document.body.children.length).toBe(0);
    });

    test('unparsbarer Datenblock baut nichts auf und wirft nicht', function () {
        setDataBlock('{kein gueltiges JSON');

        expect(function () { widget.init(); }).not.toThrow();
        expect(document.body.children.length).toBe(0);
    });

    test('Nutzlast ohne blocks-Schlüssel baut nichts auf', function () {
        setDataBlock({ label: '0 JSON-LD-Blöcke' });

        widget.init();

        expect(document.body.children.length).toBe(0);
    });

    test('Trigger und Dialog hängen nach init() an document.body', function () {
        setDataBlock(samplePayload());

        widget.init();

        expect(trigger()).not.toBeNull();
        expect(dialog()).not.toBeNull();
        expect(document.body.contains(trigger())).toBe(true);
        expect(document.body.contains(dialog())).toBe(true);
    });

    test('die Trigger-Beschriftung übernimmt das label der Nutzlast', function () {
        setDataBlock(samplePayload());

        widget.init();

        expect(trigger().textContent).toContain('2 JSON-LD-Blöcke');
    });

    test('je Block entstehen Kopierschaltfläche und Vorschau mit laufender ID', function () {
        setDataBlock(samplePayload());

        widget.init();

        expect(document.getElementById('schemaOrgData-debug-copy-0')).not.toBeNull();
        expect(document.getElementById('schemaOrgData-debug-pre-0')).not.toBeNull();
        expect(document.getElementById('schemaOrgData-debug-copy-1')).not.toBeNull();
        expect(document.getElementById('schemaOrgData-debug-pre-1')).not.toBeNull();
        expect(document.getElementById('schemaOrgData-debug-pre-2')).toBeNull();
    });

    test('die Vorschau trägt das JSON der Nutzlast unverändert, Zeilenumbrüche und Umlaute eingeschlossen', function () {
        var payload = samplePayload();
        setDataBlock(payload);

        widget.init();

        expect(document.getElementById('schemaOrgData-debug-pre-0').textContent)
            .toBe(payload.blocks[0].json);
    });

    test('der Blocktitel setzt sich aus Geltungsbereich und Type zusammen', function () {
        setDataBlock(samplePayload());

        widget.init();

        var titel = Array.prototype.map.call(
            dialog().querySelectorAll('h3'),
            function (h) { return h.textContent; }
        );

        expect(titel[0]).toContain('global');
        expect(titel[0]).toContain('LocalBusiness');
        expect(titel[1]).toContain('page_leistungen_beratung');
        expect(titel[1]).toContain('Event');
    });

    test('der Validator-Link zeigt auf validator.schema.org und öffnet entkoppelt', function () {
        setDataBlock(samplePayload());

        widget.init();

        var link = dialog().querySelector('a');

        expect(link.getAttribute('href')).toBe('https://validator.schema.org');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener');
    });

    test('ein Klick auf den Trigger öffnet den Dialog', function () {
        setDataBlock(samplePayload());

        widget.init();
        click(trigger());

        expect(window.HTMLDialogElement.prototype.showModal).toHaveBeenCalled();
    });

    test('ein Klick auf die Schließen-Schaltfläche schließt den Dialog', function () {
        setDataBlock(samplePayload());

        widget.init();
        click(document.getElementById('schemaOrgData-debug-close'));

        expect(window.HTMLDialogElement.prototype.close).toHaveBeenCalled();
    });

    test('ohne Clipboard-API und ohne execCommand meldet die Schaltfläche den Fehlschlag', function () {
        setDataBlock(samplePayload());

        widget.init();
        click(document.getElementById('schemaOrgData-debug-copy-0'));

        expect(document.getElementById('schemaOrgData-debug-copy-0').textContent)
            .toBe('Fehler beim Kopieren');
    });

    test('die Hilfs-Textarea des Kopier-Fallbacks bleibt nicht im Dokument zurück', function () {
        setDataBlock(samplePayload());

        widget.init();
        click(document.getElementById('schemaOrgData-debug-copy-0'));

        expect(document.querySelectorAll('textarea').length).toBe(0);
    });
});
