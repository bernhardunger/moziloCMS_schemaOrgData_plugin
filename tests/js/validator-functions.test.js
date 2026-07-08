'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

// Repräsentatives Mini-Schema für checkFormats()/checkUnknownProperties()/
// validateExtensionField() - bewusst kein Laden einer echten schemas/*.json-
// Datei (das wäre Schema-Validierung, nicht Validator-Logik-Test, siehe
// doc/adr_js_test_infrastructure.md, Entscheidung e/g).
// Hinweis: js/ajv.min.js bündelt kein "ajv-formats" - getAjv() ruft
// ajv.addFormat() nirgends auf, daher wird das "format"-Keyword (z. B.
// "uri" für hasMap) von checkFormats()/validateExtensionField() aktuell
// NICHT tatsächlich geprüft ("strict: false" unterdrückt lediglich die
// sonst von AJV geworfene Ausnahme für das unbekannte Format). Bestehendes
// Produktionsverhalten, außerhalb des Scopes dieses Fixes - das Mini-Schema
// nutzt daher "type" statt "format" als real durchsetzbare Verletzung.
var MINI_SCHEMA = {
    '$schema': 'http://json-schema.org/draft-07/schema#',
    title: 'MiniTestSchema',
    type: 'object',
    properties: {
        name: { type: 'string' },
        hasMap: { type: 'string' },
        count: { type: 'integer' }
    }
};

describe('js/validator.js - reine Validierungsfunktionen', function () {
    var validator;

    beforeEach(function () {
        loadPluginScripts.loadAjv();
        validator = loadPluginScripts.loadValidator();
    });

    describe('validatePostalCode()', function () {
        test('gültige 5-stellige PLZ bei DE', function () {
            expect(validator.validatePostalCode('12345', 'DE').status).toBe('ok');
        });

        test('ungültige PLZ bei DE', function () {
            expect(validator.validatePostalCode('123', 'DE').status).toBe('error');
        });

        test('andere Ländercodes werden nicht geprüft', function () {
            expect(validator.validatePostalCode('ABC', 'AT').status).toBeNull();
        });

        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validatePostalCode('', 'DE').status).toBeNull();
        });
    });

    describe('validateTelephone()', function () {
        test('gültiges E.164-Format mit +-Präfix', function () {
            expect(validator.validateTelephone('+491701234567', 'DE').status).toBe('ok');
        });

        test('gültiges E.164-Format mit 00-Präfix', function () {
            expect(validator.validateTelephone('00491701234567', 'DE').status).toBe('ok');
        });

        test('ungültiges Format', function () {
            expect(validator.validateTelephone('nicht-numerisch', 'DE').status).toBe('error');
        });

        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validateTelephone('', 'DE').status).toBeNull();
        });
    });

    describe('validateUrl()', function () {
        test('https:// ist ok', function () {
            expect(validator.validateUrl('https://example.com').status).toBe('ok');
        });

        test('http:// ergibt eine Warnung, keinen Fehler', function () {
            expect(validator.validateUrl('http://example.com').status).toBe('warning');
        });

        test('ungültige URL ist ein Fehler', function () {
            expect(validator.validateUrl('nicht-valide').status).toBe('error');
        });

        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validateUrl('').status).toBeNull();
        });
    });

    describe('validateEmail()', function () {
        test('gültige E-Mail-Adresse', function () {
            expect(validator.validateEmail('info@example.com').status).toBe('ok');
        });

        test('ungültige E-Mail-Adresse', function () {
            expect(validator.validateEmail('keine-email').status).toBe('error');
        });

        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validateEmail('').status).toBeNull();
        });
    });

    describe('validateRequiredField()', function () {
        test('leerer Wert erzeugt Fehler mit übergebener Meldung', function () {
            var result = validator.validateRequiredField('', 'Pflichtfeld!');
            expect(result.status).toBe('error');
            expect(result.message).toBe('Pflichtfeld!');
        });

        test('befüllter Wert erzeugt keinen Fehler', function () {
            expect(validator.validateRequiredField('Muster GmbH', 'Pflichtfeld!').status).toBeNull();
        });
    });

    describe('validateOpeningHoursTime()', function () {
        test('gültiges Von < Bis', function () {
            expect(validator.validateOpeningHoursTime('09:00', '18:00').status).toBe('ok');
        });

        test('Von >= Bis ist ein Fehler', function () {
            expect(validator.validateOpeningHoursTime('18:00', '09:00').status).toBe('error');
        });

        test('beide Felder leer ("geschlossen") ist kein Fehler', function () {
            expect(validator.validateOpeningHoursTime('', '').status).toBeNull();
        });

        test('nur ein Feld befüllt ist ein Fehler', function () {
            expect(validator.validateOpeningHoursTime('09:00', '').status).toBe('error');
        });

        test('ungültiges Zeitformat ist ein Fehler', function () {
            expect(validator.validateOpeningHoursTime('9:00', '18:00').status).toBe('error');
        });
    });

    describe('validateEventDateInput()', function () {
        test('gültiges ISO-8601-Datum', function () {
            expect(validator.validateEventDateInput('2026-07-07').status).toBe('ok');
        });

        test('gültiges deutsches Format TT.MM.YYYY', function () {
            expect(validator.validateEventDateInput('07.07.2026').status).toBe('ok');
        });

        test('nicht existierender Kalendertag ist ein Fehler', function () {
            expect(validator.validateEventDateInput('31.02.2026').status).toBe('error');
        });

        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validateEventDateInput('').status).toBeNull();
        });

        test('Unsinn-Eingabe ist ein Fehler', function () {
            expect(validator.validateEventDateInput('nicht-ein-datum').status).toBe('error');
        });
    });

    describe('checkSyntax()', function () {
        test('gültiges JSON', function () {
            var result = validator.checkSyntax('{"hasMap": "https://example.com"}');
            expect(result.valid).toBe(true);
            expect(result.data).toEqual({ hasMap: 'https://example.com' });
        });

        test('ungültiges JSON', function () {
            var result = validator.checkSyntax('{invalid');
            expect(result.valid).toBe(false);
            expect(result.data).toBeNull();
        });

        test('leerer String gilt als gültiges leeres Objekt', function () {
            var result = validator.checkSyntax('');
            expect(result.valid).toBe(true);
            expect(result.data).toEqual({});
        });
    });

    describe('checkUnknownProperties()', function () {
        test('bekannte Top-Level-Property', function () {
            expect(validator.checkUnknownProperties({ name: 'x' }, MINI_SCHEMA)).toEqual([]);
        });

        test('unbekannte Top-Level-Property', function () {
            expect(validator.checkUnknownProperties({ foo: 'x' }, MINI_SCHEMA)).toEqual(['foo']);
        });
    });

    describe('checkFormats()/validateExtensionField() gegen Mini-Schema', function () {
        test('gültige Daten ergeben keine Formatfehler', function () {
            var errors = validator.checkFormats({ hasMap: 'https://example.com', count: 3 }, MINI_SCHEMA);
            expect(errors).toEqual([]);
        });

        test('Typverletzung (count keine Zahl) ergibt Formatfehler', function () {
            var errors = validator.checkFormats({ count: 'keine-zahl' }, MINI_SCHEMA);
            expect(errors.length).toBeGreaterThan(0);
        });

        test('validateExtensionField(): ungültiges JSON blockiert vor Schema-Prüfung', function () {
            var result = validator.validateExtensionField('{invalid', MINI_SCHEMA);
            expect(result.valid).toBe(false);
            expect(result.syntaxError).not.toBeNull();
            expect(result.unknownProperties).toEqual([]);
            expect(result.formatErrors).toEqual([]);
        });

        test('validateExtensionField(): unbekannte Property blockiert nicht (nur Warnung)', function () {
            var result = validator.validateExtensionField('{"foo": "bar"}', MINI_SCHEMA);
            expect(result.valid).toBe(true);
            expect(result.unknownProperties).toEqual(['foo']);
        });

        test('validateExtensionField(): Formatfehler blockiert (valid === false)', function () {
            var result = validator.validateExtensionField('{"count": "keine-zahl"}', MINI_SCHEMA);
            expect(result.valid).toBe(false);
            expect(result.formatErrors.length).toBeGreaterThan(0);
        });
    });

    describe('showExtensionFeedback() - DOM-XSS-Schutz (Freeze-Fix-Batch Punkt 3)', function () {
        var feedback;

        beforeEach(function () {
            feedback = document.createElement('div');
            document.body.appendChild(feedback);
        });

        test('Property-Name mit script-artigem Inhalt landet als Text, nicht als Markup', function () {
            var result = {
                syntaxError: null,
                unknownProperties: ['<img src=x onerror=alert(1)>'],
                formatErrors: []
            };

            validator.showExtensionFeedback(feedback, result);

            expect(feedback.querySelector('script')).toBeNull();
            expect(feedback.querySelector('img')).toBeNull();
            expect(feedback.textContent).toContain('<img src=x onerror=alert(1)>');
        });

        test('AJV-Fehlermeldung mit script-artigem Inhalt landet als Text, nicht als Markup', function () {
            var result = {
                syntaxError: null,
                unknownProperties: [],
                formatErrors: [{ instancePath: '</span><script>alert(1)</script>', message: 'kaputt' }]
            };

            validator.showExtensionFeedback(feedback, result);

            expect(feedback.querySelector('script')).toBeNull();
            expect(feedback.textContent).toContain('</span><script>alert(1)</script>');
        });

        test('syntaxError mit script-artigem Inhalt landet als Text, nicht als Markup', function () {
            var result = { syntaxError: '<script>alert(1)</script>', unknownProperties: [], formatErrors: [] };

            validator.showExtensionFeedback(feedback, result);

            expect(feedback.querySelector('script')).toBeNull();
            expect(feedback.textContent).toContain('<script>alert(1)</script>');
        });

        test('valide Daten ohne Warnungen/Fehler zeigen genau ein OK-Feedback', function () {
            var result = { syntaxError: null, unknownProperties: [], formatErrors: [] };

            validator.showExtensionFeedback(feedback, result);

            expect(feedback.children.length).toBe(1);
            expect(feedback.querySelector('.schemaOrgData-feedback--ok')).not.toBeNull();
        });
    });
});
