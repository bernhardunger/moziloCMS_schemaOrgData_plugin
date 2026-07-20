<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_ConfigSaveService
*
* Save-Flow-Pipeline des Admin-Formulars: bündelt die
* vererbungsbewusste Feld-Auflösung (resolveInheritableFields()),
* die POST-Bereinigung (sanitizePostData(), sanitizeAddressData())
* sowie Validieren/Speichern (saveConfig()) - vorher auf
* SchemaOrgData_AdminController, dessen saveConfig()-Rückreferenz
* aus SchemaOrgData_AdminRequestHandler damit aufgelöst ist.
* saveConfig() macht die Vier-Phasen-Struktur (Rohdaten/Validieren/
* Normalisieren/Speichern) per Kommentar-Abschnitten sichtbar; die
* Erweiterungsfeld-Validierung ist in die eigenständige, ein
* SchemaOrgData_ValidationResult liefernde Methode
* validateExtensionField() ausgelagert.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
* SchemaOrgData_SchemaRepository, SchemaOrgData_Validator,
* SchemaOrgData_OpeningHoursHelper, SchemaOrgData_AdminPageRenderer,
* $this->settings, PLUGIN_SELF_DIR) werden je Aufruf als Parameter
* übergeben, nicht im Konstruktor eingefroren (siehe README.md,
* Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_ConfigSaveService {

    /***************************************************************
    *
    * Ermittelt für eine Kategorie-/Seiten-Sektion, welche Feldwerte
    * eines Schema-Types von einer übergeordneten Ebene (Global bzw.
    * Global+Kategorie) geerbt würden (siehe
    * SchemaOrgData_ScopeResolver::resolveTypeInheritance()), sowie
    * die jeweilige Ursprungsebene als lesbares Label (siehe
    * SchemaOrgData_AdminPageRenderer::buildScopeLabel()). Dient
    * ausschließlich der Anzeige im Formular (Placeholder + "ü"-Badge,
    * siehe SchemaOrgData_FormRenderer::renderInheritedBadge()) - die
    * zurückgegebenen Werte werden NICHT in die Formularfelder
    * übernommen und nicht gespeichert.
    *
    * Bei mehreren übergeordneten Ebenen gewinnt die spezifischere
    * (Kategorie vor Global) - analog mergeConfigs()/
    * resolveTypeInheritance().
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param string $type  Schema-Type, z. B. "LocalBusiness"
    * @param Language $lang Admin-Sprachobjekt
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param SchemaOrgData_AdminPageRenderer $adminPageRenderer für buildScopeLabel()
    * @return array{data: array<string,mixed>, originLabel: array<string,string>}
    *
    ***************************************************************/
    public function resolveInheritableFields(
        string $scope,
        ?string $cat,
        ?string $page,
        string $type,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        $settings,
        SchemaOrgData_AdminPageRenderer $adminPageRenderer
    ): array {
        $higherScopes = match($scope) {
            'category' => [
                ['global', $scopeResolver->loadScopeConfig($settings, 'global'), null, null],
            ],
            'page' => [
                ['global', $scopeResolver->loadScopeConfig($settings, 'global'), null, null],
                ['category', $scopeResolver->loadScopeConfig($settings, 'category', $cat), $cat, null],
            ],
            default => [],
        };

        $data = [];
        $originLabel = [];
        foreach($higherScopes as [$higherScope, $higherConfig, $higherCat, $higherPage]) {
            if(!is_array($higherConfig[$type] ?? null)) {
                continue;
            }
            $label = $adminPageRenderer->buildScopeLabel($higherScope, $higherCat, $higherPage, $lang);
            foreach($higherConfig[$type] as $field => $value) {
                $data[$field] = $value;
                $originLabel[$field] = $label;
            }
        }

        return ['data' => $data, 'originLabel' => $originLabel];
    }

    /***************************************************************
    *
    * Bereinigt die Formularfeld-Werte eines Schema-Types vor dem
    * Speichern: nur im Schema bekannte Properties, Strings
    * getrimmt und ohne HTML-Tags (strip_tags), Telefonnummern
    * normalisiert (preg_replace('/[^0-9+]/', '', ...)), Datumsfelder
    * (format: date-time) auf ISO-8601 normalisiert
    * (normalizeEventDateInput()), Öffnungszeiten als schema.org-Array
    * (buildOpeningHoursArray) und FAQ-Einträge ohne vollständige
    * Frage/Antwort entfernt.
    *
    * @param array<string, mixed> $formData Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array<string, mixed> $schema aktives JSON-Schema (schemas/{Type}.json)
    * @return array<string, mixed> bereinigte Properties, bereit für serialize()
    *
    ***************************************************************/
    public function sanitizePostData(
        array $formData,
        array $schema,
        SchemaOrgData_SchemaRepository $schemaRepository,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_Validator $validator
    ): array {
        $result = [];

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            if(!array_key_exists($name, $formData)) {
                continue;
            }

            $fieldSchema = $schemaRepository->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema['ui:widget'] ?? 'text';
            $value = $formData[$name];

            if($widget === 'postal_address') {
                $address = $this->sanitizeAddressData(is_array($value) ? $value : [], $fieldSchema, $validator);
                if($address !== []) {
                    $result[$name] = $address;
                }
                continue;
            }

            if($widget === 'place') {
                // Wiederverwendung von sanitizeAddressData() für die
                // verschachtelte Adresse - keine eigene Bereinigungslogik.
                $place = is_array($value) ? $value : [];
                $placeResult = [];

                $placeName = trim(strip_tags((string) ($place['name'] ?? '')));
                if($placeName !== '') {
                    $placeResult['name'] = $placeName;
                }

                $properties = $fieldSchema['properties'] ?? [];
                if(isset($properties['address'])) {
                    $addressSchema = $schemaRepository->resolveSchemaRef($properties['address'], $schema);
                    $address = $this->sanitizeAddressData(is_array($place['address'] ?? null) ? $place['address'] : [], $addressSchema, $validator);
                    if($address !== []) {
                        $placeResult['address'] = $address;
                    }
                }

                if($placeResult !== []) {
                    $result[$name] = $placeResult;
                }
                continue;
            }

            if($widget === 'opening_hours') {
                $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
                $perDay = is_array($value) ? $value : [];
                $primary = $openingHoursHelper->buildOpeningHoursArray($perDay, $days);
                $secondary = $openingHoursHelper->buildOpeningHoursArray($perDay, $days, 'from2', 'to2');
                $openingHours = array_merge($primary, $secondary);
                if($openingHours !== []) {
                    $result[$name] = $openingHours;
                }
                continue;
            }

            if($widget === 'faq_list') {
                $entries = [];
                foreach((is_array($value) ? $value : []) as $entry) {
                    $question = trim(strip_tags((string) ($entry['name'] ?? '')));
                    $answer = trim(strip_tags((string) ($entry['acceptedAnswer']['text'] ?? '')));

                    if($question === '' or $answer === '') {
                        continue;
                    }

                    $entries[] = ['name' => $question, 'acceptedAnswer' => ['text' => $answer]];
                }
                if($entries !== []) {
                    $result[$name] = $entries;
                }
                continue;
            }

            if($widget === 'geo') {
                // Paar-Pflicht ("beides oder nichts") wurde bereits in
                // validateFormData() geprüft - sind wir hier, sind beide
                // Werte gefüllt oder beide leer. Numerische Umwandlung
                // (statt String), damit buildJsonLdScript() korrekte
                // JSON-Zahlen statt gequoteter Strings ausgibt.
                $geo = is_array($value) ? $value : [];
                $latValue = trim(strip_tags((string) ($geo['latitude'] ?? '')));
                $lonValue = trim(strip_tags((string) ($geo['longitude'] ?? '')));
                if($latValue !== '' and $lonValue !== '') {
                    $result[$name] = ['latitude' => (float) $latValue, 'longitude' => (float) $lonValue];
                }
                continue;
            }

            if($widget === 'id_reference_or_literal') {
                if(!is_array($value)) {
                    continue;
                }
                $mode = (string) ($value['_mode'] ?? '');
                if($mode === 'reference') {
                    $fragment = trim(strip_tags((string) ($value['_fragment'] ?? '')));
                    if($fragment !== '') {
                        $result[$name] = ['_mode' => 'reference', '_fragment' => $fragment];
                    }
                } elseif($mode === 'literal') {
                    $literal = ['_mode' => 'literal'];
                    foreach($fieldSchema['ui:literalFields'] ?? [] as $lf) {
                        $lv = trim(strip_tags((string) ($value[(string) $lf] ?? '')));
                        if($lv !== '') {
                            $literal[(string) $lf] = $lv;
                        }
                    }
                    if(count($literal) > 1) {
                        $result[$name] = $literal;
                    }
                }
                continue;
            }

            if(!is_scalar($value)) {
                continue;
            }

            $stringValue = trim(strip_tags((string) $value));
            if($stringValue === '') {
                continue;
            }

            if($name === 'telephone') {
                $stringValue = preg_replace('/[^0-9+]/', '', $stringValue);
            }

            if(($fieldSchema['format'] ?? null) === 'date-time') {
                $stringValue = $validator->normalizeEventDateInput($stringValue);
            }

            $result[$name] = $stringValue;
        }

        return $result;
    }

    /***************************************************************
    *
    * Bereinigt die Werte einer PostalAddress (siehe sanitizePostData).
    * Wurde keine Adresse ausgefüllt (siehe isAddressProvided), wird
    * ein leeres Array zurückgegeben - es wird also kein
    * unvollständiges "address"-Property gespeichert, das nur den
    * Default-Wert von addressCountry enthält.
    *
    * @return array<string, mixed> bereinigte Adress-Properties, ggf. leer
    *
    ***************************************************************/
    public function sanitizeAddressData(array $address, array $fieldSchema, SchemaOrgData_Validator $validator): array {
        $subProperties = $fieldSchema['properties'] ?? [];

        if(!$validator->isAddressProvided($address, $subProperties)) {
            return [];
        }

        $result = [];
        foreach($subProperties as $subName => $subSchema) {
            $subValue = trim(strip_tags((string) ($address[$subName] ?? '')));
            if($subValue !== '') {
                $result[$subName] = $subValue;
            }
        }

        return $result;
    }

    /***************************************************************
    *
    * Validiert das Erweiterungsfeld (freies JSON-Textarea, siehe
    * README.md "Erweiterungsfeld"): dekodiert die Rohdaten und prüft
    * Geo-Properties (validateExtensionGeo()). Aus saveConfig() ausgelagert,
    * damit saveConfig() nur noch orchestriert und keine Validierungslogik
    * selbst enthält.
    *
    * Leere Rohdaten ($extensionRaw === '') sind kein Fehler - das
    * Erweiterungsfeld ist optional.
    *
    * @param string $extensionRaw Rohwert aus $_POST (schemaOrgData[scope][extension][$type])
    *
    ***************************************************************/
    public function validateExtensionField(
        string $extensionRaw,
        Language $lang,
        SchemaOrgData_Validator $validator
    ): SchemaOrgData_ValidationResult {
        if($extensionRaw === '') {
            return new SchemaOrgData_ValidationResult(true, [], []);
        }

        $decoded = json_decode($extensionRaw, true);

        if(json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
            return new SchemaOrgData_ValidationResult(false, [$lang->getLanguageValue('error_json_invalid')], []);
        }

        $errors = $validator->validateExtensionGeo($decoded, $lang);

        return new SchemaOrgData_ValidationResult($errors === [], $errors, $decoded);
    }

    /***************************************************************
    *
    * Validiert und speichert die Konfiguration einer Geltungsebene.
    *
    * Ablauf (Save-Flow-Pipeline): Schema des gewählten Types laden,
    * Formularfelder und Erweiterungsfeld validieren (validateFormData()/
    * validateExtensionField()). Bei Validierungsfehlern wird nicht
    * gespeichert. Andernfalls werden die Formularfelder bereinigt
    * (sanitizePostData) und mit dem Erweiterungsfeld zusammengeführt
    * (Formular hat Vorrang, siehe README.md "Erweiterungsfeld"),
    * zusätzlich excluded_cats (nur global) und jsonld_mode
    * übernommen und über $this->settings gespeichert. Wurde kein Type
    * gewählt ("- kein Schema -"), wird die bisherige
    * Type-Konfiguration dieser Ebene entfernt, _meta und
    * excluded_cats bleiben erhalten.
    *
    * @param string $scope    'global' | 'category' | 'page'
    * @param array<string, mixed> $postData schemaOrgData[scope] aus $_POST
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param SchemaOrgData_AdminPageRenderer $adminPageRenderer wird an resolveInheritableFields() durchgereicht
    * @param SchemaOrgData_PersonsRegistryService $personsRegistryService für die Slug-Existenzprüfung der Organisations-Relationen
    * @param SchemaOrgData_OrgRelationsService $orgRelationsService für Validierung/Bereinigung der Organisations-Relationen (nur global)
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function saveConfig(
        string $scope,
        array $postData,
        $settings,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_AdminPageRenderer $adminPageRenderer,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        SchemaOrgData_OrgRelationsService $orgRelationsService
    ): array {
        // 1. Rohdaten
        [$cat, $page] = $scopeResolver->resolveScopeIdentifiers($scope);
        $key = $scopeResolver->getScopeSettingsKey($scope, $cat, $page);

        if ($key === null) {
            return ['success' => false, 'errors' => []];
        }

        $existing = $settings->keyExists($key)
            ? $settings->get($key) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $config = ['_meta' => $existing['_meta'] ?? ['existing_jsonld' => false, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => '']];

        if($scope === 'global') {
            $config['excluded_cats'] = $existing['excluded_cats'] ?? '';
            $config['debug_output'] = !empty($existing['debug_output']);
            $config['org_relations'] = is_array($existing['org_relations'] ?? null) ? $existing['org_relations'] : [];
        }

        $type = (string) ($postData['type'] ?? '');
        $errors = [];

        if($type !== '') {
            $schema = $schemaRepository->loadSchema($pluginSelfDir, $type);

            // LocalBusiness-Familie: bei Kategorie/Seite nur der bei Global
            // aktive Familien-Type zulässig. Schutz gegen Formular-
            // Manipulation (das gefilterte Dropdown verhindert die
            // Auswahl bereits clientseitig, siehe
            // SchemaOrgData_AdminController::renderScopeSection()).
            $familyMismatch = false;
            if($schema !== null and $scope !== 'global' and isset($schema['ui:family'])) {
                $globalConfig = $scopeResolver->loadScopeConfig($settings, 'global');
                $globalActiveType = $schemaRepository->resolveActiveType($globalConfig, $pluginSelfDir);
                $globalSchema = $globalActiveType !== null
                    ? $schemaRepository->loadSchema($pluginSelfDir, $globalActiveType) : null;
                $globalFamily = $globalSchema['ui:family'] ?? null;

                if($globalFamily !== null and $globalFamily === $schema['ui:family'] and $globalActiveType !== $type) {
                    $familyMismatch = true;
                }
            }

            if($schema === null or !in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $errors[] = $lang->getLanguageValue('error_invalid_schema_type', $type);
            } elseif($familyMismatch) {
                $errors[] = $lang->getLanguageValue('error_family_type_mismatch');
            } else {
                $formData = is_array($postData['data'] ?? null) ? $postData['data'] : [];
                $extensionRaw = trim((string) ($postData['extension'][$type] ?? ''));

                // 2. Validieren
                // Bewusste Reihenfolge: Validierung arbeitet auf den Rohdaten,
                // nicht auf der bereits bereinigten Normalisierungs-
                // Zwischenform - Fehlermeldungen sollen sich
                // auf das beziehen, was der Nutzer tatsächlich eingegeben hat.
                $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type, $lang, $scopeResolver, $settings, $adminPageRenderer);
                $formErrors = $validator->validateFormData($formData, $schema, $inheritable, $lang, $schemaRepository);
                $extensionResult = $this->validateExtensionField($extensionRaw, $lang, $validator);
                $errors = array_merge($formErrors, $extensionResult->errors);

                // 3. Normalisieren
                if($errors === []) {
                    $config[$type] = array_merge($extensionResult->extensionData, $this->sanitizePostData($formData, $schema, $schemaRepository, $openingHoursHelper, $validator));
                }
            }
        }

        // Organisations-Relationen (org_relations, nur global): Validierung
        // VOR dem Fehler-Gate, damit eine unbekannte Rolle bzw. eine
        // Referenz auf einen nicht (mehr) existierenden Personen-Slug das
        // Speichern blockiert (analog zur Formularfeld-/Erweiterungsfeld-
        // Validierung oben) statt still verworfen zu werden. Das Widget
        // erscheint nur bei global aktivem Organisations-Type (ui:idFragment
        // == "organization", siehe SchemaOrgData_AdminController), daher
        // fehlt "org_relations" im POST komplett, wenn z. B. WebSite aktiv
        // ist - in diesem Fall bleibt der bereits oben aus $existing gesetzte
        // Wert unangetastet (Type-Wechsel-Stabilität, siehe README.md).
        // Zusätzlich zu "org_relations" selbst wird auch das vom Widget immer
        // mitgesendete Marker-Feld "org_relations_marker" geprüft
        // (SchemaOrgData_FormRenderer::renderOrgRelationsWidget()): sind 0
        // Personen in der Registry verfügbar, rendert das Widget keine
        // org_relations[]-Zeilen, sendet aber weiterhin den Marker - ohne
        // diese zweite Bedingung würde array_key_exists("org_relations", ...)
        // fälschlich den "Feld fehlt komplett"-Fall (Type-Wechsel) annehmen
        // und eine zuvor gespeicherte, jetzt verwaiste Relation nicht bereinigen.
        // array_key_exists() statt isset(), da ein Formular ohne jede
        // Relation ein leeres Array sendet (unterscheidbar vom Fehlen des
        // Feldes selbst).
        $orgRelationsResult = null;
        if($scope === 'global' and (array_key_exists('org_relations', $postData) or array_key_exists('org_relations_marker', $postData))) {
            $orgRelationsResult = $orgRelationsService->sanitizeAndValidate(
                is_array($postData['org_relations'] ?? null) ? $postData['org_relations'] : [],
                $settings, $personsRegistryService, $lang
            );
            $errors = array_merge($errors, $orgRelationsResult['errors']);
        }

        if($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if($scope === 'global') {
            $excludedCats = [];
            foreach((array) ($postData['excluded_cats'] ?? []) as $excludedCat) {
                $excludedCat = $scopeResolver->sanitizeScopeIdentifier(trim((string) $excludedCat));
                if($excludedCat !== '') {
                    $excludedCats[] = $excludedCat;
                }
            }
            $config['excluded_cats'] = implode(',', $excludedCats);
            $config['debug_output'] = !empty($postData['debug_output']);
            if($orgRelationsResult !== null) {
                $config['org_relations'] = $orgRelationsResult['relations'];
            }
        }

        $jsonldMode = $_POST['schemaOrgData_jsonld_mode_'.$scope] ?? null;
        if(in_array($jsonldMode, ['keep', 'override'], true)) {
            $config['_meta']['jsonld_mode'] = $jsonldMode;
        }

        // 4. Speichern
        // Konfiguration über moziloCMS-settings-API speichern
        try {
            $settings->set($key, $config);
        } catch (\Throwable $e) {
            error_log('schemaOrgData: saveConfig fehlgeschlagen: ' . $e->getMessage());
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ]];
        }

        return ['success' => true, 'errors' => []];
    }
}
