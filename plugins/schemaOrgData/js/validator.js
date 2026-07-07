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

    /**
     * Liefert die lokalisierten Texte für die Feldvalidierung.
     * Werden von getConfig() als window.schemaOrgDataMessages
     * eingebettet (admin_language_{lang}.txt). Fallback: leere
     * Strings, falls das Skript ohne diese Variable geladen wird.
     *
     * @returns {object}
     */
    function getMessages() {
        return window.schemaOrgDataMessages || {};
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
     * Fehler (siehe index.php, validateUrl()).
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateUrl(value) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        try {
            new URL(value);
        } catch (e) {
            return { status: 'error', message: getMessages().urlInvalid || null };
        }

        if (value.indexOf('http://') === 0) {
            return { status: 'warning', message: getMessages().urlHttpWarning || null };
        }

        return { status: 'ok', message: null };
    }

    /**
     * Validiert eine E-Mail-Adresse (siehe index.php, validateEmail()).
     *
     * @param {string} value
     * @returns {{status: string|null, message: string|null}}
     */
    function validateEmail(value) {
        if (value.trim() === '') {
            return { status: null, message: null };
        }

        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
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
     * ISO-8601 (Datum bzw. Datum+Zeit+Sekunden+Offset/"Z") sowie zusätzlich
     * das deutsche Format "TT.MM.YYYY", optional mit Uhrzeit
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

        var isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})(?:T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:Z|[+-]\d{2}:\d{2}))?$/);
        if (isoMatch && isValidCalendarDate(parseInt(isoMatch[1], 10), parseInt(isoMatch[2], 10), parseInt(isoMatch[3], 10))) {
            return { status: 'ok', message: null };
        }

        var deMatch = trimmed.match(/^(\d{2})\.(\d{2})\.(\d{4})(?: ([01][0-9]|2[0-3]):([0-5][0-9]))?$/);
        if (deMatch && isValidCalendarDate(parseInt(deMatch[3], 10), parseInt(deMatch[2], 10), parseInt(deMatch[1], 10))) {
            return { status: 'ok', message: null };
        }

        return { status: 'error', message: getMessages().dateInvalid || null };
    }

    /**
     * Wandelt eine bereits als gültig bestätigte Datumseingabe
     * (validateEventDateInput()) in ein vergleichbares "Date"-Objekt um -
     * reine Hilfsfunktion für checkDateRange(), kein eigenständiger
     * Validierungsschritt. Ein reines Datum ohne Uhrzeit wird als lokale
     * Mitternacht interpretiert (symmetrisch zu normalizeEventDateInput() in
     * SchemaOrgData_Validator.php); mit Uhrzeit übernimmt der "Date"-
     * Konstruktor die Sommer-/Winterzeit-Auflösung der Browser-Zeitzone
     * automatisch.
     *
     * @param {string} value
     * @returns {Date|null}
     */
    function parseEventDateValue(value) {
        var trimmed = (value || '').trim();

        var deMatch = trimmed.match(/^(\d{2})\.(\d{2})\.(\d{4})(?: ([01][0-9]|2[0-3]):([0-5][0-9]))?$/);
        if (deMatch) {
            var day = parseInt(deMatch[1], 10);
            var month = parseInt(deMatch[2], 10) - 1;
            var year = parseInt(deMatch[3], 10);
            var hour = deMatch[4] ? parseInt(deMatch[4], 10) : 0;
            var minute = deMatch[5] ? parseInt(deMatch[5], 10) : 0;
            return new Date(year, month, day, hour, minute, 0);
        }

        var isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})(?:T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:Z|[+-]\d{2}:\d{2}))?$/);
        if (isoMatch) {
            if (!isoMatch[4]) {
                return new Date(parseInt(isoMatch[1], 10), parseInt(isoMatch[2], 10) - 1, parseInt(isoMatch[3], 10));
            }
            return new Date(trimmed);
        }

        return null;
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
     * Prüft, ob ein Zeitwert dem Format "HH:MM" entspricht
     * (24-Stunden-Format, siehe README.md "Öffnungszeiten").
     *
     * @param {string} value
     * @returns {boolean}
     */
    function isValidTimeFormat(value) {
        return /^[0-9]{2}:[0-9]{2}$/.test((value || '').trim());
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

        var pattern = /^[0-9]{2}:[0-9]{2}$/;

        if (!pattern.test(from) || !pattern.test(to)) {
            return { status: 'error', message: getMessages().openingHoursFormat || null };
        }

        if (from >= to) {
            return { status: 'error', message: getMessages().openingHoursOrder || null };
        }

        return { status: 'ok', message: null };
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
        feedback.className = 'schemaOrgData-feedback schemaOrgData-feedback--' + result.status;
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
            showFieldFeedback(input, input.id + '_feedback', validateEventDateInput(input.value), onlyClearErrors);
            return;
        }

        if (input !== counterpart.endInput) {
            showFieldFeedback(input, input.id + '_feedback', validateEventDateInput(input.value), onlyClearErrors);
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
     * unabhängig vom data-validate-Typ.
     *
     * @param {HTMLElement} input
     * @param {boolean} [onlyClearErrors] siehe showFieldFeedback() -
     *        true beim "input"-Event: zeigt keine neue Fehler-/
     *        Warnmeldung an, entfernt aber ein bereits sichtbares
     *        Feedback, sobald der Wert wieder gültig ist
     */
    function runFieldValidation(input, onlyClearErrors) {
        var type = input.getAttribute('data-validate');
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
            case 'required':
                result = validateRequiredField(input.value, input.getAttribute('data-required-message'));
                break;
            case 'opening_hours':
                runOpeningHoursValidation(input, onlyClearErrors);
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
        notice.textContent = template.replace('{PARAM1}', sectionLabel);
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
     */
    function showExtensionFeedback(feedback, result) {
        var html = '';

        if (result.syntaxError) {
            html = '<span class="schemaOrgData-feedback schemaOrgData-feedback--error">❌ ' + result.syntaxError + '</span>';
        } else {
            result.unknownProperties.forEach(function (property) {
                html += '<span class="schemaOrgData-feedback schemaOrgData-feedback--warning">⚠️ '
                    + (getMessages().unknownProperty || property).replace('{PARAM1}', property) + '</span>';
            });

            result.formatErrors.forEach(function (error) {
                html += '<span class="schemaOrgData-feedback schemaOrgData-feedback--error">❌ '
                    + (error.instancePath || '') + ' ' + error.message + '</span>';
            });

            if (html === '') {
                html = '<span class="schemaOrgData-feedback schemaOrgData-feedback--ok">✅</span>';
            }
        }

        feedback.innerHTML = html;
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
     */
    function initExtensionFieldValidation() {
        var textareas = document.querySelectorAll('.schemaOrgData-extension-field');

        for (var i = 0; i < textareas.length; i++) {
            (function (textarea) {
                var schema = null;
                var schemaUrl = textarea.getAttribute('data-schema-url');

                var validate = function () {
                    var feedback = document.getElementById(textarea.id + '_feedback');
                    if (feedback) {
                        showExtensionFeedback(feedback, validateExtensionField(textarea.value, schema));
                    }
                };

                if (schemaUrl) {
                    fetch(schemaUrl)
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            schema = data;
                            if (textarea.value.trim() !== '') {
                                validate();
                            }
                        })
                        .catch(function () { schema = null; });
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
     * Aktiviert den "Erkannten Block übernehmen"-Button
     * (.schemaOrgData-autofill-btn, siehe index.php
     * renderExistingJsonLdNotice()). Klick überträgt den Wert aus
     * dataset.existingContent per direkter DOM-Property-Zuweisung
     * in das Import-Textarea — kein AJAX, kein Auto-Save.
     */
    function initAutofillButton() {
        var buttons = document.querySelectorAll('.schemaOrgData-autofill-btn');

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (event) {
                var btn = event.currentTarget;
                var targetId = btn.getAttribute('data-target');
                var textarea = targetId ? document.getElementById(targetId) : null;
                if (textarea) {
                    textarea.value = btn.dataset.existingContent || '';
                }
            });
        }
    }

    /**
     * Initialisiert das gesamte Admin-Formular: Scope-Selektor,
     * Type-Umschaltung, Live-Validierung der Formularfelder sowie der
     * Erweiterungsfelder. Wird von renderAdminPage() nach
     * DOMContentLoaded aufgerufen.
     */
    function initAdminForm() {
        initScopeSelector();
        initTypeSwitcher();
        initFieldValidation();
        initExtensionFieldValidation();
        initExcludedCatsSelectAll();
        initAutofillButton();
    }

    // Öffentliche API
    window.schemaOrgDataValidator = {
        validateExtensionField: validateExtensionField,
        checkSyntax: checkSyntax,
        checkUnknownProperties: checkUnknownProperties,
        checkFormats: checkFormats,
        validatePostalCode: validatePostalCode,
        validateTelephone: validateTelephone,
        validateUrl: validateUrl,
        validateEmail: validateEmail,
        validateRequiredField: validateRequiredField,
        validateOpeningHoursTime: validateOpeningHoursTime,
        validateEventDateInput: validateEventDateInput,
        initExcludedCatsSelectAll: initExcludedCatsSelectAll,
        initAutofillButton: initAutofillButton,
        initAdminForm: initAdminForm
    };

})(window);
