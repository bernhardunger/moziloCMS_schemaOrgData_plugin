'use strict';

/**
 * Spiegelt htmlspecialchars($json, ENT_QUOTES, 'UTF-8') aus
 * SchemaOrgData_AdminController::renderAdminPage(). Das kaufmännische
 * Und wird zuerst ersetzt, sonst würde es die Entities der folgenden
 * Ersetzungen ein zweites Mal kodieren.
 *
 * @param {string} value
 * @returns {string}
 */
function htmlspecialcharsQuotes(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Baut den Formular-Container, an dem getMessages() (validator.js) die
 * lokalisierten Texte liest, in exakt der Form, die
 * SchemaOrgData_AdminController::renderAdminPage() ausgibt: doppelt
 * gequotetes data-messages-Attribut mit entity-kodiertem JSON.
 * JSON.stringify() entspricht dabei json_encode() mit
 * JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE - beide lassen
 * Schrägstriche und Nicht-ASCII-Zeichen unangetastet.
 *
 * @param {object} messages
 * @param {string} innerHtml Markup innerhalb des Containers
 * @returns {string}
 */
function buildAdminContainer(messages, innerHtml) {
    return '<div class="schemaOrgData-admin" data-messages="'
        + htmlspecialcharsQuotes(JSON.stringify(messages))
        + '">' + innerHtml + '</div>';
}

module.exports = { buildAdminContainer: buildAdminContainer };
