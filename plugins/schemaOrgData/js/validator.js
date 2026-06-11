/**
 * schemaOrgData - validator.js
 *
 * Client-seitige Validierung des Erweiterungsfelds (JSON-Textarea)
 * gegen das jeweils aktive JSON-Schema (schemas/*.json).
 *
 * Nutzt AJV (js/ajv.min.js, Draft-07, globaler Export "ajv7").
 *
 * Ablauf (siehe CLAUDE.md, Abschnitt "Erweiterungsfeld"):
 *   1. JSON-Syntaxprüfung   - Fehler mit Position sofort anzeigen
 *   2. Property-Whitelist   - unbekannte Properties als Warnung (gelb),
 *                              nicht blockierend
 *   3. Format-Prüfung       - bekannte Properties (z. B. URL-Format
 *                              für "hasMap") gegen das Schema prüfen
 *
 * Server-seitig wird zusätzlich json_decode() vor dem Speichern
 * geprüft (siehe index.php).
 */
(function (window) {
    'use strict';

    /** Gemeinsame AJV-Instanz (Draft-07), lazy initialisiert */
    var ajvInstance = null;

    /**
     * Erstellt bzw. liefert die AJV-Instanz.
     *
     * @returns {object|null} AJV-Instanz oder null, falls ajv.min.js
     *                         nicht geladen wurde
     */
    function getAjv() {
        if (ajvInstance === null && typeof window.ajv7 !== 'undefined') {
            var Ajv = window.ajv7.default || window.ajv7;
            ajvInstance = new Ajv({ allErrors: true, strict: false });
        }
        return ajvInstance;
    }

    /**
     * Schritt 1: Prüft die JSON-Syntax des Erweiterungsfelds.
     *
     * @param {string} jsonText Inhalt des Textarea-Felds
     * @returns {{valid: boolean, data: object|null, error: string|null}}
     */
    function checkSyntax(jsonText) {
        var trimmed = (jsonText || '').trim();

        if (trimmed === '') {
            return { valid: true, data: {}, error: null };
        }

        try {
            return { valid: true, data: JSON.parse(trimmed), error: null };
        } catch (e) {
            // TODO: Zeile/Spalte aus der Fehlermeldung ermitteln und
            //       in admin_lang-Schlüssel "error_json_syntax" einsetzen
            return { valid: false, data: null, error: e.message };
        }
    }

    /**
     * Schritt 2: Prüft, ob alle Top-Level-Properties im aktiven Schema
     * bekannt sind. Unbekannte Properties werden als Warnung
     * zurückgegeben, blockieren das Speichern aber nicht.
     *
     * @param {object} data   geparstes JSON aus dem Erweiterungsfeld
     * @param {object} schema aktives JSON-Schema (schemas/{Type}.json)
     * @returns {string[]} Liste unbekannter Property-Namen
     */
    function checkUnknownProperties(data, schema) {
        var unknown = [];

        if (!schema || !schema.properties || typeof data !== 'object' || data === null) {
            return unknown;
        }

        var knownProperties = Object.keys(schema.properties);

        Object.keys(data).forEach(function (property) {
            if (knownProperties.indexOf(property) === -1) {
                unknown.push(property);
            }
        });

        return unknown;
    }

    /**
     * Schritt 3: Validiert bekannte Properties gegen das aktive Schema
     * (z. B. "format": "uri" für hasMap, "format": "email", etc.).
     *
     * @param {object} data   geparstes JSON aus dem Erweiterungsfeld
     * @param {object} schema aktives JSON-Schema (schemas/{Type}.json)
     * @returns {object[]} Liste der AJV-Fehlerobjekte (leer = gültig)
     */
    function checkFormats(data, schema) {
        var ajv = getAjv();

        if (!ajv || !schema) {
            return [];
        }

        // TODO: Schema ggf. auf bekannte Properties einschränken,
        //       da unbekannte Properties bereits in checkUnknownProperties
        //       behandelt werden (additionalProperties hier nicht blockieren)
        var validate = ajv.compile(schema);

        return validate(data) ? [] : (validate.errors || []);
    }

    /**
     * Führt alle drei Prüfungen aus und liefert ein Gesamtergebnis.
     *
     * @param {string} jsonText Inhalt des Erweiterungsfelds
     * @param {object} schema   aktives JSON-Schema (schemas/{Type}.json)
     * @returns {{
     *   valid: boolean,
     *   data: object|null,
     *   syntaxError: string|null,
     *   unknownProperties: string[],
     *   formatErrors: object[]
     * }}
     */
    function validateExtensionField(jsonText, schema) {
        var syntax = checkSyntax(jsonText);

        if (!syntax.valid) {
            return {
                valid: false,
                data: null,
                syntaxError: syntax.error,
                unknownProperties: [],
                formatErrors: []
            };
        }

        var unknownProperties = checkUnknownProperties(syntax.data, schema);
        var formatErrors = checkFormats(syntax.data, schema);

        return {
            valid: formatErrors.length === 0,
            data: syntax.data,
            syntaxError: null,
            unknownProperties: unknownProperties,
            formatErrors: formatErrors
        };
    }

    // Öffentliche API
    window.schemaOrgDataValidator = {
        validateExtensionField: validateExtensionField,
        checkSyntax: checkSyntax,
        checkUnknownProperties: checkUnknownProperties,
        checkFormats: checkFormats
    };

})(window);
