'use strict';

/**
 * Testet die Quelle der lokalisierten Texte (getMessages() in
 * plugins/schemaOrgData/js/validator.js): das Attribut data-messages am
 * Formular-Container .schemaOrgData-admin, den
 * SchemaOrgData_AdminController::renderAdminPage() ausgibt.
 *
 * getMessages() ist bewusst nicht Teil der öffentlichen API und wird
 * deshalb über validatePostalCode() geprüft - die Funktion gibt
 * getMessages().postalCode unverändert als message zurück und ist damit
 * der kürzeste Weg an das gelesene Objekt.
 */

var loadPluginScripts = require('./helpers/load-plugin-scripts');
var adminContainer = require('./helpers/admin-container');

/**
 * Erzeugt einen Fehlerfall von validatePostalCode() und liefert dessen
 * message - also den Wert, den getMessages() unter postalCode geliefert
 * hat (null, wenn dort nichts stand).
 *
 * @param {object} validator
 * @returns {string|null}
 */
function postalCodeMessage(validator) {
    return validator.validatePostalCode('123', 'DE').message;
}

describe('Quelle der lokalisierten Texte (getMessages)', function () {
    beforeEach(function () {
        document.body.innerHTML = '';
    });

    test('liest die Texte aus dem data-messages-Attribut des Containers', function () {
        document.body.innerHTML = adminContainer.buildAdminContainer(
            { postalCode: 'PLZ_FEHLER' },
            '<form></form>'
        );

        expect(postalCodeMessage(loadPluginScripts.loadValidator())).toBe('PLZ_FEHLER');
    });

    test('liefert ohne Container ein leeres Objekt statt einer Ausnahme', function () {
        document.body.innerHTML = '<form></form>';

        var validator = loadPluginScripts.loadValidator();

        expect(function () { postalCodeMessage(validator); }).not.toThrow();
        expect(postalCodeMessage(validator)).toBeNull();
    });

    test('liefert bei einem Container ohne data-messages ein leeres Objekt', function () {
        document.body.innerHTML = '<div class="schemaOrgData-admin"><form></form></div>';

        expect(postalCodeMessage(loadPluginScripts.loadValidator())).toBeNull();
    });

    test('liefert bei ungültigem JSON ein leeres Objekt statt einer Ausnahme', function () {
        document.body.innerHTML = '<div class="schemaOrgData-admin" data-messages="{kaputt">'
            + '<form></form></div>';

        var validator = loadPluginScripts.loadValidator();

        expect(function () { postalCodeMessage(validator); }).not.toThrow();
        expect(postalCodeMessage(validator)).toBeNull();
    });

    test('parst einmalig: eine spätere Änderung des Attributs bleibt unbeachtet', function () {
        document.body.innerHTML = adminContainer.buildAdminContainer(
            { postalCode: 'ERSTER_TEXT' },
            '<form></form>'
        );

        var validator = loadPluginScripts.loadValidator();
        expect(postalCodeMessage(validator)).toBe('ERSTER_TEXT');

        document.querySelector('.schemaOrgData-admin')
            .setAttribute('data-messages', JSON.stringify({ postalCode: 'ZWEITER_TEXT' }));

        expect(postalCodeMessage(validator)).toBe('ERSTER_TEXT');
    });
});
