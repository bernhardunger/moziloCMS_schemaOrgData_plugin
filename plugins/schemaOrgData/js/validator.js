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
     * @param {HTMLElement} anchor Element, nach dem ein neues
     *        Feedback-<span> eingefügt wird (insertAdjacentElement
     *        "afterend"), falls noch keines existiert
     * @param {string} feedbackId Element-ID des Feedback-<span>
     * @param {{status: string|null, message: string|null}} result
     */
    function showFieldFeedback(anchor, feedbackId, result) {
        var feedback = document.getElementById(feedbackId);

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
     * @param {HTMLElement} input das gerade geänderte Von- oder Bis-Feld
     */
    function runOpeningHoursValidation(input) {
        var pairInput = document.getElementById(input.getAttribute('data-pair'));
        var isFrom = input.id.endsWith('_from');
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

        var group = input.closest('.schemaOrgData-opening-hours-group');
        var feedbackId = (fromInput ? fromInput.id : input.id) + '_feedback';
        showFieldFeedback(group || input, feedbackId, result);
    }

    /**
     * Führt die zu input.dataset.validate passende Live-Validierung
     * aus und zeigt das Ergebnis an. Bei "opening_hours" wird das
     * über data-pair verknüpfte Gegenstück (Von/Bis) mit einbezogen
     * (siehe runOpeningHoursValidation()). Felder mit
     * data-required-message melden einen leeren Wert sofort als
     * Fehler, unabhängig vom data-validate-Typ.
     *
     * @param {HTMLElement} input
     */
    function runFieldValidation(input) {
        var type = input.getAttribute('data-validate');
        var requiredMessage = input.getAttribute('data-required-message');

        // Pflichtfeld leer: sofort melden, unabhängig vom sonstigen
        // Validierungstyp (url/email/telephone/required).
        if (requiredMessage && input.value.trim() === '') {
            showFieldFeedback(input, input.id + '_feedback', { status: 'error', message: requiredMessage });
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
                runOpeningHoursValidation(input);
                return;
            default:
                return;
        }

        showFieldFeedback(input, input.id + '_feedback', result);
    }

    /**
     * Aktiviert die Live-Validierung (bei "blur" und "input") für alle
     * Felder mit data-validate innerhalb des Admin-Formulars. Das
     * "input"-Event sorgt dafür, dass eine bereits angezeigte
     * Fehlermeldung sofort verschwindet, sobald der Wert während der
     * Eingabe wieder gültig wird (Fix 1) - ohne dass das Feld erst
     * verlassen werden muss. Bereits befüllte Felder werden zusätzlich
     * einmalig beim Laden der Seite validiert, damit ein ungültiger
     * gespeicherter bzw. nach gescheitertem Save zurückgegebener Wert
     * sofort als Fehler angezeigt wird, ohne dass der Nutzer das Feld
     * erst verlassen muss.
     */
    function initFieldValidation() {
        var inputs = document.querySelectorAll('[data-validate]');

        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('blur', function (event) {
                runFieldValidation(event.target);
            });
            inputs[i].addEventListener('input', function (event) {
                runFieldValidation(event.target);
            });

            if (inputs[i].value.trim() !== '') {
                runFieldValidation(inputs[i]);
            }
        }
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
        // aktualisiert die Hidden-Felder für den POST.
        function activateSection(cat, page) {
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
                }
            });

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
            hideSaveNotice();
            populatePageSelect(cat, '');
            activateSection(cat, '');
        });

        pageSelect.addEventListener('change', function () {
            hideSaveNotice();
            activateSection(catSelect.value, pageSelect.value);
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
        var activeSection = null;
        document.querySelectorAll('.schemaOrgData-scope').forEach(function (section) {
            if (activeSection === null && section.style.display !== 'none') {
                activeSection = section;
            }
        });
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
        initExcludedCatsSelectAll: initExcludedCatsSelectAll,
        initAdminForm: initAdminForm
    };

})(window);
