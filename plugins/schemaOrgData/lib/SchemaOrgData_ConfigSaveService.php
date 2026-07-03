<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_ConfigSaveService
*
* Save-Flow-Pipeline des Admin-Formulars (Fahrplan-Schritt 6, siehe
* doc/adr_ziel_architektur.md, Abschnitt 4 + 11): bündelt die
* vererbungsbewusste Feld-Auflösung (resolveInheritableFields()),
* die POST-Bereinigung (sanitizePostData(), sanitizeAddressData())
* sowie Validieren/Speichern (saveConfig()) - vorher auf
* SchemaOrgData_AdminController, dessen saveConfig()-Rückreferenz
* aus SchemaOrgData_AdminRequestHandler damit aufgelöst ist. Noch
* dünner Wrapper: die vier Methoden wurden unverändert übernommen,
* saveConfig() macht die Vier-Phasen-Struktur (Rohdaten/Normalisieren/
* Validieren/Speichern) nur per Kommentar-Abschnitten sichtbar - ein
* eigenes ValidationResult-Objekt kommt erst mit Fahrplan-Schritt 7.
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
    * übernommen und nicht gespeichert, damit die feldweise Vererbung
    * aus 0.2.4-beta unverändert bleibt.
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
    * normalisiert (preg_replace('/[^0-9+]/', '', ...)),
    * Öffnungszeiten als schema.org-Array (buildOpeningHoursArray)
    * und FAQ-Einträge ohne vollständige Frage/Antwort entfernt.
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
    * Validiert und speichert die Konfiguration einer Geltungsebene.
    *
    * Ablauf (Save-Flow-Pipeline, siehe doc/adr_ziel_architektur.md,
    * Abschnitt 4): Schema des gewählten Types laden, Formularfelder
    * und Erweiterungsfeld validieren (validateFormData/json_decode/
    * validateExtensionGeo). Bei Validierungsfehlern wird nicht
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
        SchemaOrgData_AdminPageRenderer $adminPageRenderer
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
        }

        $type = (string) ($postData['type'] ?? '');
        $errors = [];

        if($type !== '') {
            $schema = $schemaRepository->loadSchema($pluginSelfDir, $type);

            if($schema === null or !in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $errors[] = $lang->getLanguageValue('error_invalid_schema_type', $type);
            } else {
                $formData = is_array($postData['data'] ?? null) ? $postData['data'] : [];
                $extensionRaw = trim((string) ($postData['extension'][$type] ?? ''));
                $extensionData = [];

                // 3. Validieren
                $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type, $lang, $scopeResolver, $settings, $adminPageRenderer);
                $errors = $validator->validateFormData($formData, $schema, $inheritable, $lang, $schemaRepository);

                if($extensionRaw !== '') {
                    $decoded = json_decode($extensionRaw, true);

                    if(json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
                        $errors[] = $lang->getLanguageValue('error_json_invalid');
                    } else {
                        $extensionData = $decoded;
                        $errors = array_merge($errors, $validator->validateExtensionGeo($extensionData, $lang));
                    }
                }

                // 2. Normalisieren
                if($errors === []) {
                    $config[$type] = array_merge($extensionData, $this->sanitizePostData($formData, $schema, $schemaRepository, $openingHoursHelper, $validator));
                }
            }
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
