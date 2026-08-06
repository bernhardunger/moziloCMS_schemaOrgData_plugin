'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

// Repräsentatives Mini-Schema für checkFormats()/checkUnknownProperties()/
// validateExtensionField() - bewusst kein Laden einer echten schemas/*.json-
// Datei (das wäre Schema-Validierung, nicht Validator-Logik-Test).
// getAjv() registriert die drei im Projekt genutzten Formate ("uri",
// "email", "date-time") über eigene Prädikate (siehe isValidUrlFormat()/
// isValidEmailFormat()/isValidIso8601Format() in js/validator.js) - das
// "format"-Keyword wird also tatsächlich geprüft. Das Mini-Schema nutzt
// trotzdem "type" statt "format" als Verletzung: eigene, dedizierte Tests
// für "format" stehen weiter unten (checkFormats()-Block), das Mini-Schema
// bleibt damit auf die hier im Vordergrund stehende Property-Whitelist-
// und Typ-Prüfung fokussiert.
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

// Eigenes Mini-Schema für die "format"-Prüfung (B5-01): getAjv() registriert
// "uri"/"email"/"date-time" über dieselben Prädikate wie validateUrl()/
// validateEmail()/isValidIso8601Format() in js/validator.js.
var MINI_SCHEMA_WITH_FORMATS = {
    '$schema': 'http://json-schema.org/draft-07/schema#',
    title: 'MiniTestSchemaWithFormats',
    type: 'object',
    properties: {
        website: { type: 'string', format: 'uri' },
        contact: { type: 'string', format: 'email' },
        published: { type: 'string', format: 'date-time' }
    }
};

// Bildet den AccountingService-Use-Case des Bugs nach (doc/TODO.md,
// "Bug (lokalisiert)"): Haupt-Schema mit required: [name, url] - das
// Erweiterungsfeld enthält aber ausschließlich unbekannte Properties
// (priceRange, geo), keine der beiden Pflichtfelder.
var MINI_SCHEMA_WITH_REQUIRED = {
    '$schema': 'http://json-schema.org/draft-07/schema#',
    title: 'MiniTestSchemaWithRequired',
    type: 'object',
    required: ['name', 'url'],
    properties: {
        name: { type: 'string' },
        url: { type: 'string' }
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

        test('unbekanntes Schema mit gültiger URI-Syntax ist ein Fehler ("htto://...")', function () {
            // new URL() prüft nur allgemeine URI-Syntax und würde einen
            // Tippfehler wie "htto://" sonst fälschlich als gültig durchlassen.
            expect(validator.validateUrl('htto://www.dddd.de').status).toBe('error');
        });

        test('ähnliches, aber falsches Schema ist ein Fehler ("htxxxs://...")', function () {
            expect(validator.validateUrl('htxxxs://www.example.com/pfad').status).toBe('error');
        });

        // B5-04: "new URL()" akzeptiert IDN-Hostnamen ungeprüft, filter_var()
        // serverseitig (FILTER_VALIDATE_URL) nicht - ohne die Nicht-ASCII-
        // Ablehnung zeigte der Client hier fälschlich Grün, das Speichern
        // wäre serverseitig gescheitert.
        test('IDN-Hostname mit Nicht-ASCII-Zeichen ist ein Fehler ("http://ä.de")', function () {
            expect(validator.validateUrl('http://ä.de').status).toBe('error');
        });
    });

    describe('validateSortOrder()', function () {
        test('leerer Wert wird nicht geprüft', function () {
            expect(validator.validateSortOrder('').status).toBeNull();
        });

        test('positive Ganzzahl ist ok', function () {
            expect(validator.validateSortOrder('50').status).toBe('ok');
        });

        test('negative Ganzzahl ist ok', function () {
            expect(validator.validateSortOrder('-1').status).toBe('ok');
        });

        test('nicht-numerische Eingabe ist eine Warnung, kein Fehler', function () {
            expect(validator.validateSortOrder('abc').status).toBe('warning');
        });

        test('Dezimalzahl ist eine Warnung', function () {
            expect(validator.validateSortOrder('1.5').status).toBe('warning');
        });
    });

    // Ein-/Ausgabe-Paare 1:1 aus PersonsRegistryServiceTest.php
    // (SchemaOrgData_PersonsRegistryService::generateSlugSuggestion()/
    // sanitizeSlugCandidate()) - Parität ist die Grundlage der
    // Slug-Live-Kollisionsprüfung (runPersonSlugValidation()).
    describe('generateSlugSuggestionJs()', function () {
        test('transliteriert Umlaute und schreibt klein', function () {
            expect(validator.generateSlugSuggestionJs('Max Mustermann')).toBe('max-mustermann');
            expect(validator.generateSlugSuggestionJs('Jürgen Müller-Schön')).toBe('juergen-mueller-schoen');
            expect(validator.generateSlugSuggestionJs('Straße Weiß')).toBe('strasse-weiss');
        });

        test('entfernt führende und abschließende Bindestriche', function () {
            expect(validator.generateSlugSuggestionJs('  !A B?  ')).toBe('a-b');
            expect(validator.generateSlugSuggestionJs('   ')).toBe('');
        });
    });

    describe('sanitizeSlugCandidateJs()', function () {
        test('entfernt unzulässige Zeichen und schreibt klein', function () {
            expect(validator.sanitizeSlugCandidateJs('Max Mustermann')).toBe('max-mustermann');
            expect(validator.sanitizeSlugCandidateJs('A/b_c-1!')).toBe('ab_c-1');
        });

        test('wandelt Leerraum in Bindestriche statt ihn zu verkleben', function () {
            expect(validator.sanitizeSlugCandidateJs('MM 2026!')).toBe('mm-2026');
        });

        test('transliteriert Umlaute wie der abgeleitete Weg', function () {
            expect(validator.sanitizeSlugCandidateJs('Müller')).toBe('mueller');
            expect(validator.sanitizeSlugCandidateJs('Jürgen Müller-Schön')).toBe('juergen-mueller-schoen');
        });

        // Das geschützte Leerzeichen ist für PHP kein Leerraum: preg_replace('/\s+/')
        // ohne /u trifft seine beiden UTF-8-Bytes nicht, der Zeichenfilter von
        // SchemaOrgData_PersonsRegistryService::sanitizeSlugCandidate() löscht sie
        // ersatzlos. Die ausgeschriebene ASCII-Leerraumklasse hier hält dagegen,
        // dass String.replace(/\s+/) sie zu einem Bindestrich machen würde.
        test('geschütztes Leerzeichen wird gelöscht, nicht zum Bindestrich', function () {
            expect(validator.sanitizeSlugCandidateJs('max mustermann')).toBe('maxmustermann');
            expect(validator.sanitizeSlugCandidateJs('max mustermann')).toBe('max-mustermann');
        });

        // Spiegelt SchemaOrgData_PersonsRegistryService::sanitizeSlugCandidate():
        // ohne alphanumerisches Zeichen gilt die Kennung als nicht angegeben,
        // sonst schlüge der Live-Fill einen Wert vor, den der Server ablehnt.
        test('reine Trennzeichenfolge gilt als nicht angegeben', function () {
            expect(validator.sanitizeSlugCandidateJs('Иван Петров')).toBe('');
            expect(validator.sanitizeSlugCandidateJs('-_-')).toBe('');
            expect(validator.sanitizeSlugCandidateJs('-max-')).toBe('-max-');
        });
    });

    // Regression zu B5-06: applyMessageParam() spiegelt str_replace() aus
    // Language::getLanguageValue() - global und literal.
    describe('applyMessageParam()', function () {
        test('ersetzt alle Fundstellen von {PARAM1}, nicht nur die erste', function () {
            expect(validator.applyMessageParam('a {PARAM1} b {PARAM1} c', 'X'))
                .toBe('a X b X c');
        });

        test('$&, $` , $\' und $$ im Wert werden literal eingesetzt', function () {
            expect(validator.applyMessageParam('vor {PARAM1} nach', '$&')).toBe('vor $& nach');
            expect(validator.applyMessageParam('vor {PARAM1} nach', '$`')).toBe('vor $` nach');
            expect(validator.applyMessageParam('vor {PARAM1} nach', '$\'')).toBe('vor $\' nach');
            expect(validator.applyMessageParam('vor {PARAM1} nach', '$$')).toBe('vor $$ nach');
        });

        test('ein Text ohne Platzhalter bleibt unverändert', function () {
            expect(validator.applyMessageParam('ohne Platzhalter', 'X')).toBe('ohne Platzhalter');
        });

        test('ein fehlender Wert erscheint als Text, nicht als Trennzeichen', function () {
            expect(validator.applyMessageParam('vor {PARAM1} nach', null)).toBe('vor null nach');
            expect(validator.applyMessageParam('vor {PARAM1} nach', undefined)).toBe('vor undefined nach');
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

        // B5-04: das bisherige Regex (/^[^\s@]+@[^\s@]+\.[^\s@]+$/) ließ
        // aufeinanderfolgende Punkte im Lokalteil durch, FILTER_VALIDATE_EMAIL
        // serverseitig nicht - ohne diese Ablehnung zeigte der Client
        // fälschlich Grün, das Speichern wäre serverseitig gescheitert.
        test('aufeinanderfolgende Punkte im Lokalteil sind ein Fehler ("a..b@c.de")', function () {
            expect(validator.validateEmail('a..b@c.de').status).toBe('error');
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
        test('ISO-8601-Datum wird nicht mehr akzeptiert', function () {
            expect(validator.validateEventDateInput('2026-07-07').status).toBe('error');
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

    describe('isPersonSuggestionCandidate()', function () {
        test('employee mit @type Person ist ein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', { '@type': 'Person', name: 'Julia Weber' })).toBe(true);
        });

        test('founder mit @type Person ist ein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('founder', { '@type': 'Person', name: 'Julia Weber' })).toBe(true);
        });

        test('member mit @type Person ist ein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('member', { '@type': 'Person', name: 'Julia Weber' })).toBe(true);
        });

        test('Array statt Einzelobjekt ist kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', [{ '@type': 'Person', name: 'Julia Weber' }])).toBe(false);
        });

        test('anderer @type-Wert ist kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', { '@type': 'Organization', name: 'Andere GmbH' })).toBe(false);
        });

        test('andere Property-Namen sind kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('foundingPerson', { '@type': 'Person', name: 'Julia Weber' })).toBe(false);
        });

        test('Nicht-Objekt-Werte (String) sind kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', 'Julia Weber')).toBe(false);
        });

        test('Nicht-Objekt-Werte (Zahl) sind kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', 42)).toBe(false);
        });

        test('null ist kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', null)).toBe(false);
        });

        test('undefined ist kein Kandidat', function () {
            expect(validator.isPersonSuggestionCandidate('employee', undefined)).toBe(false);
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

    // B5-01: getAjv() registriert "uri"/"email"/"date-time" jetzt tatsächlich
    // (siehe Kopfkommentar) - vorher war "strict: false" die einzige Wirkung
    // dieser "format"-Schlüssel, checkFormats() ließ jeden Wert durch.
    describe('checkFormats() gegen "format"-Schlüssel (uri/email/date-time, B5-01)', function () {
        test('gültige Werte für uri/email/date-time ergeben keine Formatfehler', function () {
            var errors = validator.checkFormats({
                website: 'https://example.com',
                contact: 'info@example.com',
                published: '2026-07-31T10:00:00Z'
            }, MINI_SCHEMA_WITH_FORMATS);
            expect(errors).toEqual([]);
        });

        test('ungültiger Wert für format "uri" ergibt einen Formatfehler', function () {
            var errors = validator.checkFormats({ website: 'nicht-valide' }, MINI_SCHEMA_WITH_FORMATS);
            expect(errors.length).toBeGreaterThan(0);
        });

        test('ungültiger Wert für format "email" ergibt einen Formatfehler', function () {
            var errors = validator.checkFormats({ contact: 'keine-email' }, MINI_SCHEMA_WITH_FORMATS);
            expect(errors.length).toBeGreaterThan(0);
        });

        test('ungültiger Wert für format "date-time" ergibt einen Formatfehler (deutsches Format statt ISO 8601)', function () {
            var errors = validator.checkFormats({ published: '31.07.2026' }, MINI_SCHEMA_WITH_FORMATS);
            expect(errors.length).toBeGreaterThan(0);
        });
    });

    /***************************************************************
    *
    * Regressionstest: checkFormats() ruft bei jedem Aufruf ajv.compile()
    * auf derselben, modul-weit wiederverwendeten AJV-Instanz auf. Trägt
    * das Schema eine "$id" (wie jede reale schemas/*.json-Datei), warf
    * ein zweiter compile()-Aufruf für dieselbe $id bislang AJVs interne
    * Kollisionsausnahme ("schema with key or id ... already exists") -
    * ausgelöst durch jedes weitere blur-Event auf einem bereits einmal
    * validierten Erweiterungsfeld-Textarea.
    *
    ***************************************************************/
    describe('checkFormats() - wiederholte Aufrufe für ein Schema mit "$id"', function () {
        var MINI_SCHEMA_WITH_ID = Object.assign({ '$id': 'https://schema.org/MiniTestSchema' }, MINI_SCHEMA);

        test('zweiter Aufruf für dasselbe Schema wirft nicht und liefert weiterhin ein korrektes Ergebnis', function () {
            expect(validator.checkFormats({ hasMap: 'https://example.com' }, MINI_SCHEMA_WITH_ID)).toEqual([]);

            expect(function () {
                var errors = validator.checkFormats({ count: 'keine-zahl' }, MINI_SCHEMA_WITH_ID);
                expect(errors.length).toBeGreaterThan(0);
            }).not.toThrow();
        });

        test('dritter und vierter Aufruf werfen ebenfalls nicht', function () {
            expect(function () {
                validator.checkFormats({ hasMap: 'https://example.com' }, MINI_SCHEMA_WITH_ID);
                validator.checkFormats({ count: 'keine-zahl' }, MINI_SCHEMA_WITH_ID);
                validator.checkFormats({ hasMap: 'https://example.com' }, MINI_SCHEMA_WITH_ID);
                validator.checkFormats({ count: 'keine-zahl' }, MINI_SCHEMA_WITH_ID);
            }).not.toThrow();
        });

        test('validateExtensionField(): zweiter Aufruf für dasselbe Schema wirft nicht (Zwei-Blur-Szenario)', function () {
            expect(function () {
                validator.validateExtensionField('{"hasMap": "https://example.com"}', MINI_SCHEMA_WITH_ID);
                var result = validator.validateExtensionField('{"count": "keine-zahl"}', MINI_SCHEMA_WITH_ID);
                expect(result.valid).toBe(false);
                expect(result.formatErrors.length).toBeGreaterThan(0);
            }).not.toThrow();
        });
    });

    /***************************************************************
    *
    * Regressionstest für den in doc/TODO.md dokumentierten Bug:
    * checkFormats() kompilierte bislang das volle Type-Schema inkl.
    * required-Keyword und meldete dadurch Pflichtfeld-Fehler des
    * HAUPT-Schemas ("must have required property 'name'/'url'")
    * unter einem Erweiterungsfeld, das nur unbekannte Zusatz-
    * Properties enthält. Unbekannte Properties bleiben weiterhin als
    * Warnung erhalten (checkUnknownProperties ist unabhängig).
    *
    ***************************************************************/
    describe('checkFormats()/validateExtensionField() - required-Keyword des Haupt-Schemas (doc/TODO.md)', function () {
        test('checkFormats(): keine required-Fehler bei Erweiterungsfeld ohne Pflichtfelder', function () {
            var errors = validator.checkFormats({ priceRange: '$$', geo: {} }, MINI_SCHEMA_WITH_REQUIRED);
            var requiredErrors = errors.filter(function (error) {
                return error.keyword === 'required';
            });

            expect(requiredErrors).toEqual([]);
        });

        test('validateExtensionField(): gültig trotz fehlender Pflichtfelder des Haupt-Schemas, unbekannte Properties weiterhin als Warnung', function () {
            var result = validator.validateExtensionField('{"priceRange": "$$", "geo": {}}', MINI_SCHEMA_WITH_REQUIRED);

            expect(result.valid).toBe(true);
            expect(result.formatErrors).toEqual([]);
            expect(result.unknownProperties.sort()).toEqual(['geo', 'priceRange']);
        });
    });

    describe('showExtensionFeedback() - DOM-XSS-Schutz', function () {
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
