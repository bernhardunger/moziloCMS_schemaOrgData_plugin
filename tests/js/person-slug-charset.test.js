'use strict';

var loadPluginScripts = require('./helpers/load-plugin-scripts');

// Das geschuetzte Leerzeichen wird konstruiert statt literal geschrieben:
// ein literales U+00A0 im Quelltext waere von einem gewoehnlichen
// Leerzeichen beim Lesen nicht zu unterscheiden.
var NBSP = String.fromCharCode(0x00a0);

/**
 * Prueft die Zeichenregel beider Slug-Wege (generateSlugSuggestionJs(),
 * sanitizeSlugCandidateJs()) unmittelbar ueber die oeffentliche API
 * window.schemaOrgDataValidator, in der beide Funktionen exportiert sind -
 * ein DOM-Umweg ueber den Live-Fill ist dafuer nicht noetig. Die
 * bestehenden Suiten person-slug-live-fill.test.js und
 * person-slug-widget.test.js decken Verdrahtung und Kollisionspruefung ab,
 * keine von beiden aber die Zeichenregel selbst; genau diese Luecke liess
 * die JS-Seite gegenueber der PHP-Gegenstelle driften.
 *
 * Die Fallliste ist zeichengleich mit PersonsRegistryServiceTest::SLUG_FAELLE
 * auf der PHP-Testebene - beide Seiten muessen fuer denselben Eingabetext
 * denselben Slug liefern.
 *
 * Je Fall: [Eingabe, Erwartung, Bezeichnung im Testnamen].
 */
var SLUG_FAELLE = [
    ['Max Mustermann', 'max-mustermann', '"Max Mustermann"'],
    ['Max_Mustermann', 'max_mustermann', '"Max_Mustermann" (Unterstrich bleibt)'],
    ['Dr.Max', 'dr-max', '"Dr.Max" (Punkt trennt)'],
    ['-Max-', 'max', '"-Max-" (Rand-Trim)'],
    ['Max--Mustermann', 'max-mustermann', '"Max--Mustermann" (Trennzeichenfolge)'],
    ['A/b_c-1!', 'a-b_c-1', '"A/b_c-1!"'],
    // Das geschuetzte Leerzeichen faellt wie jedes andere unzulaessige
    // Zeichen in die Klasse [^a-z0-9_] und trennt deshalb, statt die
    // Woerter zu verkleben.
    ['Max' + NBSP + 'Mustermann', 'max-mustermann', '"Max<U+00A0>Mustermann" (geschuetztes Leerzeichen)'],
    ['Jürgen Müller-Schön', 'juergen-mueller-schoen', '"Juergen Mueller-Schoen" (Transliteration)'],
    ['Straße Weiß', 'strasse-weiss', '"Strasse Weiss" (Eszett)'],
    ['Müller', 'mueller', '"Mueller"'],
    ['Ärztin', 'aerztin', '"Aerztin" (Grossumlaut am Wortanfang)'],
    ['  !A B?  ', 'a-b', '"  !A B?  " (Rand plus Sonderzeichen)'],
    ['Иван Петров', '', 'rein kyrillischer Name'],
    ['Иван Max', 'max', 'Mischschrift kyrillisch/lateinisch'],
    ['-', '', '"-"'],
    ['--', '', '"--"'],
    ['_', '', '"_"'],
    ['-_-', '', '"-_-"'],
    ['   ', '', 'nur Leerraum']
];

describe('Personen-Registry - Slug-Zeichenregel (normalizeSlugJs)', function () {
    var validator;

    beforeEach(function () {
        document.body.innerHTML = '';
        validator = loadPluginScripts.loadValidator();
    });

    SLUG_FAELLE.forEach(function (fall) {
        var input = fall[0];
        var expected = fall[1];
        var label = fall[2];

        it('generateSlugSuggestionJs: ' + label + ' ergibt ' + JSON.stringify(expected), function () {
            expect(validator.generateSlugSuggestionJs(input)).toBe(expected);
        });

        it('sanitizeSlugCandidateJs: ' + label + ' ergibt ' + JSON.stringify(expected), function () {
            expect(validator.sanitizeSlugCandidateJs(input)).toBe(expected);
        });
    });

    it('beide Wege liefern fuer denselben Eingabetext denselben Slug', function () {
        SLUG_FAELLE.forEach(function (fall) {
            expect(validator.sanitizeSlugCandidateJs(fall[0])).toBe(validator.generateSlugSuggestionJs(fall[0]));
        });
    });

    // normalizeSlugJs() ist bewusst nicht exportiert - die Tests fahren die
    // oeffentlichen Wege, nicht die Interna.
    it('normalizeSlugJs bleibt modulintern', function () {
        expect(validator.normalizeSlugJs).toBeUndefined();
    });

    it('behandelt fehlende und nicht-string Eingaben wie einen Leerstring', function () {
        expect(validator.generateSlugSuggestionJs(undefined)).toBe('');
        expect(validator.sanitizeSlugCandidateJs(null)).toBe('');
    });
});
