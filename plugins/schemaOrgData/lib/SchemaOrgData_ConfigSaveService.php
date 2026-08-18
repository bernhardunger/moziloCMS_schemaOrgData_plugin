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
* Abschnitt "Entwicklerdokumentation").
*
***************************************************************/
class SchemaOrgData_ConfigSaveService {

    /**
     * Bindung an die Verwaltungsschlüssel aus
     * SchemaOrgData_ScopeResolver - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const KEY_META          = SchemaOrgData_ScopeResolver::KEY_META;
    private const KEY_EXCLUDED_CATS = SchemaOrgData_ScopeResolver::KEY_EXCLUDED_CATS;
    private const KEY_DEBUG_OUTPUT  = SchemaOrgData_ScopeResolver::KEY_DEBUG_OUTPUT;
    private const KEY_ORG_RELATIONS = SchemaOrgData_ScopeResolver::KEY_ORG_RELATIONS;

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
    * Bereinigungen, bei denen Inhalt verloren geht, werden zusätzlich in
    * $notices vermerkt (siehe collectNotice()) - die Methode führt kein
    * Language und kann deshalb keine Texte bauen; das übernimmt
    * saveConfig() anhand der gesammelten Feldnamen. Reines Trimmen und die
    * Telefon-Normalisierung melden bewusst nicht: beides ist
    * dokumentiertes Sollverhalten, steht nach dem Neuladen sichtbar im
    * Feld und feuert bei alltäglicher Eingabe - eine Meldung, die ständig
    * erscheint, wird weggeklickt und hilft im Ernstfall (Eingabe nur aus
    * Tags, Feld danach ersatzlos leer) nicht mehr.
    *
    * @param array<string, mixed> $formData Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array<string, mixed> $schema aktives JSON-Schema (schemas/{Type}.json)
    * @param array<int, array{field: string, kind: string}> $notices Sammler für Verlust-Hinweise
    * @return array<string, mixed> bereinigte Properties, bereit für serialize()
    *
    ***************************************************************/
    public function sanitizePostData(
        array $formData,
        array $schema,
        SchemaOrgData_SchemaRepository $schemaRepository,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_Validator $validator,
        array &$notices = []
    ): array {
        $result = [];

        // Gesendete Felder ohne Gegenstück im Schema erreichen die
        // Feldschleife unten nie - sie iteriert über die Schema-Properties,
        // nicht über die Formulardaten. Der Verlust wird deshalb hier
        // vermerkt, wo die Schema-Bindung stattfindet.
        foreach(array_keys($formData) as $postedName) {
            if(!array_key_exists($postedName, $schema['properties'] ?? [])) {
                $this->collectNotice($notices, (string) $postedName, 'dropped');
            }
        }

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            if(!array_key_exists($name, $formData)) {
                continue;
            }

            $fieldSchema = $schemaRepository->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema['ui:widget'] ?? 'text';
            $value = $formData[$name];

            if($widget === 'postal_address') {
                $address = $this->sanitizeAddressData(is_array($value) ? $value : [], $fieldSchema, $validator, $notices);
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

                $placeRaw = (string) ($place['name'] ?? '');
                $placeName = trim(strip_tags($placeRaw));
                if($placeName !== trim($placeRaw)) {
                    $this->collectNotice($notices, (string) $name, 'cleaned');
                }
                if($placeName !== '') {
                    $placeResult['name'] = $placeName;
                }

                $properties = $fieldSchema['properties'] ?? [];
                if(isset($properties['address'])) {
                    $addressSchema = $schemaRepository->resolveSchemaRef($properties['address'], $schema);
                    $address = $this->sanitizeAddressData(is_array($place['address'] ?? null) ? $place['address'] : [], $addressSchema, $validator, $notices);
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
                $days = SchemaOrgData_OpeningHoursHelper::resolveDays($fieldSchema);
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
                    $questionRaw = (string) ($entry['name'] ?? '');
                    $answerRaw = (string) ($entry['acceptedAnswer']['text'] ?? '');
                    $question = trim(strip_tags($questionRaw));
                    $answer = trim(strip_tags($answerRaw));

                    if($question !== trim($questionRaw) or $answer !== trim($answerRaw)) {
                        $this->collectNotice($notices, (string) $name, 'cleaned');
                    }

                    if($question === '' or $answer === '') {
                        // Die stets mitgesendete leere Anlege-Zeile ist kein
                        // Verlust und meldet deshalb nicht - nur ein Eintrag,
                        // von dem eine der beiden Hälften ausgefüllt war.
                        if($question !== '' or $answer !== '') {
                            $this->collectNotice($notices, (string) $name, 'dropped');
                        }
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
                $latRaw = (string) ($geo['latitude'] ?? '');
                $lonRaw = (string) ($geo['longitude'] ?? '');
                $latValue = trim(strip_tags($latRaw));
                $lonValue = trim(strip_tags($lonRaw));
                if($latValue !== trim($latRaw) or $lonValue !== trim($lonRaw)) {
                    $this->collectNotice($notices, (string) $name, 'cleaned');
                }
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
                    $fragmentRaw = (string) ($value['_fragment'] ?? '');
                    $fragment = trim(strip_tags($fragmentRaw));
                    if($fragment !== trim($fragmentRaw)) {
                        $this->collectNotice($notices, (string) $name, 'cleaned');
                    }
                    if($fragment !== '') {
                        $result[$name] = ['_mode' => 'reference', '_fragment' => $fragment];
                    }
                } elseif($mode === 'literal') {
                    $literal = ['_mode' => 'literal'];
                    foreach($fieldSchema['ui:literalFields'] ?? [] as $lf) {
                        $lvRaw = (string) ($value[(string) $lf] ?? '');
                        $lv = trim(strip_tags($lvRaw));
                        if($lv !== trim($lvRaw)) {
                            $this->collectNotice($notices, (string) $name, 'cleaned');
                        }
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
                $this->collectNotice($notices, (string) $name, 'dropped');
                continue;
            }

            $rawValue = (string) $value;
            $stringValue = trim(strip_tags($rawValue));
            if($stringValue !== trim($rawValue)) {
                $this->collectNotice($notices, (string) $name, 'cleaned');
            }
            if($stringValue === '') {
                // Bestand die Eingabe nur aus Auszeichnung ("<b></b>"), ist
                // das Feld nach dem Strippen ersatzlos weg - die stärkere
                // Aussage ersetzt den eben vermerkten cleaned-Hinweis
                // (siehe collectNotice()). Ein von vornherein leeres Feld
                // ist dagegen kein Verlust und meldet nicht.
                if(trim($rawValue) !== '') {
                    $this->collectNotice($notices, (string) $name, 'dropped');
                }
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
    * @param array<int, array{field: string, kind: string}> $notices Sammler für Verlust-Hinweise, siehe sanitizePostData()
    * @return array<string, mixed> bereinigte Adress-Properties, ggf. leer
    *
    ***************************************************************/
    public function sanitizeAddressData(array $address, array $fieldSchema, SchemaOrgData_Validator $validator, array &$notices = []): array {
        $subProperties = $fieldSchema['properties'] ?? [];

        if(!$validator->isAddressProvided($address, $subProperties)) {
            return [];
        }

        $result = [];
        foreach($subProperties as $subName => $subSchema) {
            $subRaw = (string) ($address[$subName] ?? '');
            $subValue = trim(strip_tags($subRaw));
            if($subValue !== trim($subRaw)) {
                $this->collectNotice($notices, (string) $subName, 'cleaned');
            }
            if($subValue !== '') {
                $result[$subName] = $subValue;
            }
        }

        return $result;
    }

    /***************************************************************
    *
    * Nimmt einen Verlust-Hinweis in den Sammler auf - höchstens einen
    * je Feld. Eine Eingabe, die nur aus Auszeichnung besteht, läuft
    * durch beide Zweige der Feldbereinigung (Inhalt entfernt, danach
    * leer und verworfen); der Admin braucht dann die stärkere der
    * beiden Aussagen und nicht beide, deshalb ersetzt "dropped" ein
    * bereits vermerktes "cleaned" desselben Feldes, nicht umgekehrt.
    *
    * "extension_property_dropped" hat Vorrang vor beiden anderen und
    * wird von keiner überschrieben: Was das Formular bereinigt hat, sieht
    * der Admin nach dem Neuladen im Feld selbst - was aus dem
    * Erweiterungsfeld verworfen wurde, sieht er nirgends. Für ein Property,
    * das in beiden Eingabewegen zugleich auftaucht, ist deshalb der
    * Erweiterungsfeld-Hinweis der einzige, der ihm etwas Neues sagt.
    *
    * @param array<int, array{field: string, kind: string}> $notices Sammler
    * @param string $kind 'cleaned' | 'dropped' | 'extension_property_dropped'
    *
    ***************************************************************/
    private function collectNotice(array &$notices, string $field, string $kind): void {
        foreach($notices as $index => $notice) {
            if(($notice['field'] ?? null) !== $field) {
                continue;
            }

            $existingKind = (string) ($notice['kind'] ?? '');

            if($existingKind === 'extension_property_dropped') {
                return;
            }

            if($kind === 'extension_property_dropped'
                or ($kind === 'dropped' and $existingKind === 'cleaned')) {
                $notices[$index]['kind'] = $kind;
            }

            return;
        }

        $notices[] = ['field' => $field, 'kind' => $kind];
    }

    /***************************************************************
    *
    * Entfernt aus den Erweiterungsfeld-Daten jeden Schlüssel, den das
    * Schema als eigenes Formularfeld führt, und vermerkt ihn im
    * Notice-Sammler.
    *
    * Das Erweiterungsfeld ist für Properties gedacht, die das Formular
    * NICHT abbildet (siehe README.md "Erweiterungsfeld"). Ein
    * gleichnamiger Schlüssel umginge sonst die Feldbereinigung: lässt der
    * Admin das Formularfeld leer, liefert sanitizePostData() für dieses
    * Property gar keinen Wert, und der Merge hat nichts, womit er den
    * ungereinigten Eintrag aus dem Erweiterungsfeld überschreiben könnte -
    * gespeichert würde die Roheingabe samt Auszeichnung.
    *
    * Der Schlüssel wird verworfen und gemeldet, nicht abgelehnt: ein
    * Fehler würde eine bestehende Konfiguration beim nächsten Speichern
    * blockieren, ohne dass der Admin etwas falsch gemacht hätte.
    *
    * @param array<string, mixed> $extensionData dekodierte Erweiterungsfeld-Daten
    * @param array<string, mixed> $schema aktives JSON-Schema (schemas/{Type}.json)
    * @param array<int, array{field: string, kind: string}> $notices Sammler für Verlust-Hinweise
    * @return array<string, mixed> Erweiterungsfeld-Daten ohne Schema-Property-Schlüssel
    *
    ***************************************************************/
    private function dropSchemaPropertyKeys(array $extensionData, array $schema, array &$notices): array {
        // Ein Schema ohne Array unter "properties" führt keine bekannten
        // Properties - dann bleibt alles stehen, statt array_keys() mit
        // einem Nicht-Array aufzurufen.
        if(!is_array($schema['properties'] ?? null)) {
            return $extensionData;
        }

        $knownProperties = array_keys($schema['properties']);
        $result = [];

        foreach($extensionData as $key => $value) {
            if(in_array($key, $knownProperties, true)) {
                $this->collectNotice($notices, (string) $key, 'extension_property_dropped');
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /***************************************************************
    *
    * Validiert das Erweiterungsfeld (freies JSON-Textarea, siehe
    * README.md "Erweiterungsfeld"): dekodiert die Rohdaten, prüft die
    * Struktur (erwartet wird ein JSON-Objekt, keine Liste) und prüft
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

        // json_decode() liefert für Objekt und Liste gleichermaßen ein
        // PHP-Array, is_array() trennt die beiden also nicht. Eine Liste
        // hat keine Property-Namen - der Merge in saveConfig() vergäbe ihre
        // numerischen Schlüssel neu und schriebe sie als Properties "0",
        // "1", "2" in die Konfiguration. Die Nicht-Leer-Bedingung ist
        // notwendig, weil json_decode('{}', true) ein leeres Array liefert,
        // das array_is_list() als Liste zählt: das leere Objekt ist ein
        // gültiges, folgenloses Erweiterungsfeld und wird nicht abgelehnt.
        if($decoded !== [] and array_is_list($decoded)) {
            return new SchemaOrgData_ValidationResult(false, [$lang->getLanguageValue('error_json_not_object')], []);
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
    * (sanitizePostData) und mit dem Erweiterungsfeld zusammengeführt -
    * Schlüssel, die das Formular als eigenes Feld führt, verwirft
    * dropSchemaPropertyKeys() vorher aus dem Erweiterungsfeld (siehe
    * README.md, Abschnitt zum Erweiterungsfeld),
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
    * Neben "errors" (blockierend) führt das Ergebnis "notices": nicht
    * blockierende Hinweise darauf, dass eine Eingabe zwar gespeichert
    * wurde, aber nicht unverändert (siehe sanitizePostData()). Sie
    * beeinflussen "success" nicht und werden hier - der einzigen Stelle,
    * die Language und Schema zugleich führt - aus den gesammelten
    * Feldnamen in Text übersetzt. Der Schlüssel wird an jeder
    * Rückgabestelle gesetzt, auch in den Fehlerzweigen, damit die
    * Ergebnisform nie wechselt.
    *
    * @param SchemaOrgData_OrgRelationsService $orgRelationsService für Validierung/Bereinigung der Organisations-Relationen (nur global)
    * @return array{success: bool, errors: string[], notices: string[]}
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
            return ['success' => false, 'errors' => [], 'notices' => []];
        }

        $existing = $settings->keyExists($key)
            ? $settings->get($key) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $config = [self::KEY_META => $existing[self::KEY_META] ?? ['existing_jsonld' => false, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => '']];

        if($scope === 'global') {
            $config[self::KEY_EXCLUDED_CATS] = $existing[self::KEY_EXCLUDED_CATS] ?? '';
            $config[self::KEY_DEBUG_OUTPUT] = !empty($existing[self::KEY_DEBUG_OUTPUT]);
            $config[self::KEY_ORG_RELATIONS] = is_array($existing[self::KEY_ORG_RELATIONS] ?? null) ? $existing[self::KEY_ORG_RELATIONS] : [];
        }

        $type = (string) ($postData['type'] ?? '');
        $errors = [];
        $collectedNotices = [];
        $activeSchema = null;

        if($type !== '') {
            $schema = $schemaRepository->loadSchema($pluginSelfDir, $type);
            $activeSchema = is_array($schema) ? $schema : null;

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
                    $extensionData = $this->dropSchemaPropertyKeys($extensionResult->extensionData, $schema, $collectedNotices);
                    $config[$type] = array_merge($extensionData, $this->sanitizePostData($formData, $schema, $schemaRepository, $openingHoursHelper, $validator, $collectedNotices));
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
        if($scope === 'global' and (array_key_exists(self::KEY_ORG_RELATIONS, $postData) or array_key_exists('org_relations_marker', $postData))) {
            $orgRelationsResult = $orgRelationsService->sanitizeAndValidate(
                is_array($postData[self::KEY_ORG_RELATIONS] ?? null) ? $postData[self::KEY_ORG_RELATIONS] : [],
                $settings, $personsRegistryService, $lang
            );
            $errors = array_merge($errors, $orgRelationsResult['errors']);
            // Über collectNotice() statt array_merge(), damit mehrere
            // kaputte Relationen-Zeilen nicht dieselbe Meldung mehrfach
            // erzeugen (ein Hinweis je Feld).
            foreach($orgRelationsResult['notices'] ?? [] as $orgNotice) {
                $this->collectNotice($collectedNotices, (string) ($orgNotice['field'] ?? ''), (string) ($orgNotice['kind'] ?? 'dropped'));
            }
        }

        if($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'notices' => []];
        }

        if($scope === 'global') {
            $excludedCats = [];
            foreach((array) ($postData[self::KEY_EXCLUDED_CATS] ?? []) as $excludedCat) {
                $excludedCat = $scopeResolver->sanitizeScopeIdentifier(trim((string) $excludedCat));
                if($excludedCat !== '') {
                    $excludedCats[] = $excludedCat;
                }
            }
            $config[self::KEY_EXCLUDED_CATS] = implode(',', $excludedCats);
            $config[self::KEY_DEBUG_OUTPUT] = !empty($postData[self::KEY_DEBUG_OUTPUT]);
            if($orgRelationsResult !== null) {
                $config[self::KEY_ORG_RELATIONS] = $orgRelationsResult['relations'];
            }
        }

        $jsonldMode = $_POST['schemaOrgData_jsonld_mode_'.$scope] ?? null;
        if(in_array($jsonldMode, ['keep', 'override'], true)) {
            $config[self::KEY_META]['jsonld_mode'] = $jsonldMode;
        }

        // 4. Speichern
        // Konfiguration über moziloCMS-settings-API speichern. Der Kern
        // signalisiert einen fehlgeschlagenen Schreibzugriff (nicht
        // schreibbare plugin.conf.php, voller Datenträger) per Rückgabewert
        // false und rollt dabei seinen In-Memory-Stand zurück - er wirft
        // keine Exception. Der catch-Zweig bleibt als Netz für unerwartete
        // Fehler daneben stehen.
        try {
            if($settings->set($key, $config) === false) {
                return ['success' => false, 'errors' => [
                    $lang->getLanguageValue('error_config_write_failed')
                ], 'notices' => []];
            }
        } catch (\Throwable $e) {
            error_log('schemaOrgData: saveConfig fehlgeschlagen: ' . $e->getMessage());
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ], 'notices' => []];
        }

        return [
            'success' => true,
            'errors' => [],
            'notices' => $this->buildNoticeTexts($collectedNotices, $activeSchema, $lang, $schemaRepository),
        ];
    }

    /***************************************************************
    *
    * Übersetzt die von den Sanitisierungsstellen gesammelten
    * Feldnamen in fertige Meldungstexte. Der Wert selbst wird bewusst
    * nicht genannt: der Admin sieht das Ergebnis ohnehin im Formular,
    * und ein Echo der Roheingabe in die Admin-Seite wäre eine
    * zusätzliche Kodierungsfläche ohne Zusatznutzen.
    *
    * @param array<int, array{field: string, kind: string}> $collected Sammler aus sanitizePostData()
    * @param array<string, mixed>|null $schema aktives Schema für die Label-Auflösung
    * @return string[] fertige Meldungstexte
    *
    ***************************************************************/
    private function buildNoticeTexts(
        array $collected,
        ?array $schema,
        Language $lang,
        SchemaOrgData_SchemaRepository $schemaRepository
    ): array {
        $texts = [];

        foreach($collected as $notice) {
            $field = (string) ($notice['field'] ?? '');
            if($field === '') {
                continue;
            }

            $key = match((string) ($notice['kind'] ?? '')) {
                'extension_property_dropped' => 'notice_extension_property_dropped',
                'dropped' => 'notice_value_dropped',
                default => 'notice_value_cleaned',
            };

            $texts[] = $lang->getLanguageValue(
                $key, $this->resolveFieldLabel($field, $schema, $lang, $schemaRepository)
            );
        }

        return $texts;
    }

    /***************************************************************
    *
    * Liefert die für den Admin lesbare Bezeichnung eines Feldes:
    * das über "ui:label" aufgelöste, übersetzte Label, sonst der rohe
    * Property-Name. "Feld 'Beschreibung'" ist brauchbar, "Feld
    * 'description'" nicht - aber ein Property ohne Label ist immer noch
    * besser benannt als gar nicht.
    *
    * @param array<string, mixed>|null $schema aktives Schema
    *
    ***************************************************************/
    private function resolveFieldLabel(
        string $field,
        ?array $schema,
        Language $lang,
        SchemaOrgData_SchemaRepository $schemaRepository
    ): string {
        // Die Organisations-Relationen sind kein Schema-Property, sondern
        // ein eigenständiges Widget mit festem Sprachschlüssel.
        $labelKey = ($field === self::KEY_ORG_RELATIONS) ? 'label_org_relations' : null;

        if($labelKey === null and $schema !== null) {
            $labelKey = $this->findLabelKey($field, $schema['properties'] ?? [], $schema, $schemaRepository);
        }

        if($labelKey === null) {
            return $field;
        }

        $label = $lang->getLanguageValue($labelKey);

        return ($label !== '') ? $label : $field;
    }

    /***************************************************************
    *
    * Sucht den "ui:label"-Sprachschlüssel eines Property-Namens im
    * Schema. Verschachtelte Properties (Adress-Teilfelder, FAQ-Einträge)
    * werden mit durchsucht, aber erst im zweiten Durchgang: ein
    * gleichnamiges Feld der obersten Ebene gewinnt, weil die
    * Sanitisierungsstellen dort ebenfalls zuerst greifen.
    *
    * @param array<string, mixed> $properties zu durchsuchende Property-Ebene
    * @param array<string, mixed> $schema Wurzelschema für die $ref-Auflösung
    *
    ***************************************************************/
    private function findLabelKey(
        string $field,
        array $properties,
        array $schema,
        SchemaOrgData_SchemaRepository $schemaRepository,
        int $depth = 0
    ): ?string {
        // Rekursionsbremse gegen ein Schema, dessen $ref-Kette auf sich
        // selbst zurückführt - die Schemadateien sind flach, die Grenze
        // wird im Bestand nicht erreicht.
        if($depth > 4) {
            return null;
        }

        $resolved = [];
        foreach($properties as $name => $propSchema) {
            $propSchema = $schemaRepository->resolveSchemaRef($propSchema, $schema);
            if(!is_array($propSchema)) {
                continue;
            }
            $resolved[(string) $name] = $propSchema;
        }

        if(isset($resolved[$field]['ui:label'])) {
            return (string) $resolved[$field]['ui:label'];
        }

        foreach($resolved as $propSchema) {
            $nested = $propSchema['properties'] ?? ($propSchema['items']['properties'] ?? null);
            if(!is_array($nested)) {
                continue;
            }

            $found = $this->findLabelKey($field, $nested, $schema, $schemaRepository, $depth + 1);
            if($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
