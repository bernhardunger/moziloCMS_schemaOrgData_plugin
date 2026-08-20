<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_Validator
*
* Serverseitige Feldvalidierung (Ergänzung zur clientseitigen
* AJV-Validierung) sowie Formular-/Schema-Validierung für das
* Plugin schemaOrgData (siehe README.md, Abschnitt
* "Formularvalidierung").
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_SchemaRepository)
* werden je Aufruf als Parameter übergeben, nicht im Konstruktor
* eingefroren.
*
***************************************************************/
class SchemaOrgData_Validator {

    /**
     * Bindung an das Widget-Vokabular aus
     * SchemaOrgData_SchemaRepository - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const WIDGET_TEXT                    = SchemaOrgData_SchemaRepository::WIDGET_TEXT;
    private const WIDGET_POSTAL_ADDRESS          = SchemaOrgData_SchemaRepository::WIDGET_POSTAL_ADDRESS;
    private const WIDGET_PLACE                   = SchemaOrgData_SchemaRepository::WIDGET_PLACE;
    private const WIDGET_OPENING_HOURS           = SchemaOrgData_SchemaRepository::WIDGET_OPENING_HOURS;
    private const WIDGET_GEO                     = SchemaOrgData_SchemaRepository::WIDGET_GEO;
    private const WIDGET_FAQ_LIST                = SchemaOrgData_SchemaRepository::WIDGET_FAQ_LIST;
    private const WIDGET_ID_REFERENCE            = SchemaOrgData_SchemaRepository::WIDGET_ID_REFERENCE;
    private const WIDGET_ID_REFERENCE_OR_LITERAL = SchemaOrgData_SchemaRepository::WIDGET_ID_REFERENCE_OR_LITERAL;
    /**
     * Bindung an die ui:-Schluesselnamen aus
     * SchemaOrgData_SchemaRepository - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const UI_WIDGET            = SchemaOrgData_SchemaRepository::UI_WIDGET;
    private const UI_LABEL             = SchemaOrgData_SchemaRepository::UI_LABEL;
    private const UI_REQUIRED          = SchemaOrgData_SchemaRepository::UI_REQUIRED;
    private const UI_ALLOW_LITERAL     = SchemaOrgData_SchemaRepository::UI_ALLOW_LITERAL;
    private const UI_LITERAL_FIELDS    = SchemaOrgData_SchemaRepository::UI_LITERAL_FIELDS;
    private const UI_REFERENCE_TARGETS = SchemaOrgData_SchemaRepository::UI_REFERENCE_TARGETS;
    /**
     * Muster der beiden Datumsformate. Beide stehen wortgleich in
     * js/validator.js - ein Wächtertest hält die Fassungen gegeneinander.
     * Die Zeitgruppen fangen ein, obwohl die reinen Prüfaufrufe sie nicht
     * lesen: so trägt ein Muster je Format die Prüfung und die Zerlegung.
     */
    private const SHARED_PATTERN_DATE_DE  = '/^(\d{2})\.(\d{2})\.(\d{4})(?: ([01][0-9]|2[0-3]):([0-5][0-9]))?$/';
    private const SHARED_PATTERN_DATE_ISO = '/^(\d{4})-(\d{2})-(\d{2})(?:T([01][0-9]|2[0-3]):([0-5][0-9]):[0-5][0-9](?:Z|[+-]\d{2}:\d{2}))?$/';

    /**
     * Muster der uebrigen Formatpruefungen. Alle stehen wortgleich in
     * js/validator.js - ein Waechtertest haelt die Fassungen gegeneinander
     * und laesst je Rumpf genau ein Vorkommen dort zu, damit keine zweite
     * Kopie entsteht, die er nicht sieht.
     *
     * Das Praefix SHARED_PATTERN_ ist dabei die Zusage: Wer eine Konstante so
     * benennt, sagt zu, dass es eine wortgleiche Gegenstelle in
     * js/validator.js gibt. Ein Muster ohne JS-Seite gehoert anders
     * benannt - der Waechter sammelt ueber genau dieses Praefix.
     *
     * SHARED_PATTERN_URL_SCHEME ist als einzige oeffentlich, weil
     * SchemaOrgData_PersonsRegistryService sie bindet; die uebrigen kennt
     * nur diese Klasse. Der Waechter liest sie per Reflection und braucht
     * dafuer keine Sichtbarkeit.
     */
    private const SHARED_PATTERN_POSTAL_CODE = '/^[0-9]{5}$/';
    private const SHARED_PATTERN_PHONE_STRIP = '/[^0-9+]/';
    private const SHARED_PATTERN_PHONE       = '/^(\+|00)[1-9][0-9]{6,14}$/';
    public  const SHARED_PATTERN_URL_SCHEME  = '#^https?://#i';
    private const SHARED_PATTERN_TIME        = '/^[0-9]{2}:[0-9]{2}$/';

    /**
     * Statuswerte einer Feldpruefung. Sie reisen als Wert in
     * ['status'] und landen ungeprueft in einem CSS-Klassennamen
     * (SchemaOrgData_FormRenderer::SHARED_CLASS_FEEDBACK); ein Vertipper
     * erzeugt eine Klasse ohne Regel, die Meldung erscheint in
     * Standardfarbe statt rot - sichtbar nur im Browser.
     *
     * js/validator.js fuehrt dieselben drei Werte und einen vierten,
     * 'info', den nur die AJV-Meldung dort erzeugt. Er ist deshalb kein
     * geteilter Wert und steht nicht unter diesem Praefix; der Waechter
     * kennt ihn als Ausnahme.
     */
    public const SHARED_STATUS_OK      = 'ok';
    public const SHARED_STATUS_WARNING = 'warning';
    public const SHARED_STATUS_ERROR   = 'error';

    /***************************************************************
    *
    * Validiert Formulardaten serverseitig gegen ein JSON-Schema.
    *
    * Prüft, ob alle in "required" gelisteten Properties vorhanden
    * und nicht leer sind, sowie ob alle Properties in "properties"
    * bekannt sind. Dient als serverseitige Ergänzung zur
    * clientseitigen AJV-Validierung; unbekannte Properties führen
    * nur zu einer Warnung, kein Speichern wird dadurch verhindert.
    *
    * @param array<string, mixed> $data zu prüfende Properties (Formularfeld-Werte)
    * @param array<string, mixed>|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @param SchemaOrgData_SchemaRepository $schemaRepository für resolveSchemaRef()
    * @return array{errors: string[], warnings: string[]}
    *
    ***************************************************************/
    public function validateAgainstSchema(array $data, ?array $schema, SchemaOrgData_SchemaRepository $schemaRepository): array {
        $errors = [];
        $warnings = [];

        if($schema === null) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        foreach($schema['required'] ?? [] as $requiredProperty) {
            // id_reference wird zur Build-Zeit automatisch emittiert,
            // id_reference_or_literal verwaltet eigene Pflichtprüfung in validateFormData().
            $propSchema = $schemaRepository->resolveSchemaRef($schema['properties'][$requiredProperty] ?? [], $schema);
            $widget = $propSchema[self::UI_WIDGET] ?? '';
            if($widget === self::WIDGET_ID_REFERENCE or $widget === self::WIDGET_ID_REFERENCE_OR_LITERAL) {
                continue;
            }

            $value = $data[$requiredProperty] ?? null;
            if($value === null or $value === '' or $value === []) {
                $errors[] = $requiredProperty;
            }
        }

        $knownProperties = array_keys($schema['properties'] ?? []);
        foreach(array_keys($data) as $property) {
            if(!in_array($property, $knownProperties, true)) {
                $warnings[] = $property;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /***************************************************************
    *
    * Validiert die Formularfelder eines Schema-Types serverseitig
    * (Ergänzung zur clientseitigen Live-Validierung): prüft
    * Pflichtfelder ("ui:required", inkl. PostalAddress und
    * mainEntity) sowie die Feldvalidatoren validatePostalCode,
    * validateTelephone, validateUrl, validateEmail und
    * validateOpeningHoursTime.
    *
    * Ist ein Pflichtfeld leer, aber ein geerbter Wert von einer
    * übergeordneten Ebene vorhanden ($inheritable['data']), wird
    * kein Pflichtfeld-Fehler erzeugt - der geerbte Wert deckt das
    * Pflichtfeld ab (analog clientseitigem Placeholder-Check).
    *
    * @param array<string, mixed> $formData Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array<string, mixed> $schema aktives JSON-Schema (schemas/{Type}.json)
    * @param array{data: array<string, mixed>, originLabel: array<string, mixed>} $inheritable
    *              Rückgabe von resolveInheritableFields()
    * @param Language $lang für Fehlermeldungen und Feld-Labels
    * @param SchemaOrgData_SchemaRepository $schemaRepository für resolveSchemaRef()
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validateFormData(array $formData, array $schema, array $inheritable, Language $lang, SchemaOrgData_SchemaRepository $schemaRepository): array {
        $inheritableData = $inheritable['data'] ?? [];
        $errors = [];

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $fieldSchema = $schemaRepository->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema[self::UI_WIDGET] ?? self::WIDGET_TEXT;
            $required = (bool) ($fieldSchema[self::UI_REQUIRED] ?? false);
            $label = $lang->getLanguageValue($fieldSchema[self::UI_LABEL] ?? $name);
            $value = $formData[$name] ?? null;

            if($widget === self::WIDGET_POSTAL_ADDRESS) {
                $inheritableAddress = is_array($inheritableData[$name] ?? null) ? $inheritableData[$name] : [];
                $errors = array_merge($errors, $this->validatePostalAddressData(
                    is_array($value) ? $value : [], $fieldSchema, $inheritableAddress, $lang
                ));
                continue;
            }

            if($widget === self::WIDGET_PLACE) {
                // Wiederverwendung von validatePostalAddressData() für die
                // verschachtelte Adresse - keine eigene Validierungslogik.
                $properties = $fieldSchema['properties'] ?? [];
                $addressSchema = isset($properties['address']) ? $schemaRepository->resolveSchemaRef($properties['address'], $schema) : [];
                $placeValue = is_array($value) ? $value : [];
                $addressValue = is_array($placeValue['address'] ?? null) ? $placeValue['address'] : [];
                $inheritedPlace = is_array($inheritableData[$name] ?? null) ? $inheritableData[$name] : [];
                $inheritedAddress = is_array($inheritedPlace['address'] ?? null) ? $inheritedPlace['address'] : [];

                // Das Geschwister-Feld "name" liegt außerhalb von $addressValue und
                // würde von validatePostalAddressData() sonst nicht als "Widget
                // wurde angefasst" erkannt - ebenso das eigene "ui:required" des
                // gesamten place-Widgets (z. B. JobPosting.jobLocation), das ohne
                // $forceRequired komplett wirkungslos bliebe, weil
                // validatePostalAddressData() nur $addressValue kennt.
                $placeNameFilled = trim((string) ($placeValue['name'] ?? '')) !== '';
                $errors = array_merge($errors, $this->validatePostalAddressData(
                    $addressValue, $addressSchema, $inheritedAddress, $lang, $required or $placeNameFilled
                ));
                continue;
            }

            if($widget === self::WIDGET_OPENING_HOURS) {
                $days = SchemaOrgData_OpeningHoursHelper::resolveDays($fieldSchema);
                $perDay = is_array($value) ? $value : [];
                foreach($days as $day) {
                    $from  = (string) ($perDay[$day]['from']  ?? '');
                    $to    = (string) ($perDay[$day]['to']    ?? '');
                    $from2 = (string) ($perDay[$day]['from2'] ?? '');
                    $to2   = (string) ($perDay[$day]['to2']   ?? '');

                    $result = $this->validateOpeningHoursTime($from, $to, $lang);
                    if($result['status'] === self::SHARED_STATUS_ERROR) {
                        $errors[] = $result['message'];
                    }

                    $result2 = $this->validateOpeningHoursTime($from2, $to2, $lang);
                    if($result2['status'] === self::SHARED_STATUS_ERROR) {
                        $errors[] = $result2['message'];
                    }

                    // Zweiter Zeitraum darf nicht vor Ende des ersten beginnen
                    if($from2 !== '' && $to2 !== '' && $to !== '' && $from2 < $to) {
                        $errors[] = $lang->getLanguageValue('error_opening_hours_overlap');
                    }
                }
                continue;
            }

            if($widget === self::WIDGET_GEO) {
                $errors = array_merge($errors, $this->validateGeoPair(is_array($value) ? $value : [], $lang));
                continue;
            }

            if($widget === self::WIDGET_FAQ_LIST) {
                if($required and !$this->hasFaqEntry(is_array($value) ? $value : [])) {
                    // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                    // Ebene ein nicht-leeres FAQ-Array geerbt wird.
                    $inheritedList = $inheritableData[$name] ?? null;
                    if(!is_array($inheritedList) or !$this->hasFaqEntry($inheritedList)) {
                        $errors[] = $lang->getLanguageValue('error_required_field', $label);
                    }
                }
                continue;
            }

            if($widget === self::WIDGET_ID_REFERENCE) {
                // Wird zur Build-Zeit automatisch emittiert (buildJsonLdScript()) -
                // schreibgeschützte Info-Anzeige ohne POST-Wert per Design
                // (siehe renderField()), analog zum Skip in validateAgainstSchema().
                continue;
            }

            if($widget === self::WIDGET_ID_REFERENCE_OR_LITERAL) {
                $stored = is_array($value) ? $value : [];
                $mode = (string) ($stored['_mode'] ?? 'reference');

                // Modus-/Zieleinschränkung (ui:allowLiteral/ui:referenceTargets,
                // siehe SchemaOrgData_IdReferenceService): ein POST-Wert
                // außerhalb der Feldkonfiguration wird abgelehnt statt
                // stillschweigend auf den erlaubten Zustand umgedeutet - unabhängig
                // von $required, da sonst ein optionales Feld die Einschränkung
                // umgehen könnte.
                if($mode === 'literal' and !($fieldSchema[self::UI_ALLOW_LITERAL] ?? true)) {
                    $errors[] = $lang->getLanguageValue('error_id_reflit_restricted', $label);
                } elseif($mode === 'reference') {
                    $fragment = trim((string) ($stored['_fragment'] ?? ''));
                    $referenceTargets = $fieldSchema[self::UI_REFERENCE_TARGETS] ?? null;
                    if($fragment !== '' and is_array($referenceTargets)
                        and !SchemaOrgData_IdReferenceService::isFragmentAllowedForReferenceTargets($fragment, $referenceTargets)) {
                        $errors[] = $lang->getLanguageValue('error_id_reflit_restricted', $label);
                    }
                }

                if($required) {
                    if($mode === 'reference') {
                        $fragment = trim((string) ($stored['_fragment'] ?? ''));
                        if($fragment === '') {
                            $errors[] = $lang->getLanguageValue('error_required_field', $label);
                        }
                    } elseif($mode === 'literal') {
                        $hasValue = false;
                        foreach($fieldSchema[self::UI_LITERAL_FIELDS] ?? [] as $lf) {
                            if(trim((string) ($stored[(string) $lf] ?? '')) !== '') {
                                $hasValue = true;
                                break;
                            }
                        }
                        if(!$hasValue) {
                            $errors[] = $lang->getLanguageValue('error_required_field', $label);
                        }
                    }
                }
                continue;
            }

            $stringValue = trim((string) ($value ?? ''));

            if($stringValue === '') {
                if($required) {
                    // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                    // Ebene ein nicht-leerer Wert geerbt wird.
                    $inheritedValue = $inheritableData[$name] ?? null;
                    if(!is_scalar($inheritedValue) or (string) $inheritedValue === '') {
                        $errors[] = $lang->getLanguageValue('error_required_field', $label);
                    }
                }
                continue;
            }

            $format = $fieldSchema['format'] ?? null;
            $enum = $fieldSchema['enum'] ?? null;

            // Enum-Zugehörigkeit prüfen: ohne diese Prüfung würde ein nicht mehr
            // zur enum-Liste passender gespeicherter Wert (z. B. nach einer
            // Enum-Wertänderung im Schema) in renderSelectWidget() kein <option>
            // als "selected" markieren; der Browser zeigt dann optisch die erste
            // Option an, ein nachfolgendes Speichern würde diesen Wert
            // stillschweigend übernehmen. Siehe README.md, Abschnitt
            // "Formularvalidierung".
            $result = match(true) {
                $format === 'uri'       => $this->validateUrl($stringValue, $lang),
                $format === 'email'     => $this->validateEmail($stringValue, $lang),
                $format === 'date-time' => $this->validateEventDateInput($stringValue, $lang),
                $name === 'telephone'   => $this->validateTelephone($stringValue, (string) ($formData['address']['addressCountry'] ?? 'DE'), $lang),
                is_array($enum) and !in_array($stringValue, $enum, true)
                    => ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_invalid_format', $label)],
                default                 => ['status' => null, 'message' => null],
            };

            if($result['status'] === self::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        // Bereichsprüfung für die bekannten Start-/End-Datumsfeldpaare
        // (Event startDate/endDate, JobPosting datePosted/validThrough) -
        // siehe validateDateRange(). Andere Schema-Types besitzen diese
        // Felder nicht, das Feldpaar bleibt dann leer und die Prüfung ist
        // ein No-op.
        foreach([['startDate', 'endDate'], ['datePosted', 'validThrough']] as [$startField, $endField]) {
            $rangeError = $this->validateDateRange($startField, $endField, 'error_date_range_invalid', $formData, $lang);
            if($rangeError !== null) {
                $errors[] = $rangeError;
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Prüft, dass das Ende eines Datumsbereichs nicht vor dessen Beginn
    * liegt (z. B. Event startDate/endDate, JobPosting
    * datePosted/validThrough) - nur wenn beide Felder gültige Werte im
    * deutschen Format enthalten (siehe validateEventDateInput(); ein
    * bereits gemeldeter Formatfehler wird nicht durch einen
    * zusätzlichen Bereichsfehler verdoppelt). Vor dem Vergleich wird auf
    * ISO normalisiert (normalizeEventDateInput()), da DateTimeImmutable
    * ein deutsches Rohformat nicht korrekt parst. Vergleich über
    * Unix-Timestamps statt lexikalisch, damit ein reines Datum (lokale
    * Mitternacht) und ein Datum mit Uhrzeit korrekt geordnet werden.
    *
    * @param string $errorKey Sprachschlüssel für die Bereichsfehlermeldung
    * @param array<string, mixed> $formData Formularfeld-Werte
    * @param Language $lang für die Fehlermeldung
    * @return string|null Fehlermeldung oder null (kein Fehler bzw. nicht prüfbar)
    *
    ***************************************************************/
    private function validateDateRange(string $startField, string $endField, string $errorKey, array $formData, Language $lang): ?string {
        $startValue = trim((string) ($formData[$startField] ?? ''));
        $endValue = trim((string) ($formData[$endField] ?? ''));

        if($startValue === '' or $endValue === ''
            or $this->validateEventDateInput($startValue, $lang)['status'] === self::SHARED_STATUS_ERROR
            or $this->validateEventDateInput($endValue, $lang)['status'] === self::SHARED_STATUS_ERROR) {
            return null;
        }

        $startTimestamp = (new DateTimeImmutable($this->normalizeEventDateInput($startValue)))->getTimestamp();
        $endTimestamp = (new DateTimeImmutable($this->normalizeEventDateInput($endValue)))->getTimestamp();

        return ($endTimestamp < $startTimestamp) ? $lang->getLanguageValue($errorKey) : null;
    }

    /***************************************************************
    *
    * Validiert eine Postleitzahl. Nur für addressCountry = "DE"
    * relevant (siehe README.md, Abschnitt "Formularvalidierung").
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *   status: null (nicht geprüft), 'ok', 'error'
    *
    ***************************************************************/
    public function validatePostalCode(string $value, string $countryCode, Language $lang): array {
        if($countryCode !== 'DE' or trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(preg_match(self::SHARED_PATTERN_POSTAL_CODE, $value)) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_postal_code_format')];
    }

    /***************************************************************
    *
    * Validiert eine Telefonnummer (E.164, alle Länder). Die
    * Eingabe wird zunächst normalisiert
    * (preg_replace() gegen SHARED_PATTERN_PHONE_STRIP) und dann gegen ein
    * vereinfachtes E.164-Format geprüft.
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateTelephone(string $value, string $countryCode, Language $lang): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        $normalized = preg_replace(self::SHARED_PATTERN_PHONE_STRIP, '', $value);

        if(preg_match(self::SHARED_PATTERN_PHONE, $normalized)) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_telephone_format')];
    }

    /***************************************************************
    *
    * Validiert eine URL. "http://" ergibt eine Warnung (HTTPS
    * empfohlen), "https://" ist OK, eine ungültige URL ist ein
    * Fehler.
    *
    * @param Language $lang für Fehler-/Warnmeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateUrl(string $value, Language $lang): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        // FILTER_VALIDATE_URL prüft nur allgemeine URI-Syntax, kein
        // konkretes Schema - "htto://..." oder "htxxxs://..." würden sonst
        // fälschlich als gültig durchgehen.
        if(filter_var($value, FILTER_VALIDATE_URL) === false or !preg_match(self::SHARED_PATTERN_URL_SCHEME, $value)) {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_url_invalid')];
        }

        if(str_starts_with($value, 'http://')) {
            return ['status' => self::SHARED_STATUS_WARNING, 'message' => $lang->getLanguageValue('warning_url_http')];
        }

        return ['status' => self::SHARED_STATUS_OK, 'message' => null];
    }

    /***************************************************************
    *
    * Validiert eine E-Mail-Adresse via FILTER_VALIDATE_EMAIL.
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateEmail(string $value, Language $lang): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_email_invalid')];
    }

    /***************************************************************
    *
    * Validiert ein Von/Bis-Zeitpaar des Öffnungszeiten-Widgets.
    * Ausschließlich 24-Stunden-Format (HH:MM), Von muss vor Bis
    * liegen. Sind beide Felder leer, gilt der Tag als
    * "geschlossen" und wird nicht geprüft.
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateOpeningHoursTime(string $from, string $to, Language $lang): array {
        $from = trim($from);
        $to = trim($to);

        if($from === '' and $to === '') {
            return ['status' => null, 'message' => null];
        }

        if(($from === '') !== ($to === '')) {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_opening_hours_incomplete')];
        }

        if(!preg_match(self::SHARED_PATTERN_TIME, $from) or !preg_match(self::SHARED_PATTERN_TIME, $to)) {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_opening_hours_format')];
        }

        if($from >= $to) {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_opening_hours_order')];
        }

        return ['status' => self::SHARED_STATUS_OK, 'message' => null];
    }

    /***************************************************************
    *
    * Validiert ein ISO-8601-Datum (Event.startDate/endDate):
    * entweder reines Datum ("YYYY-MM-DD") oder Datum+Zeit+Zeitzone
    * ("YYYY-MM-DDTHH:MM:SS±HH:MM" bzw. "...Z"). Ausschließlich
    * 24-Stunden-Format, kein TT.MM.YYYY (siehe README.md,
    * Abschnitt "Formularvalidierung"). Kalendarische Gültigkeit
    * (z. B. 31. Februar) wird zusätzlich per checkdate() geprüft.
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateIso8601Date(string $value, Language $lang): array {
        $value = trim($value);
        if($value === '') {
            return ['status' => null, 'message' => null];
        }

        if(preg_match(self::SHARED_PATTERN_DATE_ISO, $value, $m)
            and checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_date_invalid')];
    }

    /***************************************************************
    *
    * Validiert eine Datumseingabe für Event.startDate/endDate:
    * akzeptiert ausschließlich das deutsche Format "TT.MM.YYYY",
    * optional mit Uhrzeit ("TT.MM.YYYY HH:MM"). validateIso8601Date()
    * bleibt als eigenständige, unabhängig nutzbare Methode bestehen,
    * wird von dieser Methode aber nicht mehr aufgerufen - die
    * Formularvalidierung von Event.startDate/endDate akzeptiert damit
    * keine ISO-8601-Eingabe mehr (Persistenz/Ausgabe bleiben
    * unverändert ISO-8601, siehe normalizeEventDateInput()).
    *
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateEventDateInput(string $value, Language $lang): array {
        $value = trim($value);
        if($value === '') {
            return ['status' => null, 'message' => null];
        }

        if(preg_match(self::SHARED_PATTERN_DATE_DE, $value, $m)
            and checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_date_invalid')];
    }

    /***************************************************************
    *
    * Normalisiert eine bereits als gültig bestätigte Datumseingabe
    * (validateEventDateInput()) auf ISO-8601. Reine, zustandslose
    * String-Transformation ohne eigene Fehlerprüfung - wird nur auf
    * Werte angewendet, die validateEventDateInput() bereits
    * akzeptiert hat.
    *
    * Bereits gültiges ISO bleibt unverändert. Deutsches Datum ohne
    * Uhrzeit wird zu reinem ISO-Datum ("YYYY-MM-DD") ohne Offset
    * normalisiert (symmetrisch zum bestehenden Verhalten, dass auch
    * ein reines ISO-Datum ohne Zeit/Offset gültig ist). Deutsches
    * Datum mit Uhrzeit wird zu "YYYY-MM-DDTHH:MM:SS+HH:MM"
    * normalisiert, wobei der Offset aus der PHP-Serverzeitzone für
    * das konkrete Datum aufgelöst wird (behandelt Sommer-/Winterzeit
    * korrekt).
    *
    ***************************************************************/
    public function normalizeEventDateInput(string $value): string {
        $value = trim($value);

        if(preg_match(self::SHARED_PATTERN_DATE_DE, $value, $m)) {
            $isoDate = $m[3] . '-' . $m[2] . '-' . $m[1];

            if(!isset($m[4])) {
                return $isoDate;
            }

            $timezone = new DateTimeZone(date_default_timezone_get());
            $dateTime = new DateTimeImmutable($isoDate . ' ' . $m[4] . ':' . $m[5] . ':00', $timezone);
            return $dateTime->format('Y-m-d\TH:i:sP');
        }

        return $value;
    }

    /***************************************************************
    *
    * Formatiert einen gespeicherten ISO-8601-Wert (Event.startDate/
    * endDate) für die Anzeige im Formular als deutsches Datum
    * "TT.MM.YYYY" bzw. "TT.MM.YYYY HH:MM" - symmetrisches Gegenstück
    * zu normalizeEventDateInput(). Sekunden und Offset werden für
    * die Anzeige verworfen: beim erneuten Speichern löst
    * normalizeEventDateInput() den Offset ohnehin aus der aktuellen
    * Serverzeitzone neu auf (bewusste Vereinfachung, kein Bug -
    * betrifft nur ein manuell mit abweichendem Offset gespeichertes
    * Datum). Nicht als ISO erkannte Werte werden unverändert
    * zurückgegeben (defensiv, seit normalizeEventDateInput() wird
    * ausschließlich ISO gespeichert).
    *
    ***************************************************************/
    public function formatEventDateForDisplay(string $isoValue): string {
        $isoValue = trim($isoValue);

        if(preg_match(self::SHARED_PATTERN_DATE_ISO, $isoValue, $m)) {
            $germanDate = $m[3] . '.' . $m[2] . '.' . $m[1];

            if(!isset($m[4])) {
                return $germanDate;
            }

            return $germanDate . ' ' . $m[4] . ':' . $m[5];
        }

        return $isoValue;
    }

    /***************************************************************
    *
    * Validiert eine Geo-Koordinate (latitude/longitude) im
    * Erweiterungsfeld: muss numerisch sein und im gültigen
    * Wertebereich liegen.
    *
    * @param string $value zu prüfender Wert
    * @param float $min    unterer Grenzwert (inklusive)
    * @param float $max    oberer Grenzwert (inklusive)
    * @param string $errorKey Sprachschlüssel für die Fehlermeldung
    * @param Language $lang für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validateGeoCoordinate(string $value, float $min, float $max, string $errorKey, Language $lang): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(!is_numeric($value) or (float) $value < $min or (float) $value > $max) {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue($errorKey)];
        }

        return ['status' => self::SHARED_STATUS_OK, 'message' => null];
    }

    /** Validiert geo.latitude (-90 .. 90), siehe validateGeoCoordinate(). */
    public function validateGeoLatitude(string $value, Language $lang): array {
        return $this->validateGeoCoordinate($value, -90, 90, 'error_geo_latitude', $lang);
    }

    /** Validiert geo.longitude (-180 .. 180), siehe validateGeoCoordinate(). */
    public function validateGeoLongitude(string $value, Language $lang): array {
        return $this->validateGeoCoordinate($value, -180, 180, 'error_geo_longitude', $lang);
    }

    /***************************************************************
    *
    * Validiert das geo-Widget-Feldpaar (latitude/longitude, siehe
    * SchemaOrgData_FormRenderer::renderGeoWidget()): Paar-Pflicht
    * "beides oder nichts" - sind beide Felder leer, ist geo insgesamt
    * nicht angegeben (kein Fehler, wird von removeEmptyJsonLdProperties()
    * ohnehin aus der Ausgabe entfernt). Ist nur eines der beiden Felder
    * gefüllt, ist das jeweils andere ein Pflichtfeld-Fehler
    * (error_geo_incomplete). Sind beide gefüllt, wird jedes einzeln
    * gegen seinen Wertebereich geprüft (validateGeoLatitude/
    * validateGeoLongitude - dieselben Methoden wie für das
    * Erweiterungsfeld, siehe validateExtensionGeo()).
    *
    * @param array<string, mixed> $geo latitude/longitude-Werte des Formularfelds
    * @param Language $lang für die Fehlermeldungen
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validateGeoPair(array $geo, Language $lang): array {
        $errors = [];
        $latValue = trim((string) ($geo['latitude'] ?? ''));
        $lonValue = trim((string) ($geo['longitude'] ?? ''));

        if($latValue === '' and $lonValue === '') {
            return $errors;
        }

        if($latValue === '' or $lonValue === '') {
            $errors[] = $lang->getLanguageValue('error_geo_incomplete');
            return $errors;
        }

        $latResult = $this->validateGeoLatitude($latValue, $lang);
        if($latResult['status'] === self::SHARED_STATUS_ERROR) {
            $errors[] = $latResult['message'];
        }

        $lonResult = $this->validateGeoLongitude($lonValue, $lang);
        if($lonResult['status'] === self::SHARED_STATUS_ERROR) {
            $errors[] = $lonResult['message'];
        }

        return $errors;
    }

    /***************************************************************
    *
    * Validiert geo.latitude/geo.longitude im Erweiterungsfeld
    * (siehe validateGeoLatitude/validateGeoLongitude). Andere
    * Properties des Erweiterungsfelds werden serverseitig nicht
    * geprüft (siehe README.md, Abschnitt "Erweiterungsfeld").
    *
    * @param array<string, mixed> $extensionData dekodierte Erweiterungsfeld-Daten
    * @param Language $lang für die Fehlermeldungen
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validateExtensionGeo(array $extensionData, Language $lang): array {
        $errors = [];
        $geo = $extensionData['geo'] ?? null;

        if(!is_array($geo)) {
            return $errors;
        }

        if(isset($geo['latitude'])) {
            $result = $this->validateGeoLatitude((string) $geo['latitude'], $lang);
            if($result['status'] === self::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        if(isset($geo['longitude'])) {
            $result = $this->validateGeoLongitude((string) $geo['longitude'], $lang);
            if($result['status'] === self::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Prüft, ob für eine PostalAddress Werte übermittelt wurden:
    * mindestens ein Feld ohne "default" (also nicht addressCountry,
    * das standardmäßig "DE" enthält) ist nicht leer. Wird beim
    * Bereinigen (sanitizeAddressData) verwendet, um keine Adresse zu
    * speichern, die nur den Default-Wert von addressCountry enthält.
    *
    ***************************************************************/
    public function isAddressProvided(array $address, array $subProperties): bool {
        foreach($subProperties as $subName => $subSchema) {
            $subValue = trim((string) ($address[$subName] ?? ''));
            if($subValue !== '' and !array_key_exists('default', $subSchema)) {
                return true;
            }
        }

        return false;
    }

    /***************************************************************
    *
    * Validiert die Pflichtfelder und das Format einer PostalAddress
    * (siehe resolveSchemaRef/renderPostalAddressWidget).
    *
    * Die als "ui:required" markierten Unter-Properties (z. B.
    * addressLocality, addressCountry) werden grundsätzlich geprüft -
    * auch dann, wenn der Nutzer kein einziges Adressfeld ausgefüllt
    * hat. Andernfalls würde die voreingestellte Länder-Select-Box
    * (default "DE") eine leere Adresse als "nicht angegeben"
    * erscheinen lassen und der Pflichtfeld-Check für "Ort" beim
    * Speichern (z. B. auf Globalebene) nicht greifen.
    *
    * Ist ein Pflichtfeld leer, aber ein geerbter Wert aus einer
    * übergeordneten Ebene vorhanden ($inheritableAddress), entfällt
    * der Fehler - der geerbte Wert deckt das Pflichtfeld ab.
    *
    * @param array<string, mixed> $inheritableAddress Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (Rückgabe von
    *        resolveInheritableFields()['data']['address'])
    * @param Language $lang für die Fehlermeldungen
    * @param bool $forceRequired erzwingt die Pflichtfeld-Prüfungen auch dann, wenn
    *        innerhalb von $address selbst kein Feld ausgefüllt ist - für das
    *        place-Widget (Event.location/JobPosting.jobLocation), dessen
    *        "ist überhaupt etwas angegeben"-Zustand zusätzlich vom
    *        Geschwister-Feld "name" abhängt bzw. vom eigenen "ui:required" des
    *        gesamten Widgets (siehe validateFormData())
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validatePostalAddressData(array $address, array $fieldSchema, array $inheritableAddress, Language $lang, bool $forceRequired = false): array {
        $errors = [];
        $subProperties = $fieldSchema['properties'] ?? [];

        // Wurde kein Adressfeld ausgefüllt (nur Default-Werte wie addressCountry=DE),
        // entfallen alle Pflichtfeld-Prüfungen — die Adresse als Ganzes ist nicht required.
        if(!$forceRequired and !$this->isAddressProvided($address, $subProperties)) {
            return [];
        }

        foreach($subProperties as $subName => $subSchema) {
            $subRequired = (bool) ($subSchema[self::UI_REQUIRED] ?? false);
            $subValue = trim((string) ($address[$subName] ?? ''));

            if($subRequired and $subValue === '') {
                // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                // Ebene ein nicht-leerer Wert für dieses Sub-Feld geerbt wird.
                $inheritedSubValue = trim((string) ($inheritableAddress[$subName] ?? ''));
                if($inheritedSubValue === '') {
                    $subLabel = $lang->getLanguageValue($subSchema[self::UI_LABEL] ?? $subName);
                    $errors[] = $lang->getLanguageValue('error_required_field', $subLabel);
                }
            }

            // Enum-Zugehörigkeit (z. B. addressCountry), additiv analog zu
            // validateFormData() - siehe README.md, Abschnitt "Formularvalidierung".
            $subEnum = $subSchema['enum'] ?? null;
            if($subValue !== '' and is_array($subEnum) and !in_array($subValue, $subEnum, true)) {
                $subLabel = $lang->getLanguageValue($subSchema[self::UI_LABEL] ?? $subName);
                $errors[] = $lang->getLanguageValue('error_invalid_format', $subLabel);
            }
        }

        $postalCode = trim((string) ($address['postalCode'] ?? ''));
        if($postalCode !== '') {
            $countryCode = (string) ($address['addressCountry'] ?? 'DE');
            $result = $this->validatePostalCode($postalCode, $countryCode, $lang);
            if($result['status'] === self::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Validiert eine Liste von sameAs-URLs (Personen-Registry,
    * je Zeile eine URL) über validateUrl(). Nur Fehler werden
    * gesammelt - eine HTTP-Warnung blockiert das Speichern nicht
    * und wird hier bewusst nicht zurückgegeben (analog zu den
    * übrigen Formularfeldern, deren Warnungen nur clientseitig/beim
    * Redisplay angezeigt werden).
    *
    * @param string[] $urls bereits von Leerzeilen bereinigte URLs
    * @param Language $lang für die Fehlermeldungen
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validateSameAsEntries(array $urls, Language $lang): array {
        $errors = [];

        foreach($urls as $url) {
            $result = $this->validateUrl((string) $url, $lang);
            if($result['status'] === self::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Prüft, ob eine bereits sanitierte relative Medienpfad-Angabe
    * (Personen-Registry, Feld "image") unterhalb des moziloCMS-
    * Medienverzeichnisses existiert. Nicht blockierend - eine fehlende
    * Datei ergibt nur eine Warnung, da die Datei nachträglich
    * hochgeladen werden kann. Absolute URLs (http(s)://) werden hier
    * nicht behandelt - dafür validateUrl() verwenden.
    *
    * @param string $sanitizedRelativePath bereits sanitierter Pfad (siehe
    *        SchemaOrgData_PersonsRegistryService::sanitizeRelativeMediaPath())
    * @param string $mediaBaseDir absolutes Basisverzeichnis (siehe
    *        SchemaOrgData_UrlHelper::resolveMediaBaseDir())
    * @param Language $lang für die Warnmeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function validatePersonImageExistence(string $sanitizedRelativePath, string $mediaBaseDir, Language $lang): array {
        if($sanitizedRelativePath === '' or $mediaBaseDir === '') {
            return ['status' => null, 'message' => null];
        }

        $fullPath = rtrim($mediaBaseDir, '/').'/'.$sanitizedRelativePath;

        if(is_file($fullPath)) {
            return ['status' => self::SHARED_STATUS_OK, 'message' => null];
        }

        return ['status' => self::SHARED_STATUS_WARNING, 'message' => $lang->getLanguageValue('warning_person_image_not_found')];
    }

    /***************************************************************
    *
    * Prüft, ob mindestens ein FAQ-Eintrag mit Frage UND Antwort
    * vorhanden ist. Einträge ohne beides werden von
    * sanitizePostData() verworfen (siehe renderFaqListWidget, das
    * stets eine zusätzliche leere Zeile zum Anlegen anzeigt).
    *
    ***************************************************************/
    public function hasFaqEntry(array $entries): bool {
        foreach($entries as $entry) {
            $question = trim((string) ($entry['name'] ?? ''));
            $answer = trim((string) ($entry['acceptedAnswer']['text'] ?? ''));

            if($question !== '' and $answer !== '') {
                return true;
            }
        }

        return false;
    }
}
