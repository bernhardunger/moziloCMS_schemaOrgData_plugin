'use strict';

var fs = require('fs');
var path = require('path');

var PLUGIN_JS_DIR = path.join(__dirname, '..', '..', '..', 'plugins', 'schemaOrgData', 'js');

/**
 * Lädt eine der Plugin-JS-Dateien (validator.js, ajv.min.js,
 * debug-widget.js) in den
 * jsdom-Fenster-Kontext. Der Function-Konstruktor statt eval() ist hier
 * bewusst gewählt: eval() innerhalb dieser CommonJS-Testdatei hätte
 * Zugriff auf die lokalen "module"/"exports"-Variablen des Jest-Modul-
 * Wrappers - der UMD-Wrapper von ajv.min.js prüft genau darauf
 * ("typeof exports === 'object'") und würde sein Ergebnis fälschlich
 * dorthin statt nach window.ajv7 exportieren. Der Function-Konstruktor
 * hat keinen Zugriff auf den umgebenden Modul-Scope, nur auf den
 * globalen (jsdom-)Scope, in dem "window" bereits existiert.
 *
 * @param {string} filename
 */
function loadScriptIntoWindow(filename) {
    var code = fs.readFileSync(path.join(PLUGIN_JS_DIR, filename), 'utf8');
    new Function(code)();
}

function loadAjv() {
    loadScriptIntoWindow('ajv.min.js');
}

function loadValidator() {
    loadScriptIntoWindow('validator.js');
    return window.schemaOrgDataValidator;
}

function loadDebugWidget() {
    loadScriptIntoWindow('debug-widget.js');
    return window.schemaOrgDataDebugWidget;
}

module.exports = {
    loadAjv: loadAjv,
    loadValidator: loadValidator,
    loadDebugWidget: loadDebugWidget
};
