/**
 * schemaOrgData - validator.js
 *
 * Client-seitige Validierung des Erweiterungsfelds (JSON-Textarea)
 * gegen das jeweils aktive JSON-Schema (schemas/*.json).
 *
 * Nutzt AJV (js/ajv.min.js, Draft-07, globaler Export "ajv7").
 *
 * Ablauf (siehe README.md, Abschnitt "Erweiterungsfeld"):
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

    /** Nicht-ASCII-Zeichen. Ohne g-Flag und damit zustandslos teilbar. */
    var NON_ASCII_PATTERN = /[^\x00-\x7F]/;

    /**
     * Deutsches Datumsformat, optional mit Uhrzeit. Wortgleich zu
     * SchemaOrgData_Validator::SHARED_PATTERN_DATE_DE (PHP); ein Waechtertest
     * haelt beide Fassungen gegeneinander. Ohne g-Flag und damit
     * zustandslos zwischen den Aufrufern teilbar.
     */
    var DATE_DE_PATTERN = /^(\d{2})\.(\d{2})\.(\d{4})(?: ([01][0-9]|2[0-3]):([0-5][0-9]))?$/;

    /**
     * Zeitformat "HH:MM". Wortgleich zu
     * SchemaOrgData_Validator::SHARED_PATTERN_TIME (PHP), ebenfalls bewacht.
     * Ohne g-Flag und damit zustandslos teilbar.
     */
    var TIME_PATTERN = /^[0-9]{2}:[0-9]{2}$/;

    /**
     * Klassenstamm der Feld-Rueckmeldung. Wortgleich zu
     * SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK (PHP), von
     * einem Waechtertest dagegen gehalten.
     */
    var FEEDBACK_CLASS = 'schemaOrgData-feedback schemaOrgData-feedback--';

    /**
     * Prüft eine URL wie SchemaOrgData_Validator::validateUrl() (PHP):
     * FILTER_VALIDATE_URL-Kern (hier über "new URL()" nachgebildet) plus
     * "^https?://"-Vorfilter. Zusätzlich werden Nicht-ASCII-Zeichen
     * abgelehnt - "new URL()" akzeptiert IDN-Hostnamen ("http://ä.de")
     * ungeprüft, filter_var() nicht. Von getAjv() (Format "uri") und
     * validateUrl() gemeinsam genutzt, damit AJV nie einen Wert grün
     * zeigt, den das serverseitige Speichern ablehnt.
     *
     * @param {*} value
     * @returns {boolean}
     */
    function isValidUrlFormat(value) {
        if (typeof value !== 'string') {
            return false;
        }

        if (!/^https?:\/\//i.test(value) || NON_ASCII_PATTERN.test(value)) {
            return false;
        }

        try {
            new URL(value);
        } catch (e) {
            return false;
        }

        return true;
    }

    /** Lokalteil einer E-Mail-Adresse: Punkt-getrennte, nicht-leere Segmente (keine aufeinanderfolgenden Punkte). */
    var EMAIL_LOCAL_PART_PATTERN = /^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-zA-Z0-9!#$%&'*+/=?^_`{|}~-]+)*$/;
    /** Einzelnes Domain-Label (kein führender/nachgestellter Bindestrich). */
    var EMAIL_DOMAIN_LABEL_PATTERN = /^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/;

    /**
     * Prüft eine E-Mail-Adresse angenähert an FILTER_VALIDATE_EMAIL
     * (SchemaOrgData_Validator::validateEmail(), PHP): lehnt - anders als
     * das bisherige, deutlich lockerere Regex - aufeinanderfolgende Punkte
     * im Lokalteil ("a..b@c.de") und Nicht-ASCII-Zeichen im Lokalteil ab.
     * Von getAjv() (Format "email") und validateEmail() gemeinsam genutzt.
     *
     * @param {*} value
     * @returns {boolean}
     */
    function isValidEmailFormat(value) {
        if (typeof value !== 'string') {
            return false;
        }

        var atIndex = value.lastIndexOf('@');
        if (atIndex <= 0 || atIndex === value.length - 1) {
            return false;
        }

        var local = value.slice(0, atIndex);
        var domain = value.slice(atIndex + 1);

        if (NON_ASCII_PATTERN.test(local) || !EMAIL_LOCAL_PART_PATTERN.test(local)) {
            return false;
        }

        var domainLabels = domain.split('.');
        if (domainLabels.length < 2) {
            return false;
        }

        return domainLabels.every(function (label) {
            return EMAIL_DOMAIN_LABEL_PATTERN.test(label);
        });
    }

    /**
     * Prüft ein ISO-8601-Datum wie SchemaOrgData_Validator::validateIso8601Date()
     * (PHP): reines Datum ("YYYY-MM-DD") oder Datum+Zeit+Zeitzone
     * ("YYYY-MM-DDTHH:MM:SS±HH:MM" bzw. "...Z"), zusätzlich kalendarisch
     * gültig (siehe isValidCalendarDate()). Von getAjv() (Format
     * "date-time") genutzt - das Erweiterungsfeld speichert Datumswerte
     * ausschließlich in diesem Format, nie im deutschen TT.MM.YYYY-Format
     * der Formularfelder (siehe validateEventDateInput()).
     *
     * @param {*} value
     * @returns {boolean}
     */
    function isValidIso8601Format(value) {
        if (typeof value !== 'string') {
            return false;
        }

        var match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:T([01][0-9]|2[0-3]):([0-5][0-9]):[0-5][0-9](?:Z|[+-]\d{2}:\d{2}))?$/);
        if (!match) {
            return false;
        }

        return isValidCalendarDate(parseInt(match[1], 10), parseInt(match[2], 10), parseInt(match[3], 10));
    }

    /**
     * Erstellt bzw. liefert die AJV-Instanz. Registriert die drei im
     * Projekt genutzten "format"-Schlüssel ("uri", "email", "date-time",
     * siehe schemas/*.json) über dieselben Prädikate wie validateUrl()/
     * validateEmail() - AJV bringt ohne "ajv-formats" (bewusst nicht
     * eingebunden, siehe README.md) keine Formatprüfung mit, das
     * "format"-Keyword der Schemas wäre sonst wirkungslos.
     *
     * @returns {object|null} AJV-Instanz oder null, falls ajv.min.js
     *                         nicht geladen wurde
     */
    function getAjv() {
        if (ajvInstance === null && typeof window.ajv7 !== 'undefined') {
            var Ajv = window.ajv7.default || window.ajv7;
            ajvInstance = new Ajv({ allErrors: true, strict: false });
            ajvInstance.addFormat('uri', isValidUrlFormat);
            ajvInstance.addFormat('email', isValidEmailFormat);
            ajvInstance.addFormat('date-time', isValidIso8601Format);
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
            return { valid: false, data: null, error: getMessages().jsonInvalid || e.message };
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
     * Prüft, ob eine unbekannte Erweiterungsfeld-Property ein
     * Personen-Literal ist, das SchemaOrgData_PersonSuggestionService
     * server-seitig als Übernahme-Vorschlag anbietet (siehe README.md,
     * Abschnitt "Erweiterungsfeld"). Muss mit der PHP-Erkennung in
     * SchemaOrgData_PersonSuggestionService::detectSuggestions() in Sync
     * bleiben (Property-Whitelist + "@type": "Person" als einzelnes Objekt).
     *
     * Prüft ausschließlich die Form, nicht den Kontext. Ob an der
     * Fundstelle überhaupt ein Vorschlag möglich ist, entscheidet der
     * Aufrufer anhand des Attributs data-person-suggestions am
     * Erweiterungsfeld (siehe showExtensionFeedback() und
     * initExtensionFieldValidation()) - ohne dieses Gatter erschiene der
     * Info-Hinweis in jedem Erweiterungsfeld, auch dort, wo die
     * Serverseite keinen Vorschlag anbietet.
     *
     * @param {string} property
     * @param {*} value
     * @returns {boolean}
     */
    function isPersonSuggestionCandidate(property, value) {
        // Muss mit SchemaOrgData_OrgRelationsService::ROLES (PHP) in Sync
        // bleiben - dort keine JS-seitige Quelle verfügbar, daher hier
        // dupliziert.
        var PERSON_SUGGESTION_PROPERTIES = ['founder', 'employee', 'member'];

        if (PERSON_SUGGESTION_PROPERTIES.indexOf(property) === -1) {
            return false;
        }

        return !!value && typeof value === 'object' && !Array.isArray(value) && value['@type'] === 'Person';
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

        // Top-Level-required des Haupt-Schemas wird hier bewusst entfernt
        // (Kopie, Original bleibt unverändert): das Erweiterungsfeld enthält
        // per Definition nur Zusatz-Properties, keine Pflichtfelder des
        // Haupt-Schemas - sonst meldet AJV z. B. "must have required
        // property 'name'/'url'" unter einem Feld, das gar kein name/url
        // enthalten soll. Required-Constraints INNERHALB verschachtelter
        // Properties (z. B. address) bleiben unangetastet und validieren
        // weiterhin echten Inhalt.
        var schemaForExtensionField = Object.assign({}, schema);
        delete schemaForExtensionField.required;

        // schemaForExtensionField trägt dieselbe "$id" wie das Original-Schema
        // (alle schemas/*.json: "$id": "https://schema.org/<Type>") - ein
        // erneuter compile()-Aufruf für dieselbe $id (z. B. beim zweiten Blur
        // desselben Erweiterungsfelds) würde AJVs interne Kollisionsprüfung
        // auslösen ("schema with key or id ... already exists"). removeSchema()
        // ist bei einer noch nicht registrierten $id ein No-op.
        ajv.removeSchema(schemaForExtensionField.$id);
        var validate = ajv.compile(schemaForExtensionField);

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

    // Gemerktes Ergebnis von getMessages(). null bedeutet "noch nicht
    // gelesen" - ein leeres Objekt ist ein gültiges Ergebnis und darf
    // nicht als "noch nicht gelesen" missverstanden werden.
    var cachedMessages = null;

    /**
     * Liefert die lokalisierten Texte für die Feldvalidierung
     * (admin_language_{lang}.txt). Quelle ist das Attribut
     * data-messages am Formular-Container .schemaOrgData-admin, das
     * SchemaOrgData_AdminController::renderAdminPage() ausgibt.
     * Attributwert und Parse-Ergebnis ändern sich zur Laufzeit nicht,
     * die Aufrufer sitzen aber in Validierungs-Handlern, die bei jedem
     * Tastendruck laufen - deshalb wird einmalig gelesen und geparst
     * und danach das gemerkte Objekt geliefert. Fallback ist ein leeres
     * Objekt, damit das Skript auch ohne Container, ohne Attribut oder
     * mit defektem JSON funktionsfähig bleibt.
     *
     * @returns {object}
     */
    function getMessages() {
        if (cachedMessages !== null) {
            return cachedMessages;
        }

        cachedMessages = {};

        var container = document.querySelector('.schemaOrgData-admin');
        var raw = container ? container.getAttribute('data-messages') : null;

        if (raw) {
            try {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    cachedMessages = parsed;
                }
            } catch (e) {
                // Defektes JSON darf die Validierung nicht abbrechen -
                // es bleibt beim leeren Fallback-Objekt.
            }
        }

        return cachedMessages;
    }

    /**
     * Setzt den Platzhalter {PARAM1} eines lokalisierten Textes durch
     * einen Wert. Spiegelt Language::getLanguageValue() des moziloCMS-
     * Kerns, das str_replace() verwendet: alle Fundstellen werden
     * ersetzt, und der eingesetzte Wert gilt als Literal.
     * String.replace() mit einem String-Muster leistet beides nicht - es
     * trifft nur die erste Fundstelle und liest $&, $`, $' und $$ im
     * zweiten Parameter als Ersetzungsmuster. Eingesetzt werden hier
     * durchweg frei gewählte Nutzerwerte (Personenname, Bereichslabel,
     * Property-Name), in denen solche Zeichenfolgen vorkommen können.
     *
     * String(value) hält die Ausgabe für einen fehlenden Wert bei der
     * bisherigen Form ("null"/"undefined"): join() setzt ein undefined
     * andernfalls als Standardtrenner "," ein.
     *
     * @param {string} template
     * @param {string} value
     * @returns {string}
     */
    function applyMessageParam(template, value) {
        return template.split('{PARAM1}').join(String(value));
    }

    /**
     * Validiert eine Postleitzahl. Nur für countryCode === "DE"
     * relevant (siehe index.php, validatePostalCode()).
     *
     * @param {string} value
     * @param {string} countryCode
     * @returns {{status: string|null, message: string|null}}
     */
    function validatePostalCode(value, countryCode) {
        if (countryCode !== 'DE' || value.trim() === '') {
            return { status: null, message: null };
        }

        if (/^[0-9]{5}$/.test(value)) {
            return { status: 'ok', message: null };
        }

        return { status: 'error', message: getMessages().postalCode || null };
    }

    /**
     * Validiert eine Telefonnummer (E.164, alle Länder). Siehe
     * index.php, validateTelephone().
     *
     * @param {string} value
     * @param {string} countryCode
     * @returns {{status: string|null, message: string|null}}
     */
    function validateTelephone(value, countryCode) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        var normalized = value.replace(/[^0-9+]/g, '');

        if (/^(\+|00)[1-9][0-9]{6,14}$/.test(normalized)) {
            return { status: 'ok', message: null };
        }

        return { status: 'error', message: getMessages().telephone || null };
    }

    /**
     * Validiert eine URL. "http://" ergibt eine Warnung (HTTPS
     * empfohlen), "https://" ist OK, eine ungültige URL ist ein
     * Fehler (siehe SchemaOrgData_Validator::validateUrl()).
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateUrl(value) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        if (!isValidUrlFormat(value)) {
            return { status: 'error', message: getMessages().urlInvalid || null };
        }

        if (value.indexOf('http://') === 0) {
            return { status: 'warning', message: getMessages().urlHttpWarning || null };
        }

        return { status: 'ok', message: null };
    }

    /**
     * Validiert das Sortierung-Feld der Personen-Registry (sortOrder).
     * Rein informativ - eine ungültige Eingabe blockiert das Speichern
     * nicht, sie wird serverseitig auf den Standardwert 100
     * zurückgesetzt (siehe SchemaOrgData_PersonsRegistryService::
     * sanitizePersonData()). Das Regex-Muster muss exakt dessen
     * PHP-Gegenstück spiegeln, damit die Live-Warnung nie einen Wert
     * ablehnt, den der Server als gültig übernimmt.
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateSortOrder(value) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        if (/^-?[0-9]+$/.test(value.trim())) {
            return { status: 'ok', message: null };
        }

        return { status: 'warning', message: getMessages().sortOrderInvalid || null };
    }

    /**
     * Umlaut-Transliteration beider Slug-Wege - Entsprechung von
     * SchemaOrgData_PersonsRegistryService::transliterateSlugInput().
     * Läuft vor der Kleinschreibung, weil die PHP-Gegenstelle es tun
     * muss (dortiges strtolower() lässt "Ä"/"Ö"/"Ü" unangetastet) und
     * beide Seiten sonst auseinanderliefen.
     *
     * @param {string} value
     * @returns {string}
     */
    function transliterateSlugInputJs(value) {
        var umlautMap = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'Ä': 'Ae', 'Ö': 'Oe', 'Ü': 'Ue', 'ß': 'ss' };
        return value.replace(/[äöüÄÖÜß]/g, function (match) { return umlautMap[match]; });
    }

    /**
     * Schreibt ausschließlich die lateinischen Großbuchstaben klein.
     * Bewusst nicht String.toLowerCase(): das ist unicode-bewusst und
     * würde Zeichen kleinschreiben, die das PHP-seitige strtolower()
     * unangetastet lässt - dieselbe Eingabe ergäbe dann client- und
     * serverseitig verschiedene Slugs.
     *
     * @param {string} value
     * @returns {string}
     */
    function toAsciiLowerCaseJs(value) {
        return value.replace(/[A-Z]/g, function (match) { return match.toLowerCase(); });
    }

    /**
     * Bildet die Zeichenregel eines Slugs ab und ist deren einzige
     * Stelle - Entsprechung von
     * SchemaOrgData_PersonsRegistryService::normalizeSlug(). Zulässig
     * sind lateinische Buchstaben, Ziffern, Bindestrich und
     * Unterstrich, alles kleingeschrieben; jede zusammenhängende Folge
     * unzulässiger Zeichen wird zu genau einem Bindestrich, führende
     * und abschließende Bindestriche entfallen.
     *
     * Beide Slug-Wege - generateSlugSuggestionJs() aus dem Namen und
     * sanitizeSlugCandidateJs() aus einer Eingabe - teilen diese
     * Funktion und liefern deshalb für denselben Eingabetext denselben
     * Slug. Eine je Weg gespiegelte Regel driftet auseinander, sobald
     * einer von beiden angefasst wird.
     *
     * Der Zeichenvorrat ist bewusst auf ASCII beschränkt - der Slug ist
     * zugleich das @id-Fragment der Person und nach Erstanlage
     * unveränderlich. Die Transliteration läuft vor der
     * Kleinschreibung, Begründung siehe transliterateSlugInputJs().
     *
     * String.trim() bleibt hier bewusst stehen, obwohl es
     * unicode-bewusster ist als das PHP-seitige trim(): ein am Rand
     * stehendes geschütztes Leerzeichen entfernt JS sofort, PHP
     * anschließend über den Zeichenfilter - das Ergebnis ist beidseitig
     * dasselbe.
     *
     * Die abschließende Alphanumerik-Prüfung tritt neben den Rand-Trim,
     * nicht an seine Stelle: Der Trim allein ließe "-_-" durch, weil
     * der Unterstrich zum erlaubten Zeichenvorrat gehört und der Trim
     * nur Bindestriche abträgt. Ein solcher Slug erzeugte dauerhaft das
     * @id-Fragment "#person-_"; die PHP-Gegenstelle prüft deshalb
     * dasselbe, damit der Live-Vorschlag nicht etwas anderes anzeigt,
     * als der Server speichert.
     *
     * @param {string} value
     * @returns {string}
     */
    function normalizeSlugJs(value) {
        var result = transliterateSlugInputJs(String(value || '').trim());
        result = toAsciiLowerCaseJs(result);
        result = result.replace(/[^a-z0-9_]+/g, '-');
        result = result.replace(/^-+|-+$/g, '');

        return /[a-z0-9]/.test(result) ? result : '';
    }

    /**
     * Bereinigt einen vom Nutzer eingegebenen Personen-Registry-Slug -
     * client-seitige Entsprechung von
     * SchemaOrgData_PersonsRegistryService::sanitizeSlugCandidate(),
     * siehe README.md, Abschnitt "Personen-Registry". Die Zeichenregel
     * steht in normalizeSlugJs().
     *
     * @param {string} value
     * @returns {string}
     */
    function sanitizeSlugCandidateJs(value) {
        return normalizeSlugJs(value);
    }

    /**
     * Leitet aus einem Personennamen einen Slug-Vorschlag ab -
     * client-seitige Entsprechung von
     * SchemaOrgData_PersonsRegistryService::generateSlugSuggestion(),
     * siehe README.md, Abschnitt "Personen-Registry". Die Zeichenregel
     * steht in normalizeSlugJs(); beide Wege liefern für denselben
     * Eingabetext denselben Slug.
     *
     * @param {string} name
     * @returns {string}
     */
    function generateSlugSuggestionJs(name) {
        return normalizeSlugJs(name);
    }

    /**
     * Validiert das Slug- bzw. Namensfeld des "Neue Person"-Formulars
     * (data-pair-Verknüpfung analog runGeoValidation()) gegen die
     * bereits im DOM vorhandene Personenliste ([data-slug]-Zeilen, siehe
     * SchemaOrgData_PersonsAdminRenderer::renderListView()) - rein
     * client-seitiger Vorab-Hinweis, ersetzt nicht die serverseitige
     * Prüfung in SchemaOrgData_PersonsRegistryService::createPerson().
     * Ist das Slug-Feld leer, wird der aus dem Namensfeld abgeleitete
     * Vorschlag geprüft (SchemaOrgData_FormRenderer::renderTextWidget()
     * zeigt diesen Vorschlag ohnehin nur als Placeholder an). Das
     * Feedback erscheint stets am Slug-Feld, unabhängig davon, welches
     * der beiden verknüpften Felder die Validierung ausgelöst hat.
     *
     * @param {HTMLElement} input das gerade geänderte Slug- oder Namensfeld
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback()
     */
    function runPersonSlugValidation(input, onlyClearErrors) {
        var isSlugField = /_slug$/.test(input.id);
        var pairInput = document.getElementById(input.getAttribute('data-pair'));
        var slugInput = isSlugField ? input : pairInput;
        var nameInput = isSlugField ? pairInput : input;

        if (!slugInput) {
            return;
        }

        var slugValue = slugInput.value.trim();
        var nameValue = nameInput ? nameInput.value.trim() : '';

        var effectiveSlug = '';
        if (slugValue !== '') {
            effectiveSlug = sanitizeSlugCandidateJs(slugValue);
        } else if (nameValue !== '') {
            effectiveSlug = generateSlugSuggestionJs(nameValue);
        }

        if (effectiveSlug === '') {
            showFieldFeedback(slugInput, slugInput.id + '_feedback', { status: null, message: null }, onlyClearErrors);
            return;
        }

        var rows = document.querySelectorAll('[data-slug]');
        var matchedRow = null;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].getAttribute('data-slug') === effectiveSlug) {
                matchedRow = rows[i];
                break;
            }
        }

        if (!matchedRow) {
            showFieldFeedback(slugInput, slugInput.id + '_feedback', { status: null, message: null }, onlyClearErrors);
            return;
        }

        var message = applyMessageParam(getMessages().personSlugCollision || '', matchedRow.getAttribute('data-slug-name'));
        showFieldFeedback(slugInput, slugInput.id + '_feedback', { status: 'error', message: message }, onlyClearErrors);
    }

    /**
     * Validiert eine E-Mail-Adresse (siehe SchemaOrgData_Validator::validateEmail()).
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateEmail(value) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        if (isValidEmailFormat(value)) {
            return { status: 'ok', message: null };
        }

        return { status: 'error', message: getMessages().emailInvalid || null };
    }

    /**
     * Prüft, ob ein Kalenderdatum tatsächlich existiert (JavaScript hat kein
     * PHP-checkdate()-Äquivalent - ein "Date"-Konstruktor läuft bei
     * ungültigen Tagen/Monaten stillschweigend in den Folgemonat über, z. B.
     * 31.02. -> 03.03.). Rundreise über den Date-Konstruktor und Vergleich
     * der zerlegten Werte mit den Eingabewerten deckt das auf.
     *
     * @param {number} year
     * @param {number} month 1-12
     * @param {number} day
     * @returns {boolean}
     */
    function isValidCalendarDate(year, month, day) {
        var date = new Date(year, month - 1, day);
        return date.getFullYear() === year && (date.getMonth() + 1) === month && date.getDate() === day;
    }

    /**
     * Validiert eine Datumseingabe für Event.startDate/endDate: akzeptiert
     * ausschließlich das deutsche Format "TT.MM.YYYY", optional mit Uhrzeit
     * ("TT.MM.YYYY HH:MM"). Spiegelt SchemaOrgData_Validator::validateEventDateInput()
     * (PHP) - beide Implementierungen müssen bei einer Formatänderung
     * gemeinsam angepasst werden.
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateEventDateInput(value) {
        var trimmed = (value || '').trim();

        if (trimmed === '') {
            return { status: null, message: null };
        }

        var deMatch = trimmed.match(DATE_DE_PATTERN);
        if (deMatch && isValidCalendarDate(parseInt(deMatch[3], 10), parseInt(deMatch[2], 10), parseInt(deMatch[1], 10))) {
            return { status: 'ok', message: null };
        }

        return { status: 'error', message: getMessages().dateInvalid || null };
    }

    /**
     * Wandelt eine bereits als gültig bestätigte Datumseingabe
     * (validateEventDateInput(), also ausschließlich deutsches Format) in
     * ein vergleichbares "Date"-Objekt um - reine Hilfsfunktion für
     * checkDateRange(), kein eigenständiger Validierungsschritt. Ein reines
     * Datum ohne Uhrzeit wird als lokale Mitternacht interpretiert
     * (symmetrisch zu normalizeEventDateInput() in
     * SchemaOrgData_Validator.php); mit Uhrzeit übernimmt der "Date"-
     * Konstruktor die Sommer-/Winterzeit-Auflösung der Browser-Zeitzone
     * automatisch.
     *
     * @param {string} value
     * @returns {Date|null}
     */
    function parseEventDateValue(value) {
        var trimmed = (value || '').trim();

        var deMatch = trimmed.match(DATE_DE_PATTERN);
        if (deMatch) {
            var day = parseInt(deMatch[1], 10);
            var month = parseInt(deMatch[2], 10) - 1;
            var year = parseInt(deMatch[3], 10);
            var hour = deMatch[4] ? parseInt(deMatch[4], 10) : 0;
            var minute = deMatch[5] ? parseInt(deMatch[5], 10) : 0;
            return new Date(year, month, day, hour, minute, 0);
        }

        return null;
    }

    /**
     * Prüft, ob eine bereits als gültig bestätigte Datumseingabe
     * (validateEventDateInput()) in der Vergangenheit liegt (Vergleich
     * gegen die aktuelle Client-Uhrzeit). Ein reines Datum ohne Uhrzeit
     * wird dabei wie in parseEventDateValue() als lokale Mitternacht
     * interpretiert.
     *
     * @param {string} value
     * @returns {boolean}
     */
    function isEventDateInPast(value) {
        var parsed = parseEventDateValue(value);
        return parsed !== null && parsed.getTime() < new Date().getTime();
    }

    /**
     * Validiert ein date-time-Feld (Format + optional Vergangenheits-
     * Hinweis) für die Anzeige im eigenen Feedback-Element. Der
     * Vergangenheits-Hinweis (data-check-past, siehe buildValidationAttrs()
     * in SchemaOrgData_FormRenderer.php - ausschließlich Event.startDate)
     * ist ein reiner, nicht-blockierender Warning-Zustand und wird nur
     * gezeigt, wenn das Datum für sich genommen bereits gültig ist -
     * ein Formatfehler hat weiterhin Vorrang.
     *
     * @param {HTMLElement} input
     * @returns {{status: string|null, message: string|null}}
     */
    function validateDateTimeField(input) {
        var result = validateEventDateInput(input.value);

        if (result.status === 'ok' && input.getAttribute('data-check-past') === '1' && isEventDateInPast(input.value)) {
            return { status: 'warning', message: getMessages().dateInPast || null };
        }

        return result;
    }

    /**
     * Prüft, ob Event.endDate nicht vor Event.startDate liegt (nur wenn
     * beide Felder gefüllt und für sich genommen gültig sind - siehe
     * SchemaOrgData_Validator::validateFormData(), gleiche Bedingung
     * serverseitig). Gleicher Zeitpunkt gilt als gültig (nur "davor" ist ein
     * Fehler).
     *
     * @param {HTMLElement|null} startInput
     * @param {HTMLElement|null} endInput
     * @returns {{status: string|null, message: string|null}}
     */
    function checkDateRange(startInput, endInput) {
        var startValue = startInput ? startInput.value : '';
        var endValue = endInput ? endInput.value : '';

        if (startValue.trim() === '' || endValue.trim() === ''
            || validateEventDateInput(startValue).status === 'error'
            || validateEventDateInput(endValue).status === 'error') {
            return { status: null, message: null };
        }

        var startDate = parseEventDateValue(startValue);
        var endDate = parseEventDateValue(endValue);

        if (!startDate || !endDate || endDate.getTime() < startDate.getTime()) {
            return { status: 'error', message: getMessages().dateRangeInvalid || null };
        }

        return { status: 'ok', message: null };
    }

    /**
     * Validiert ein Pflichtfeld (siehe index.php, renderPostalAddressWidget()).
     * Die Fehlermeldung wird bereits server-seitig vollständig aufgelöst und
     * über data-required-message übergeben.
     *
     * @param {string} value
     * @param {string|null} message
     * @returns {{status: string|null, message: string|null}}
     */
    function validateRequiredField(value, message) {
        if (value.trim() !== '') {
            return { status: null, message: null };
        }

        return { status: 'error', message: message || null };
    }

    /**
     * Validiert ein Adress-Pflichtfeld (addressLocality), dessen Live-Pflicht
     * davon abhängt, ob überhaupt ein anderes Feld derselben Adressgruppe
     * (data-address-group, siehe renderAddressSubField() in
     * SchemaOrgData_FormRenderer.php - beim place-Widget zählt zusätzlich
     * das Geschwisterfeld "name" zur Gruppe) bereits befüllt ist. Spiegelt
     * SchemaOrgData_Validator::isAddressProvided()/validatePostalAddressData():
     * eine komplett leere, nicht als Ganzes ui:required markierte Adresse
     * bleibt fehlerfrei, sobald aber ein Gruppenfeld befüllt ist, wird "Ort"
     * live erzwungen. Ein als Ganzes ui:required markiertes place-Widget
     * (z. B. JobPosting.jobLocation) nutzt stattdessen weiterhin den
     * unconditional "required"-Typ (siehe $forceRequired in
     * renderAddressSubField()), landet also nie hier.
     *
     * @param {HTMLElement} input
     * @returns {{status: string|null, message: string|null}}
     */
    function checkAddressRequiredField(input) {
        if (input.value.trim() !== '' || input.placeholder.trim() !== '') {
            return { status: null, message: null };
        }

        var groupId = input.getAttribute('data-address-group');
        var groupFilled = false;

        if (groupId) {
            document.querySelectorAll('[data-address-group="' + groupId + '"]').forEach(function (el) {
                if (el !== input && el.value.trim() !== '') {
                    groupFilled = true;
                }
            });
        }

        if (!groupFilled) {
            return { status: null, message: null };
        }

        return { status: 'error', message: input.getAttribute('data-required-message') || null };
    }

    /**
     * Prüft, ob ein Zeitwert dem Format "HH:MM" entspricht
     * (24-Stunden-Format, siehe README.md "Öffnungszeiten").
     *
     * @param {string} value
     * @returns {boolean}
     */
    function isValidTimeFormat(value) {
        return TIME_PATTERN.test((value || '').trim());
    }

    /**
     * Validiert ein Von/Bis-Zeitpaar des Öffnungszeiten-Widgets
     * (siehe index.php, validateOpeningHoursTime()).
     *
     * @param {string} from
     * @param {string} to
     * @returns {{status: string|null, message: string|null}}
     */
    function validateOpeningHoursTime(from, to) {
        from = (from || '').trim();
        to = (to || '').trim();

        if (from === '' && to === '') {
            return { status: null, message: null };
        }

        if ((from === '') !== (to === '')) {
            return { status: 'error', message: getMessages().openingHoursIncomplete || null };
        }

        if (!TIME_PATTERN.test(from) || !TIME_PATTERN.test(to)) {
            return { status: 'error', message: getMessages().openingHoursFormat || null };
        }

        if (from >= to) {
            return { status: 'error', message: getMessages().openingHoursOrder || null };
        }

        return { status: 'ok', message: null };
    }

    /**
     * Prüft eine numerische Zeichenkette wie PHPs is_numeric()
     * (SchemaOrgData_Validator::validateGeoCoordinate() nutzt
     * is_numeric() direkt): Vorzeichen, Dezimalpunkt und Exponent
     * erlaubt, aber - anders als "Number()" - keine Hex- ("0x1A"),
     * Oktal- oder Binärliteral-Schreibweisen.
     *
     * @param {string} value
     * @returns {boolean}
     */
    function isNumericStringLikePhp(value) {
        return typeof value === 'string' && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(value);
    }

    /**
     * Validiert eine Geo-Koordinate (latitude/longitude), siehe
     * SchemaOrgData_Validator::validateGeoCoordinate(). Leerer Wert
     * wird hier nicht geprüft (Paar-Pflicht siehe runGeoValidation()).
     *
     * @param {string} value
     * @param {number} min
     * @param {number} max
     * @param {string|null} message
     * @returns {{status: string|null, message: string|null}}
     */
    function validateGeoCoordinate(value, min, max, message) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        if (!isNumericStringLikePhp(value)) {
            return { status: 'error', message: message || null };
        }

        var numeric = parseFloat(value);

        if (numeric < min || numeric > max) {
            return { status: 'error', message: message || null };
        }

        return { status: 'ok', message: null };
    }

    /** Validiert geo.latitude (-90..90), siehe validateGeoCoordinate(). */
    function validateGeoLatitude(value) {
        return validateGeoCoordinate(value, -90, 90, getMessages().geoLatitude);
    }

    /** Validiert geo.longitude (-180..180), siehe validateGeoCoordinate(). */
    function validateGeoLongitude(value) {
        return validateGeoCoordinate(value, -180, 180, getMessages().geoLongitude);
    }

    /**
     * Validiert ein Feld des Geo-Widgets (latitude/longitude, siehe
     * index.php, renderGeoWidget()) und bezieht das über data-pair
     * verknüpfte Gegenstück mit ein (Paar-Pflicht "beides oder
     * nichts", analog runOpeningHoursValidation()). Sind beide Felder
     * leer, gilt das Paar als nicht angegeben (kein Fehler). Ist nur
     * eines der beiden Felder gefüllt, ist das jeweils leere Feld ein
     * Fehler (geoIncomplete). Sind beide gefüllt, entscheidet der
     * eigene Wertebereich des soeben geänderten Feldes.
     *
     * @param {HTMLElement} input das gerade geänderte latitude- oder longitude-Feld
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback()
     */
    function runGeoValidation(input, onlyClearErrors) {
        var pairInput = document.getElementById(input.getAttribute('data-pair'));
        var isLatitude = /_latitude$/.test(input.id);
        var ownValue = input.value.trim();
        var pairValue = pairInput ? pairInput.value.trim() : '';
        var result = { status: null, message: null };

        if (ownValue === '' && pairValue === '') {
            // beide leer - kein Fehler
        } else if (ownValue === '') {
            result = { status: 'error', message: getMessages().geoIncomplete || null };
        } else {
            result = isLatitude ? validateGeoLatitude(ownValue) : validateGeoLongitude(ownValue);
        }

        showFieldFeedback(input, input.id + '_feedback', result, onlyClearErrors);

        // Das Gegenstück revalidieren, damit eine Korrektur an diesem Feld
        // sofort im bereits sichtbaren Feedback des anderen Feldes honoriert
        // wird (analog runOpeningHoursValidation()/runEventDateValidation()).
        if (pairInput) {
            var pairIsLatitude = !isLatitude;
            var pairResult = { status: null, message: null };
            if (pairValue === '' && ownValue === '') {
                // beide leer - kein Fehler
            } else if (pairValue === '') {
                pairResult = { status: 'error', message: getMessages().geoIncomplete || null };
            } else {
                pairResult = pairIsLatitude ? validateGeoLatitude(pairValue) : validateGeoLongitude(pairValue);
            }
            showFieldFeedback(pairInput, pairInput.id + '_feedback', pairResult, onlyClearErrors);
        }
    }

    /**
     * Zeigt das Validierungsergebnis in dem Element mit der ID
     * "feedbackId" an. Existiert das Element noch nicht (server-seitig
     * wird ein Feedback-<span> nur bei status !== null gerendert, siehe
     * renderValidationFeedback()), wird es bei Bedarf direkt nach
     * "anchor" eingefügt - so landet das Feedback an derselben Stelle
     * wie ein server-seitig gerendertes <span> (siehe Fix 2/3,
     * README.md "Formularvalidierung"). Ist "feedbackId" bereits
     * vorhanden, wird dieses Element aktualisiert statt ein zweites
     * (gedoppeltes) Feedback-Element anzulegen.
     *
     * Mit "onlyClearErrors" (true beim "input"-Event, siehe
     * initFieldValidation()) wird KEINE neue Fehler-/Warnmeldung
     * angezeigt - weder in einem neuen noch in einem bestehenden
     * Feedback-Element. Ist der Wert hingegen jetzt gültig (status
     * "ok" oder null), wird ein bereits sichtbares Feedback wie gewohnt
     * aktualisiert bzw. entfernt ("Korrektur wird sofort honoriert").
     *
     * @param {HTMLElement} anchor Element, nach dem ein neues
     *        Feedback-<span> eingefügt wird (insertAdjacentElement
     *        "afterend"), falls noch keines existiert
     * @param {string} feedbackId Element-ID des Feedback-<span>
     * @param {{status: string|null, message: string|null}} result
     * @param {boolean} [onlyClearErrors] true beim "input"-Event:
     *        neue Fehler/Warnungen unterdrücken, nur Entfernen erlauben
     */
    function showFieldFeedback(anchor, feedbackId, result, onlyClearErrors) {
        var feedback = document.getElementById(feedbackId);
        var isProblem = (result.status === 'error' || result.status === 'warning');

        if (onlyClearErrors && isProblem) {
            return;
        }

        if (!feedback) {
            if (!result.status) {
                return;
            }
            feedback = document.createElement('span');
            feedback.id = feedbackId;
            anchor.insertAdjacentElement('afterend', feedback);
        }

        if (!result.status) {
            feedback.textContent = '';
            feedback.className = '';
            return;
        }

        var icons = { ok: '✅', warning: '⚠️', error: '❌' };
        feedback.textContent = (icons[result.status] || '') + (result.message ? ' ' + result.message : '');
        feedback.className = FEEDBACK_CLASS + result.status;
    }

    /**
     * Liest den aktuellen addressCountry-Wert für ein Feld mit
     * data-country-field, Standard "DE" falls nicht vorhanden.
     *
     * @param {HTMLElement} input
     * @returns {string}
     */
    function getCountryCode(input) {
        var countryFieldId = input.getAttribute('data-country-field');
        var countryField = countryFieldId ? document.getElementById(countryFieldId) : null;
        return countryField ? countryField.value : 'DE';
    }

    /**
     * Validiert ein Von/Bis-Zeitfeldpaar des Öffnungszeiten-Widgets und
     * zeigt das Ergebnis in einem gemeinsamen Feedback-Element für das
     * Paar an (ID "<vonFeldId>_feedback", siehe renderOpeningHoursWidget()
     * in index.php - dort wird dasselbe <span> server-seitig mit dieser ID
     * gerendert). Ein gemeinsames Element statt je eines pro Feld
     * verhindert doppelte/verschobene Fehlermeldungen (Fix 2).
     *
     * Es gibt vier Feld-Suffixe (_from/_to für den Hauptzeitraum,
     * _from2/_to2 für die Pause) - die Von/Bis-Zuordnung muss daher
     * beide "Von"-Suffixe gleichermaßen erkennen, sonst werden bei
     * einem Pausen-Feld Von und Bis vertauscht.
     *
     * @param {HTMLElement} input das gerade geänderte Von- oder Bis-Feld
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback()
     */
    function runOpeningHoursValidation(input, onlyClearErrors) {
        var pairInput = document.getElementById(input.getAttribute('data-pair'));
        var isSecondRange = /_(from2|to2)$/.test(input.id);
        var isFrom = /_from2?$/.test(input.id);
        var fromInput = isFrom ? input : pairInput;
        var from = isFrom ? input.value : (pairInput ? pairInput.value : '');
        var to = isFrom ? (pairInput ? pairInput.value : '') : input.value;
        var fromEmpty = from.trim() === '';
        var toEmpty = to.trim() === '';
        var result = { status: null, message: null };

        if (fromEmpty && toEmpty) {
            // Beide Felder leer = "geschlossen", kein Fehler.
        } else if (fromEmpty !== toEmpty) {
            // Nur eines der beiden Felder ausgefüllt: die Von/Bis-
            // Reihenfolge kann noch nicht geprüft werden (Benutzer
            // tabbt evtl. noch zum anderen Feld), das Zeitformat des
            // ausgefüllten Feldes aber schon.
            var filledValue = fromEmpty ? to : from;
            if (!isValidTimeFormat(filledValue)) {
                result = { status: 'error', message: getMessages().openingHoursFormat || null };
            }
        } else {
            result = validateOpeningHoursTime(from, to);
        }

        // Pause darf nicht vor dem Ende des Hauptzeitraums beginnen
        // (serverseitiges Vorbild: renderOpeningHoursWidget() in
        // SchemaOrgData_FormRenderer.php, $from2 < $to). Nur relevant
        // für die Pause und nur wenn diese selbst keinen eigenen
        // Format-/Reihenfolgefehler hat (eigener Fehler hat Vorrang,
        // analog updateEndDateFeedback()). "!fromEmpty && !toEmpty"
        // schließt bereits den Leerfall aus, result.status ist an
        // dieser Stelle also immer "ok" oder "error" (nie null) -
        // die Prüfung greift daher bewusst bei "!== 'error'", nicht
        // bei "=== null".
        if (result.status !== 'error' && isSecondRange && !fromEmpty && !toEmpty) {
            var mainToId = fromInput.id.replace(/_from2$/, '_to');
            var mainToInput = document.getElementById(mainToId);
            var mainTo = mainToInput ? mainToInput.value.trim() : '';

            if (mainTo !== '' && from.trim() < mainTo) {
                result = { status: 'error', message: getMessages().openingHoursOverlap || null };
            }
        }

        var group = input.closest('.schemaOrgData-opening-hours-group');
        var feedbackId = (fromInput ? fromInput.id : input.id) + '_feedback';
        showFieldFeedback(group || input, feedbackId, result, onlyClearErrors);

        // Wird ein Hauptzeitraum-Feld geändert (nicht die Pause selbst), kann sich
        // dadurch die Überlappungslage einer bereits eingetragenen Pause ändern
        // (die Pause wurde nicht angefasst, ihr zuletzt angezeigtes Feedback bezieht
        // sich aber auf den jetzt veralteten Hauptzeitraum-Wert) - siehe Befund aus
        // /code-review high (2026-07-06). Die Pause wird daher zusätzlich (nicht
        // anstatt) mit-revalidiert, analog zu runEventDateValidation()/
        // updateEndDateFeedback(), wo eine startDate-Änderung ebenfalls die
        // Bereichsprüfung des zugehörigen endDate-Felds auslöst.
        if (!isSecondRange) {
            var from2Input = document.getElementById(input.id.replace(/_(from|to)$/, '_from2'));
            if (from2Input) {
                runOpeningHoursValidation(from2Input, onlyClearErrors);
            }
        }
    }

    /**
     * Sucht das Bereichs-Gegenstück eines date-time-Felds
     * (Event.startDate/endDate, siehe buildValidationAttrs() in
     * SchemaOrgData_FormRenderer.php): trägt "input" selbst
     * data-range-start-field, ist es das endDate-Feld; andernfalls wird
     * nach einem endDate-Feld gesucht, das per data-range-start-field auf
     * "input" (dann startDate) verweist.
     *
     * @param {HTMLElement} input
     * @returns {{startInput: HTMLElement|null, endInput: HTMLElement}|null}
     */
    function findDateRangeCounterpart(input) {
        var startFieldId = input.getAttribute('data-range-start-field');
        if (startFieldId) {
            return { startInput: document.getElementById(startFieldId), endInput: input };
        }

        var endInput = input.id ? document.querySelector('[data-range-start-field="' + input.id + '"]') : null;
        if (endInput) {
            return { startInput: input, endInput: endInput };
        }

        return null;
    }

    /**
     * Aktualisiert das Feedback des endDate-Felds: eigener Formatfehler hat
     * Vorrang vor dem Bereichsvergleich (siehe checkDateRange()) - ein
     * bereits gemeldeter Formatfehler wird nicht durch einen zusätzlichen
     * Bereichsfehler überschrieben/verdoppelt (analog zur serverseitigen
     * Logik in SchemaOrgData_Validator::validateFormData()).
     *
     * @param {HTMLElement|null} startInput
     * @param {HTMLElement} endInput
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback()
     */
    function updateEndDateFeedback(startInput, endInput, onlyClearErrors) {
        var endOwnResult = validateEventDateInput(endInput.value);
        var result = (endOwnResult.status === 'error') ? endOwnResult : checkDateRange(startInput, endInput);

        showFieldFeedback(endInput, endInput.id + '_feedback', result, onlyClearErrors);
    }

    /**
     * Validiert ein date-time-Feld (Event.startDate/endDate) und
     * aktualisiert bei Bedarf zusätzlich das Feedback des jeweils anderen
     * Feldes: wird startDate validiert, triggert das ebenfalls die
     * Bereichsprüfung für das zugehörige endDate (siehe
     * findDateRangeCounterpart()), damit eine nachträgliche Korrektur von
     * startDate sofort im bereits sichtbaren endDate-Feedback honoriert
     * wird, ohne dass der Nutzer endDate erneut verlassen muss.
     *
     * @param {HTMLElement} input
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback()
     */
    function runEventDateValidation(input, onlyClearErrors) {
        var counterpart = findDateRangeCounterpart(input);

        if (!counterpart) {
            showFieldFeedback(input, input.id + '_feedback', validateDateTimeField(input), onlyClearErrors);
            return;
        }

        if (input !== counterpart.endInput) {
            showFieldFeedback(input, input.id + '_feedback', validateDateTimeField(input), onlyClearErrors);
        }

        updateEndDateFeedback(counterpart.startInput, counterpart.endInput, onlyClearErrors);
    }

    /**
     * Führt die zu input.dataset.validate passende Live-Validierung
     * aus und zeigt das Ergebnis an. Bei "opening_hours" wird das
     * über data-pair verknüpfte Gegenstück (Von/Bis) mit einbezogen
     * (siehe runOpeningHoursValidation()). Bei "date-time" wird das
     * über data-range-start-field verknüpfte Gegenstück (startDate/
     * endDate) mit einbezogen (siehe runEventDateValidation()). Felder mit
     * data-required-message melden einen leeren Wert als Fehler,
     * unabhängig vom data-validate-Typ - Ausnahme: "address_required"
     * (siehe checkAddressRequiredField()), dessen Pflicht zusätzlich davon
     * abhängt, ob die Adressgruppe (data-address-group) bereits befüllt ist.
     *
     * @param {HTMLElement} input
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback() -
     *        true beim "input"-Event: zeigt keine neue Fehler-/
     *        Warnmeldung an, entfernt aber ein bereits sichtbares
     *        Feedback, sobald der Wert wieder gültig ist
     */
    function runFieldValidation(input, onlyClearErrors) {
        var type = input.getAttribute('data-validate');

        if (type === 'address_required') {
            showFieldFeedback(input, input.id + '_feedback', checkAddressRequiredField(input), onlyClearErrors);
            return;
        }

        var requiredMessage = input.getAttribute('data-required-message');

        // Pflichtfeld leer: Fehler nur wenn kein geerbter Wert als Placeholder gesetzt
        // ist. Ein gesetzter Placeholder bedeutet, dass ein Wert von einer übergeordneten
        // Ebene geerbt wird und das Pflichtfeld abdeckt — dann vorhandenes Feedback
        // entfernen statt einen Fehler zu zeigen. Ohne Placeholder: Fehler anzeigen.
        // Wichtig: return in beiden Zweigen verhindert, dass case 'required' im
        // switch validateRequiredField() aufruft und doppelt einen Fehler erzeugt.
        if (requiredMessage && input.value.trim() === '') {
            if (input.placeholder.trim() !== '') {
                showFieldFeedback(input, input.id + '_feedback', { status: null, message: null }, onlyClearErrors);
            } else {
                showFieldFeedback(input, input.id + '_feedback', { status: 'error', message: requiredMessage }, onlyClearErrors);
            }
            return;
        }

        var result = { status: null, message: null };

        switch (type) {
            case 'url':
                result = validateUrl(input.value);
                break;
            case 'email':
                result = validateEmail(input.value);
                break;
            case 'postal_code':
                result = validatePostalCode(input.value, getCountryCode(input));
                break;
            case 'telephone':
                result = validateTelephone(input.value, getCountryCode(input));
                break;
            case 'sort_order':
                result = validateSortOrder(input.value);
                break;
            case 'required':
                result = validateRequiredField(input.value, input.getAttribute('data-required-message'));
                break;
            case 'opening_hours':
                runOpeningHoursValidation(input, onlyClearErrors);
                return;
            case 'geo':
                runGeoValidation(input, onlyClearErrors);
                return;
            case 'person_slug':
                runPersonSlugValidation(input, onlyClearErrors);
                return;
            case 'date-time':
                runEventDateValidation(input, onlyClearErrors);
                return;
            default:
                return;
        }

        showFieldFeedback(input, input.id + '_feedback', result, onlyClearErrors);
    }

    /**
     * Aktiviert die Live-Validierung (bei "blur" und "input") für alle
     * Felder mit data-validate innerhalb des Admin-Formulars.
     *
     * - "blur": vollständige Validierung wie gewohnt, inkl. neuer
     *   Fehler-/Warnmeldungen.
     * - "input": KEINE neue Fehler-/Warnmeldung während der Eingabe
     *   (keine premature validation) - ein bereits sichtbares Feedback
     *   wird aber sofort entfernt bzw. aktualisiert, sobald der Wert
     *   wieder gültig ist (Fix 1, "Korrektur wird sofort honoriert").
     *
     * Konsistentes Muster für alle Felder mit Live-Validierung: Tippen
     * meckert nie, Verlassen des Feldes gibt vollständiges Feedback.
     *
     * Bereits befüllte Felder werden zusätzlich einmalig beim Laden der
     * Seite vollständig validiert, damit ein ungültiger gespeicherter
     * bzw. nach gescheitertem Save zurückgegebener Wert sofort als
     * Fehler angezeigt wird, ohne dass der Nutzer das Feld erst
     * verlassen muss.
     */
    function initFieldValidation() {
        var inputs = document.querySelectorAll('[data-validate]');

        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('blur', function (event) {
                runFieldValidation(event.target, false);
            });
            inputs[i].addEventListener('input', function (event) {
                runFieldValidation(event.target, true);
            });

            if (inputs[i].value.trim() !== '') {
                runFieldValidation(inputs[i], false);
            }
        }
    }

    /**
     * Füllt das Slug-Feld des "Neue Person"-Formulars beim Verlassen des
     * Namensfelds mit dem abgeleiteten Vorschlag (generateSlugSuggestionJs()).
     * Ohne diese Verdrahtung bleibt der Hilfetext hint_person_slug ohne
     * sichtbare Wirkung: Der serverseitig gesetzte Placeholder wird aus dem
     * beim Seitenaufbau bekannten Namen berechnet und ist im frischen
     * Formular deshalb leer, und die serverseitige Ableitung greift erst
     * beim Speichern.
     *
     * Bewusst getrennt von runPersonSlugValidation(): Die Validierungs-
     * funktionen dieser Datei zeigen ausschließlich Feedback an und ändern
     * keine Feldwerte.
     *
     * Ein automatisch gefülltes Slug-Feld trägt data-slug-autofilled und
     * folgt weiteren Namensänderungen. Sobald der Nutzer den Slug selbst
     * bearbeitet, verfällt die Markierung und der Wert bleibt unangetastet.
     * Ein leerer Vorschlag (Name gelöscht oder nur Sonderzeichen) lässt das
     * Slug-Feld unverändert, statt es zu leeren - ein Name ist ohnehin
     * Pflichtfeld, ein verwaister Slug kann daher nicht gespeichert werden.
     *
     * Das Bearbeiten-Formular ist nicht betroffen: Dort trägt das
     * Namensfeld kein data-validate und es existiert kein
     * Slug-Eingabefeld (siehe SchemaOrgData_PersonsAdminRenderer).
     */
    function initPersonSlugLiveFill() {
        var fields = document.querySelectorAll('[data-validate="person_slug"]');

        for (var i = 0; i < fields.length; i++) {
            if (/_slug$/.test(fields[i].id)) {
                // Eine manuelle Eingabe im Slug-Feld beendet die automatische
                // Ableitung dauerhaft. Das programmatische Setzen des Werts
                // weiter unten löst kein input-Ereignis aus und hebt die
                // Markierung deshalb nicht selbst wieder auf.
                fields[i].addEventListener('input', function (event) {
                    event.target.removeAttribute('data-slug-autofilled');
                });
                continue;
            }

            fields[i].addEventListener('blur', function (event) {
                var slugInput = document.getElementById(event.target.getAttribute('data-pair'));

                if (!slugInput) {
                    return;
                }
                if (slugInput.value.trim() !== '' && !slugInput.hasAttribute('data-slug-autofilled')) {
                    return;
                }

                var suggestion = generateSlugSuggestionJs(event.target.value);
                if (suggestion === '') {
                    return;
                }

                slugInput.value = suggestion;
                slugInput.setAttribute('data-slug-autofilled', '1');

                // Die Kollisionsprüfung lief über den zuerst registrierten
                // blur-Listener bereits gegen den vorherigen Feldwert. Nach dem
                // Eintragen erneut anstoßen, damit das Feedback den jetzt
                // tatsächlich im Feld stehenden Slug bewertet und nicht den
                // vorherigen.
                runFieldValidation(slugInput, false);
            });
        }
    }

    /**
     * Liefert eine eindeutige Kennung für ein Formularfeld zur
     * Snapshot-Erstellung (siehe snapshotSectionValues()): Felder mit
     * "id" über "id:<id>", Radios/Checkboxen ohne "id" (z. B.
     * jsonld_mode, siehe renderExistingJsonLdNotice() in index.php)
     * über "name:<name>=<value>".
     *
     * @param {HTMLElement} el
     * @returns {string|null}
     */
    function fieldSnapshotKey(el) {
        if (el.id) {
            return 'id:' + el.id;
        }
        if (el.name) {
            return 'name:' + el.name + '=' + el.value;
        }
        return null;
    }

    /**
     * Erstellt einen Snapshot der aktuellen Feldwerte einer
     * .schemaOrgData-scope-Sektion (siehe fieldSnapshotKey()).
     *
     * @param {HTMLElement} section
     * @returns {object}
     */
    function snapshotSectionValues(section) {
        var values = {};

        section.querySelectorAll('input, select, textarea').forEach(function (el) {
            var key = fieldSnapshotKey(el);
            if (key === null) {
                return;
            }
            values[key] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value;
        });

        return values;
    }

    /**
     * Prüft, ob sich die Feldwerte einer Sektion gegenüber ihrem beim
     * Laden erstellten Snapshot (siehe initScopeSelector()) geändert
     * haben.
     *
     * @param {HTMLElement} section
     * @returns {boolean}
     */
    function sectionHasUnsavedChanges(section) {
        var initial = section.schemaOrgDataSnapshot;
        if (!initial) {
            return false;
        }

        var current = snapshotSectionValues(section);

        for (var key in initial) {
            if (initial[key] !== current[key]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liefert die aktuell sichtbare .schemaOrgData-scope-Sektion (ohne
     * style="display:none"), oder null falls keine sichtbar ist.
     *
     * @returns {HTMLElement|null}
     */
    function getVisibleSection() {
        var result = null;

        document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
            if (result === null && section.style.display !== 'none') {
                result = section;
            }
        });

        return result;
    }

    /**
     * Entfernt den dezenten Hinweis auf ungespeicherte Eingaben
     * (siehe showUnsavedNotice()), falls vorhanden.
     */
    function hideUnsavedNotice() {
        var notice = document.getElementById('schemaOrgData_unsaved_notice');
        if (notice) {
            notice.remove();
        }
    }

    /**
     * Zeigt einen dezenten, nicht-blockierenden Hinweis oberhalb des
     * Formulars an, dass die Sektion "sectionLabel" (data-scope-label,
     * siehe buildScopeLabel() in index.php) ungespeicherte Eingaben
     * enthält (Sprachschlüssel notice_unsaved_changes, Platzhalter
     * {PARAM1}, siehe getMessages().unsavedChanges).
     *
     * @param {string} sectionLabel
     */
    function showUnsavedNotice(sectionLabel) {
        var notice = document.getElementById('schemaOrgData_unsaved_notice');

        if (!notice) {
            var admin = document.querySelector('.schemaOrgData-admin');
            if (!admin) {
                return;
            }
            notice = document.createElement('div');
            notice.id = 'schemaOrgData_unsaved_notice';
            notice.className = 'schemaOrgData-notice schemaOrgData-notice--unsaved';
            admin.insertBefore(notice, admin.firstChild);
        }

        var template = getMessages().unsavedChanges || '';
        notice.textContent = applyMessageParam(template, sectionLabel);
    }

    /**
     * Aktiviert den zweistufigen Scope-Selektor (siehe index.php,
     * renderScopeSelector()/renderScopeSection()): Stufe 1
     * (#schemaOrgData_scope_cat) wählt Global oder eine Kategorie,
     * Stufe 2 (#schemaOrgData_scope_page) wählt optional eine Seite
     * dieser Kategorie und wird anhand der auf Stufe 2 als JSON
     * hinterlegten data-pages-Map befüllt (kein PHP-Roundtrip nötig).
     * Eine Auswahl blendet die zugehörige .schemaOrgData-scope-Sektion
     * ein und alle anderen aus, ohne die Seite neu zu laden (moziloCMS
     * würde den Plugin-Tab bei einem Page-Reload schließen). Alle
     * Sektionen sind vorgerendert; nur die Felder der aktiven Sektion
     * werden aktiviert (disabled=false), die übrigen deaktiviert,
     * damit das moziloCMS-Disketten-Icon beim Speichern nur die aktive
     * Sektion überträgt. Die hidden inputs schemaOrgData_cat/_page
     * werden für den POST beim Speichern aktualisiert. Bei einem
     * Scope-Wechsel durch den Nutzer wird zusätzlich die serverseitige
     * Save-Ergebnis-Box entfernt (siehe hideSaveNotice()), da sie sich
     * auf den vorherigen Geltungsbereich bezieht.
     *
     * Zusätzlich wird beim Init für jede Sektion ein Snapshot ihrer
     * Feldwerte erstellt (snapshotSectionValues()). Verlässt der
     * Nutzer eine Sektion mit gegenüber diesem Snapshot geänderten
     * Werten, ohne zu speichern, zeigt updateUnsavedNotice() einen
     * dezenten, nicht-blockierenden Hinweis oberhalb des Formulars
     * (showUnsavedNotice()). Der Hinweis verschwindet automatisch,
     * wenn der Nutzer in die betroffene Sektion zurückwechselt oder
     * die Seite speichert (vollständiger Page-Reload). Es wird kein
     * Autosave ausgelöst und kein Wechsel verhindert; Feldwerte
     * bleiben beim Wechsel unverändert im DOM erhalten.
     */
    function initScopeSelector() {
        var catSelect  = document.getElementById('schemaOrgData_scope_cat');
        var pageSelect = document.getElementById('schemaOrgData_scope_page');
        if (!catSelect || !pageSelect) return;

        var pagesByCat = {};
        try {
            pagesByCat = JSON.parse(pageSelect.getAttribute('data-pages') || '{}');
        } catch (e) {
            // Ohne Meldung bliebe nur das Ausfallbild: ein leeres zweites
            // Auswahlfeld, das sich nicht von einer Kategorie ohne Seiten
            // unterscheiden lässt. Eine UI-Meldung wäre für diesen Fall
            // überzogen - die Map entsteht serverseitig per json_encode()
            // und ist im Normalbetrieb wohlgeformt.
            console.warn('schemaOrgData: data-pages konnte nicht als JSON gelesen werden - die Seitenauswahl bleibt leer.', e);
            pagesByCat = {};
        }

        // Initialzustand jeder Sektion merken (siehe
        // sectionHasUnsavedChanges()).
        document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
            section.schemaOrgDataSnapshot = snapshotSectionValues(section);
        });

        // Sektion, für die aktuell der Hinweis auf ungespeicherte
        // Eingaben angezeigt wird (oder null).
        var flaggedSection = null;

        // Zeigt bzw. verbirgt den Hinweis auf ungespeicherte Eingaben
        // beim Wechsel von "leavingSection" zu "enteringSection"
        // (siehe showUnsavedNotice()/hideUnsavedNotice()).
        function updateUnsavedNotice(leavingSection, enteringSection) {
            if (leavingSection && sectionHasUnsavedChanges(leavingSection)) {
                flaggedSection = leavingSection;
                showUnsavedNotice(leavingSection.getAttribute('data-scope-label') || '');
            } else if (flaggedSection === leavingSection) {
                flaggedSection = null;
                hideUnsavedNotice();
            }

            if (enteringSection === flaggedSection) {
                flaggedSection = null;
                hideUnsavedNotice();
            }
        }

        // Entfernt die serverseitige Save-Ergebnis-Box (Erfolg/Fehler,
        // siehe renderSaveResultNotice(), #schemaOrgData_save_notice).
        // Sie bezieht sich auf den zuvor gespeicherten Geltungsbereich und
        // ist nach einem Scope-Wechsel nicht mehr relevant (Fix 4).
        function hideSaveNotice() {
            var notice = document.getElementById('schemaOrgData_save_notice');
            if (notice) {
                notice.remove();
            }
        }

        // Schreibt den aktiven Geltungsbereich in die Hidden-Felder, die
        // beim Speichern mitgesendet werden (siehe renderAdminPage(),
        // #schemaOrgData_hidden_cat / #schemaOrgData_hidden_page).
        function updateScopeHiddenFields(activeCat, activePage) {
            var hiddenCat  = document.getElementById('schemaOrgData_hidden_cat');
            var hiddenPage = document.getElementById('schemaOrgData_hidden_page');
            if (hiddenCat)  hiddenCat.value  = activeCat  || '';
            if (hiddenPage) hiddenPage.value = activePage || '';
        }

        // Blendet die zu cat/page passende .schemaOrgData-scope-Sektion
        // ein und alle anderen aus, (de-)aktiviert deren Felder und
        // aktualisiert die Hidden-Felder für den POST. Liest außerdem
        // data-save-label der aktiven Sektion aus und aktualisiert
        // beide Speichern-Buttons (oben + unten, siehe renderAdminPage()).
        function activateSection(cat, page) {
            var newSaveLabel = null;

            document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
                var sCat  = section.getAttribute('data-scope-cat')  || '';
                var sPage = section.getAttribute('data-scope-page') || '';
                var isActive = (sCat === cat && sPage === page);
                section.style.display = isActive ? '' : 'none';

                // Inputs der Sektion aktivieren/deaktivieren damit nur
                // die aktive Sektion beim Speichern mitgesendet wird
                section.querySelectorAll('input, select, textarea').forEach(
                    function (el) {
                        el.disabled = !isActive;
                    }
                );

                // Innerhalb der aktivierten Sektion nur die Felder der
                // aktuell gewählten Typ-Sektion aktiviert lassen (siehe
                // applyTypeFieldsState())
                if (isActive) {
                    applyTypeFieldsState(section);
                    newSaveLabel = section.getAttribute('data-save-label') || null;
                }
            });

            if (newSaveLabel !== null) {
                document.querySelectorAll('.schemaOrgData-save-bar button').forEach(function (btn) {
                    btn.textContent = newSaveLabel;
                });
            }

            updateScopeHiddenFields(cat, page);
        }

        // Befüllt Stufe 2 (Seiten-Select) anhand der gewählten Kategorie
        // aus der data-pages-Map und blendet sie nur ein, wenn die
        // Kategorie Seiten hat bzw. eine Kategorie gewählt ist.
        function populatePageSelect(cat, selectedPage) {
            while (pageSelect.options.length > 1) {
                pageSelect.remove(1);
            }

            var pages = pagesByCat[cat] || [];
            pages.forEach(function (page) {
                var option = document.createElement('option');
                option.value = page.value;
                option.textContent = page.label;
                if (page.value === selectedPage) {
                    option.selected = true;
                }
                pageSelect.appendChild(option);
            });

            if (!selectedPage) {
                pageSelect.value = '';
            }

            pageSelect.style.display = (cat !== '') ? '' : 'none';
        }

        catSelect.addEventListener('change', function () {
            var cat = catSelect.value;
            var leavingSection = getVisibleSection();
            hideSaveNotice();
            populatePageSelect(cat, '');
            activateSection(cat, '');
            updateUnsavedNotice(leavingSection, getVisibleSection());
        });

        pageSelect.addEventListener('change', function () {
            var leavingSection = getVisibleSection();
            hideSaveNotice();
            activateSection(catSelect.value, pageSelect.value);
            updateUnsavedNotice(leavingSection, getVisibleSection());
        });

        // Einmalig beim Init: beide Selects auf den serverseitig aktiven
        // Geltungsbereich vorbelegen. Quelle ist die sichtbare
        // .schemaOrgData-scope-Sektion (renderScopeSection() rendert genau
        // eine Sektion ohne style="display:none" und versieht jede Sektion
        // zuverlässig mit data-scope-cat/data-scope-page - auch für
        // "Seite", anders als ein <option>-Attribut, das bei gleichnamigen
        // Seiten verschiedener Kategorien mehrdeutig sein könnte). Ohne
        // diesen Schritt enthalten die Hidden-Felder beim ersten Laden der
        // Seite (inkl. direkt nach dem Speichern) immer "".
        var activeSection = getVisibleSection();
        var activeCat  = activeSection ? (activeSection.getAttribute('data-scope-cat')  || '') : '';
        var activePage = activeSection ? (activeSection.getAttribute('data-scope-page') || '') : '';

        catSelect.value = activeCat;
        populatePageSelect(activeCat, activePage);
        updateScopeHiddenFields(activeCat, activePage);
    }

    /**
     * Aktiviert innerhalb einer Scope-Sektion nur die Formularfelder der
     * aktuell gewählten Typ-Sektion (.schemaOrgData-type-fields, siehe
     * index.php renderScopeSection()) und deaktiviert alle anderen.
     * Deaktivierte Felder werden vom Browser nicht mitgesendet - ohne
     * dies würden ausgeblendete Typ-Sektionen mit leeren Werten
     * gleichnamige Felder der aktiven Typ-Sektion überschreiben
     * (last-value-wins).
     */
    function applyTypeFieldsState(scopeSection) {
        var select = scopeSection.querySelector('.schemaOrgData-type-select');
        var activeType = select ? select.value : null;

        scopeSection.querySelectorAll('.schemaOrgData-type-fields').forEach(
            function (group) {
                var isActive = group.getAttribute('data-schema-type') === activeType;
                group.style.display = isActive ? '' : 'none';

                group.querySelectorAll('input, select, textarea').forEach(
                    function (el) {
                        el.disabled = !isActive;
                    }
                );
            }
        );
    }

    /**
     * Aktiviert die Type-Auswahl je Geltungsbereich: blendet die
     * Formularfelder des gewählten Schema-Types ein und alle anderen
     * aus und deaktiviert deren Felder (siehe applyTypeFieldsState()).
     */
    function initTypeSwitcher() {
        var selects = document.querySelectorAll('.schemaOrgData-type-select');

        for (var i = 0; i < selects.length; i++) {
            (function (select) {
                var scope = select.closest('.schemaOrgData-scope');
                if (!scope) {
                    return;
                }

                select.addEventListener('change', function () {
                    applyTypeFieldsState(scope);
                });

                // Initialzustand: nur für die aktive Scope-Sektion
                // anwenden - inaktive Sektionen sind bereits serverseitig
                // vollständig deaktiviert (renderScopeSection()).
                if (scope.style.display !== 'none') {
                    applyTypeFieldsState(scope);
                }
            })(selects[i]);
        }
    }

    /**
     * Rendert das Ergebnis von validateExtensionField() in das
     * "<id>_feedback"-Element des Erweiterungsfelds.
     *
     * @param {HTMLElement} feedback
     * @param {object} result Rückgabe von validateExtensionField()
     * @param {boolean} [schemaLoadFailed] true, wenn das Schema nicht
     *        geladen werden konnte (siehe initExtensionFieldValidation()) -
     *        Stufe 2 (Property-Whitelist) und Stufe 3 (Format) sind dann
     *        stumm ausgefallen (schema === null), ohne diesen Parameter
     *        würde ein leeres unknownProperties/formatErrors fälschlich
     *        als grünes "✅" gewertet.
     * @param {boolean} [personSuggestionContext] true, wenn das
     *        Erweiterungsfeld im globalen Geltungsbereich eines
     *        Organisations-Identity-Types steht (Attribut
     *        data-person-suggestions, siehe initExtensionFieldValidation()) -
     *        nur dann tritt der Info-Hinweis an die Stelle der
     *        Unbekannt-Warnung. Fehlt der Parameter, bleibt es bei der
     *        Warnung, dem restriktiveren der beiden Fälle.
     */
    function showExtensionFeedback(feedback, result, schemaLoadFailed, personSuggestionContext) {
        while (feedback.firstChild) {
            feedback.removeChild(feedback.firstChild);
        }

        // Sicheres DOM-Bauen statt innerHTML - Property-Namen und
        // AJV-Fehlermeldungen können Nutzereingaben enthalten (DOM-XSS-Schutz).
        function appendFeedbackSpan(status, text) {
            var span = document.createElement('span');
            span.className = FEEDBACK_CLASS + status;
            span.textContent = text;
            feedback.appendChild(span);
        }

        if (result.syntaxError) {
            appendFeedbackSpan('error', '❌ ' + result.syntaxError);
            return;
        }

        if (schemaLoadFailed) {
            appendFeedbackSpan('warning', '⚠️ ' + (getMessages().extensionSchemaUnavailable || ''));
            return;
        }

        result.unknownProperties.forEach(function (property) {
            var value = result.data ? result.data[property] : undefined;
            if (personSuggestionContext && isPersonSuggestionCandidate(property, value)) {
                appendFeedbackSpan('info', 'ℹ️ '
                    + applyMessageParam(getMessages().personSuggestionCandidate || property, property));
            } else {
                appendFeedbackSpan('warning', '⚠️ '
                    + applyMessageParam(getMessages().unknownProperty || property, property));
            }
        });

        result.formatErrors.forEach(function (error) {
            appendFeedbackSpan('error', '❌ ' + (error.instancePath || '') + ' ' + error.message);
        });

        if (feedback.children.length === 0) {
            appendFeedbackSpan('ok', '✅');
        }
    }

    /**
     * Aktiviert die Live-Validierung der Erweiterungsfelder
     * (.schemaOrgData-extension-field): lädt das zugehörige Schema
     * über data-schema-url und validiert bei "blur" mit
     * validateExtensionField(). Ein bereits befülltes Feld wird
     * zusätzlich einmalig beim Laden der Seite validiert (JSON-
     * Syntaxfehler erscheinen so sofort, auch ohne Nutzerinteraktion);
     * nach dem Nachladen des Schemas wird bei einem nicht-leeren Feld
     * erneut validiert, damit Warnungen zu unbekannten Properties
     * bzw. Format-Fehler ebenfalls ohne Blur sichtbar werden.
     *
     * Ein fehlgeschlagener Abruf (Netzwerkfehler oder HTTP-Fehlerstatus -
     * response.ok wird deshalb geprüft, ein 404 mit HTML-Fehlerseite als
     * Body würde sonst erst über den response.json()-Parsingfehler im
     * catch landen) setzt schemaLoadFailed dauerhaft auf true: schema
     * bleibt null, Stufe 2 (Property-Whitelist) und Stufe 3 (Format)
     * finden dadurch nicht statt. showExtensionFeedback() zeigt in diesem
     * Fall eine Warnung statt eines grünen Hakens, da nur die JSON-Syntax
     * (Stufe 1) tatsächlich geprüft wurde - Speichern bleibt möglich.
     */
    function initExtensionFieldValidation() {
        var textareas = document.querySelectorAll('.schemaOrgData-extension-field');

        for (var i = 0; i < textareas.length; i++) {
            (function (textarea) {
                var schema = null;
                var schemaLoadFailed = false;
                var schemaUrl = textarea.getAttribute('data-schema-url');
                // Anwesenheit genügt, das Attribut trägt keinen Wert (siehe
                // SchemaOrgData_FormRenderer::renderExtensionFieldWidget()).
                var personSuggestionContext = textarea.hasAttribute('data-person-suggestions');

                var validate = function () {
                    var feedback = document.getElementById(textarea.id + '_feedback');
                    if (feedback) {
                        showExtensionFeedback(feedback, validateExtensionField(textarea.value, schema), schemaLoadFailed, personSuggestionContext);
                    }
                };

                if (schemaUrl) {
                    fetch(schemaUrl)
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('schema fetch failed: ' + response.status);
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            schema = data;
                            if (textarea.value.trim() !== '') {
                                validate();
                            }
                        })
                        .catch(function () {
                            schema = null;
                            schemaLoadFailed = true;
                            if (textarea.value.trim() !== '') {
                                validate();
                            }
                        });
                }

                textarea.addEventListener('blur', validate);

                if (textarea.value.trim() !== '') {
                    validate();
                }
            })(textareas[i]);
        }
    }

    /**
     * Aktiviert den "Alle Kategorien"-Toggle der Ausschlussliste
     * (siehe index.php, renderExcludedCatsField()): ein Klick auf den
     * Toggle setzt bzw. leert alle Kategorie-Checkboxen mit
     * passendem name-Attribut; eine Änderung an einer einzelnen
     * Kategorie-Checkbox aktualisiert den Toggle-Zustand
     * (gecheckt / leer / indeterminate bei Teilauswahl).
     */
    function initExcludedCatsSelectAll() {
        var toggles = document.querySelectorAll('[data-select-all]');

        for (var i = 0; i < toggles.length; i++) {
            (function (toggle) {
                var name = toggle.getAttribute('data-select-all');
                var checkboxes = document.querySelectorAll(
                    'input[type="checkbox"][name="' + name + '"]'
                );

                if (checkboxes.length === 0) {
                    return;
                }

                var updateToggleState = function () {
                    var checkedCount = 0;
                    for (var j = 0; j < checkboxes.length; j++) {
                        if (checkboxes[j].checked) {
                            checkedCount++;
                        }
                    }
                    toggle.checked = (checkedCount === checkboxes.length);
                    toggle.indeterminate = (checkedCount > 0 && checkedCount < checkboxes.length);
                };

                toggle.addEventListener('change', function () {
                    for (var j = 0; j < checkboxes.length; j++) {
                        checkboxes[j].checked = toggle.checked;
                    }
                    toggle.indeterminate = false;
                });

                for (var j = 0; j < checkboxes.length; j++) {
                    checkboxes[j].addEventListener('change', updateToggleState);
                }

                updateToggleState();
            })(toggles[i]);
        }
    }

    /**
     * Verdrahtet die Vollansicht-Dialoge der erkannten JSON-LD-Blöcke
     * (.schemaOrgData-preview-trigger-btn / data-dialog bzw.
     * data-dialog-close, siehe
     * SchemaOrgData_AdminPageRenderer::renderExistingJsonLdNotice()).
     * Der Import selbst ist ein normaler Formular-Submit (Button
     * "schemaOrgData_import_action") und funktioniert vollständig ohne
     * JavaScript - nur die Dialog-Vollansicht ist JS-abhängig, die
     * <pre>-Vorschau bleibt in jedem Fall sichtbar.
     */
    function initPreviewDialogs() {
        var triggers = document.querySelectorAll('[data-dialog]');
        for (var i = 0; i < triggers.length; i++) {
            triggers[i].addEventListener('click', function (event) {
                var d = document.getElementById(event.currentTarget.getAttribute('data-dialog'));
                if (d && d.showModal) { d.showModal(); }
            });
        }
        var closers = document.querySelectorAll('[data-dialog-close]');
        for (var i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', function (event) {
                var d = document.getElementById(event.currentTarget.getAttribute('data-dialog-close'));
                if (d) { d.close(); }
            });
        }
    }

    /**
     * Verdrahtet alle Elemente, die ein data-action-Attribut tragen, mit
     * dem zugehörigen Handler. Der Attributwert benennt die Aktion, weitere
     * data-Attribute tragen deren Parameter:
     *
     *   persons-open   (click)  - Scope-Container aus, Personen-Container ein
     *   persons-back   (click)  - umgekehrt
     *   persons-show   (click)  - data-persons-target: die [data-persons-view]
     *                             mit dieser id sichtbar und ihre Felder aktiv,
     *                             alle anderen aus und disabled
     *   confirm        (click)  - data-confirm: Rückfrage vor dem Submit,
     *                             bei Ablehnung preventDefault()
     *   idrl-toggle    (change) - im umschließenden .schemaOrgData-idrl-container
     *                             die passende .schemaOrgData-idrl-<value>-Sektion
     *                             einblenden und .schemaOrgData-idrl-mode-field
     *                             nachführen
     *
     * Warum data-action statt eines on*-Attributs: In einem on*-Attribut steht
     * JavaScript-Quelltext innerhalb eines HTML-Attributs, ein eingesetzter Wert
     * durchläuft also zwei ineinander verschachtelte Kodierungskontexte
     * (JS-String im HTML-Attribut) - eine Fehlerquelle, die sich nicht durch
     * htmlspecialchars() allein schließen lässt. Als data-Attribut ist ein
     * Parameter reiner Text und braucht nur die HTML-Attribut-Kodierung.
     *
     * Warum kein einzelner Delegations-Listener am document: Die Admin-Seite
     * wird serverseitig einmal je Request vollständig gerendert, es kommt kein
     * Markup nachträglich hinzu - Delegation brächte hier keinen Vorteil, würde
     * aber die Zuordnung Element/Handler verschleiern. Ein Listener je Element
     * macht im Debugger sichtbar, welches Element woran hängt.
     *
     * Ein data-action-Wert ist von beiden Seiten greppbar: derselbe Zeichenkette
     * folgt man vom erzeugenden Renderer bis hierher und zurück.
     *
     * Unbekannte Aktionen werden übersprungen; ein fehlender Parameter lässt
     * den Handler wirkungslos, wirft aber nicht.
     */
    function initDataActions() {
        // Beide Container gehören fest zur Admin-Seite (Scope-Formular und
        // Personen-Registry) und sind deshalb kein Parameter des auslösenden
        // Elements.
        var SCOPE_CONTAINER_ID = 'schemaOrgData_scope_container';
        var PERSONS_CONTAINER_ID = 'schemaOrgData_persons_container';

        var setContainerVisible = function (containerId, visible) {
            var container = document.getElementById(containerId);
            if (container) {
                container.style.display = visible ? '' : 'none';
            }
        };

        var showPersonsView = function (viewId) {
            var views = document.querySelectorAll('[data-persons-view]');
            for (var i = 0; i < views.length; i++) {
                var active = (views[i].id === viewId);
                views[i].style.display = active ? '' : 'none';
                // Mehrere vorgerenderte Personen-Formulare teilen sich dieselben
                // Feldnamen - nur die Felder der aktiven Ansicht dürfen beim
                // Speichern übertragen werden.
                var fields = views[i].querySelectorAll('input, select, textarea');
                for (var j = 0; j < fields.length; j++) {
                    fields[j].disabled = !active;
                }
            }
        };

        var handlers = {
            'persons-open': {
                event: 'click',
                run: function () {
                    setContainerVisible(SCOPE_CONTAINER_ID, false);
                    setContainerVisible(PERSONS_CONTAINER_ID, true);
                }
            },
            'persons-back': {
                event: 'click',
                run: function () {
                    setContainerVisible(PERSONS_CONTAINER_ID, false);
                    setContainerVisible(SCOPE_CONTAINER_ID, true);
                }
            },
            'persons-show': {
                event: 'click',
                run: function (event, element) {
                    var viewId = element.getAttribute('data-persons-target');
                    if (!viewId) {
                        return;
                    }
                    showPersonsView(viewId);
                }
            },
            'confirm': {
                event: 'click',
                run: function (event, element) {
                    var text = element.getAttribute('data-confirm');
                    // Ohne Text keine Rückfrage - ein fehlender Sprachwert darf
                    // den Button nicht unbrauchbar machen, der Submit läuft.
                    if (!text) {
                        return;
                    }
                    if (!window.confirm(text)) {
                        event.preventDefault();
                    }
                }
            },
            'idrl-toggle': {
                event: 'change',
                run: function (event, element) {
                    var container = element.closest('.schemaOrgData-idrl-container');
                    if (!container) {
                        return;
                    }
                    var sections = container.querySelectorAll('.schemaOrgData-idrl-section');
                    for (var i = 0; i < sections.length; i++) {
                        sections[i].style.display = 'none';
                    }
                    var active = container.querySelector('.schemaOrgData-idrl-' + element.value);
                    if (active) {
                        active.style.display = '';
                    }
                    // Das versteckte Feld trägt den tatsächlichen Formularfeldnamen;
                    // die Radios sind je Instanz eindeutig benannt und taugen
                    // deshalb nicht als übertragener Wert.
                    var modeField = container.querySelector('.schemaOrgData-idrl-mode-field');
                    if (modeField) {
                        modeField.value = element.value;
                    }
                }
            }
        };

        var elements = document.querySelectorAll('[data-action]');

        for (var i = 0; i < elements.length; i++) {
            (function (element) {
                var action = element.getAttribute('data-action');
                // hasOwnProperty statt einer einfachen Wahrheitsprüfung: ein
                // data-action="constructor" träfe sonst auf das Object-Prototyp
                // und liefe in einen Handler ohne run().
                if (!Object.prototype.hasOwnProperty.call(handlers, action)) {
                    return;
                }
                var handler = handlers[action];
                element.addEventListener(handler.event, function (event) {
                    handler.run(event, element);
                });
            })(elements[i]);
        }
    }

    /**
     * Initialisiert das gesamte Admin-Formular: Scope-Selektor,
     * Type-Umschaltung, Live-Validierung der Formularfelder sowie der
     * Erweiterungsfelder, Slug-Ableitung im "Neue Person"-Formular.
     * Wird von renderAdminPage() nach DOMContentLoaded aufgerufen.
     */
    function initAdminForm() {
        initScopeSelector();
        initTypeSwitcher();
        initFieldValidation();
        initPersonSlugLiveFill();
        initExtensionFieldValidation();
        initExcludedCatsSelectAll();
        initPreviewDialogs();
        initDataActions();
    }

    // Öffentliche API
    window.schemaOrgDataValidator = {
        validateExtensionField: validateExtensionField,
        checkSyntax: checkSyntax,
        checkUnknownProperties: checkUnknownProperties,
        isPersonSuggestionCandidate: isPersonSuggestionCandidate,
        checkFormats: checkFormats,
        validatePostalCode: validatePostalCode,
        validateTelephone: validateTelephone,
        validateUrl: validateUrl,
        validateSortOrder: validateSortOrder,
        sanitizeSlugCandidateJs: sanitizeSlugCandidateJs,
        generateSlugSuggestionJs: generateSlugSuggestionJs,
        validateEmail: validateEmail,
        validateRequiredField: validateRequiredField,
        checkAddressRequiredField: checkAddressRequiredField,
        validateOpeningHoursTime: validateOpeningHoursTime,
        validateGeoLatitude: validateGeoLatitude,
        validateGeoLongitude: validateGeoLongitude,
        validateEventDateInput: validateEventDateInput,
        isEventDateInPast: isEventDateInPast,
        showExtensionFeedback: showExtensionFeedback,
        applyMessageParam: applyMessageParam,
        initScopeSelector: initScopeSelector,
        initTypeSwitcher: initTypeSwitcher,
        initExcludedCatsSelectAll: initExcludedCatsSelectAll,
        initPreviewDialogs: initPreviewDialogs,
        initDataActions: initDataActions,
        initPersonSlugLiveFill: initPersonSlugLiveFill,
        initAdminForm: initAdminForm
    };

    // Selbstauslösung: Das Skript startet das Admin-Formular selbst,
    // sobald das Dokument geladen ist - der Präsenz-Guard hält es von
    // jeder Seite fern, die den Formular-Container nicht führt.
    // Bewusst ausschließlich am Ereignis DOMContentLoaded und ohne
    // Auswertung von document.readyState: Ein readyState-Zweig würde
    // auch dann initialisieren, wenn das DOM beim Laden des Skripts
    // bereits steht. Das ist eine Verhaltensänderung gegenüber dem
    // Ereignis allein, und in Testumgebungen, die ihr Fixture vor dem
    // Skript aufbauen und danach selbst initialisieren, führte es zu
    // doppelt registrierten Listenern.
    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('.schemaOrgData-admin')) {
            initAdminForm();
        }
    });

})(window);
