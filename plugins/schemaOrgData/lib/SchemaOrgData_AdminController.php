<?php if (!defined('IS_CMS')) die();

/***************************************************************
 *
 * SchemaOrgData_AdminController
 *
 * Admin-Formular: Orchestrierung von Sektions-Rendering
 * (renderScopeSection()) und der vollständigen Admin-Seite
 * (renderAdminPage()). Die reinen Anzeige-Bausteine (Info-Block,
 * Scope-Label/Selektor, Speichern-Button-Beschriftung, Speicher-
 * Ergebnis-Hinweis, Hinweis auf vorhandenes/kollidierendes JSON-LD,
 * Ausschlussliste, Admin-CSS, Schema-Type-Auswahl) sind in
 * SchemaOrgData_AdminPageRenderer ausgelagert, die POST-Verarbeitung
 * (handlePostRequest()) in SchemaOrgData_AdminRequestHandler, die
 * feldweise Vererbungsanzeige (resolveInheritableFields()),
 * POST-Sanitizing (sanitizePostData(), sanitizeAddressData()) und
 * Speichern/Validieren (saveConfig()) in SchemaOrgData_ConfigSaveService.
 *
 * Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
 * SchemaOrgData_SchemaRepository, SchemaOrgData_FormRenderer,
 * SchemaOrgData_Validator, SchemaOrgData_OpeningHoursHelper,
 * SchemaOrgData_CollisionDetector, SchemaOrgData_IdReferenceService,
 * SchemaOrgData_AdminPageRenderer, SchemaOrgData_ConfigSaveService,
 * $this->settings, PLUGIN_SELF_DIR/PLUGIN_SELF_URL) werden je Aufruf
 * als Parameter übergeben, nicht im Konstruktor eingefroren (siehe
 * README.md, Abschnitt "Entwicklerdokumentation").
 *
 ***************************************************************/
class SchemaOrgData_AdminController {

    /***************************************************************
     *
     * Rendert den vollständigen Konfigurationsblock einer
     * Geltungsebene: Info-Block, Hinweis auf vorhandenes JSON-LD
     * (siehe renderExistingJsonLdNotice), Type-Auswahl,
     * Type-Kollisionshinweis, Formularfelder je verfügbarem Type
     * (sichtbar nur für den aktuell gewählten Type, Umschaltung
     * erfolgt clientseitig) sowie - nur für "global" - die
     * Ausschlussliste.
     *
     * Die Sektion erhält data-scope-cat/data-scope-page Attribute,
     * über die initScopeSelector() (validator.js) sie dem
     * passenden Button im Scope-Selektor zuordnet, sowie
     * data-scope-label (siehe buildScopeLabel()) für den Hinweis
     * auf ungespeicherte Eingaben beim Scope-Wechsel. Ist $active
     * false, wird die Sektion initial mit style="display:none"
     * ausgeblendet und alle enthaltenen Formularelemente erhalten
     * disabled="disabled" (JS-loses Laden zeigt dennoch die aktive
     * Sektion, initScopeSelector toggled disabled beim Umschalten).
     *
     * @param string $scope 'global' | 'category' | 'page'
     * @param bool   $active ob diese Sektion initial sichtbar ist
     * @param string|null $idPrefix Präfix für HTML-IDs dieser Sektion
     *                     (z. B. "global", "cat_Startseite"; Fallback: $scope)
     * @param bool $saveFailed Sektion aus POST-Daten statt gespeicherter
     *        Konfiguration befüllen - trotz des Namens nicht nur bei
     *        fehlgeschlagenem Speichern true, sondern auch nach einem
     *        (erfolgreichen oder fehlgeschlagenen) Import (siehe
     *        renderAdminPage(), $usePostData)
     * @param bool $importApplied unabhängig von $saveFailed - true nur
     *        nach einem erfolgreichen Import (siehe renderAdminPage()),
     *        ausschließlich für die Person-Übernahme-Erkennung
     *        (SchemaOrgData_PersonSuggestionService) verwendet, damit
     *        ein Vorschlag direkt im Post-Import-Redisplay erscheinen
     *        kann statt erst nach einem zusätzlichen Speichern
     * @param SchemaOrgData_AdminRequestContext $context Laufzeit-Kollaboratoren (siehe dort)
     * @return string HTML-Snippet
     *
     ***************************************************************/
    public function renderScopeSection(
        string $scope,
        ?string $cat,
        ?string $page,
        bool $active,
        ?string $idPrefix,
        bool $saveFailed,
        bool $importApplied,
        SchemaOrgData_AdminRequestContext $context
    ): string {
        $lang = $context->lang;
        $scopeResolver = $context->scopeResolver;
        $settings = $context->settings;
        $schemaRepository = $context->schemaRepository;
        $pluginSelfDir = $context->pluginSelfDir;
        $formRenderer = $context->formRenderer;
        $dataSplitHelper = $context->dataSplitHelper;
        $urlHelper = $context->urlHelper;
        $pluginLang = $context->pluginLang;
        $pluginSelfUrl = $context->pluginSelfUrl;
        $weekdayLang = $context->weekdayLang;
        $idReferenceService = $context->idReferenceService;
        $openingHoursHelper = $context->openingHoursHelper;
        $validator = $context->validator;
        $adminPageRenderer = $context->adminPageRenderer;
        $configSaveService = $context->configSaveService;
        $personsRegistryService = $context->personsRegistryService;
        $personSuggestionService = $context->personSuggestionService;

        $idPrefix = $idPrefix ?? $scope;

        // Settings-Schlüssel ausschließlich aus sanitierten Bezeichnern
        // bilden. $cat/$page kommen roh aus get_CatArray()/get_PageArray();
        // Speicherpfad, Import und Frontend sanitieren dagegen bereits.
        // mo_rawurlencode() des Kerns lässt den Punkt unkodiert, ein
        // Bezeichner wie "Dr.%20Meier" erreicht die Schlüsselbildung also
        // mit Punkt - ohne Sanitizing entstünde hier ein anderer Schlüssel
        // als beim Speichern, und die Sektion bliebe leer.
        // Roh bleiben die Bezeichner überall dort, wo nicht der Schlüssel,
        // sondern der Name gemeint ist: Anzeige, data-scope-*-Attribute und
        // der Seiteninhalts-Zugriff über den Kern.
        $keyCat  = $cat !== null ? $scopeResolver->sanitizeScopeIdentifier($cat) : null;
        $keyPage = $page !== null ? $scopeResolver->sanitizeScopeIdentifier($page) : null;

        $config = $scopeResolver->loadScopeConfig($settings, $scope, $keyCat, $keyPage);

        // Bei fehlgeschlagenem Speichern: die aktive Sektion mit den
        // POST-Daten statt mit dem gespeicherten Konfigurations-Stand
        // befüllen, damit fehlerhafte Eingaben nicht verloren gehen
        // (siehe renderAdminPage()).
        $postScope = null;
        if ($active and $saveFailed and is_array($_POST['schemaOrgData'][$scope] ?? null)) {
            $postScope = $_POST['schemaOrgData'][$scope];
        }

        // verfügbare Schema-Types für diesen Geltungsbereich ermitteln
        $availableTypes = [];
        foreach ($schemaRepository->getAvailableSchemaTypes($pluginSelfDir) as $type) {
            $schema = $schemaRepository->loadSchema($pluginSelfDir, $type);
            if ($schema !== null and in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $availableTypes[$type] = $schema;
            }
        }

        // LocalBusiness-Familie: bei Kategorie/Seite nur den bei Global
        // aktiven Familien-Type anbieten.
        $familyFilterGlobalLabel = null;
        if ($scope !== 'global') {
            $globalConfig = $scopeResolver->loadScopeConfig($settings, 'global');
            $globalActiveType = $schemaRepository->resolveActiveType($globalConfig, $pluginSelfDir);
            $globalSchema = $globalActiveType !== null
                ? $schemaRepository->loadSchema($pluginSelfDir, $globalActiveType) : null;
            $globalFamily = $globalSchema['ui:family'] ?? null;

            if ($globalFamily !== null) {
                foreach ($availableTypes as $type => $schema) {
                    $family = $schema['ui:family'] ?? null;
                    if ($family === $globalFamily and $type !== $globalActiveType) {
                        unset($availableTypes[$type]);
                        $familyFilterGlobalLabel = $lang->getLanguageHtml($globalSchema['ui:typeLabel'] ?? $globalActiveType);
                    }
                }
            }
        }

        // aktuell konfigurierten Type ermitteln: nach fehlgeschlagenem
        // Speichern der vom Nutzer im Formular gewählte Type (POST), sonst
        // der erste bekannte Type in $config
        $selectedType = null;
        if ($postScope !== null) {
            $postedType = (string) ($postScope['type'] ?? '');
            if (isset($availableTypes[$postedType])) {
                $selectedType = $postedType;
            }
        }
        if ($selectedType === null) {
            foreach (array_keys($config) as $type) {
                if (isset($availableTypes[$type])) {
                    $selectedType = $type;
                    break;
                }
            }
        }

        $catAttr       = htmlspecialchars($cat ?? '', ENT_QUOTES, CHARSET);
        $pageAttr      = htmlspecialchars($page ?? '', ENT_QUOTES, CHARSET);
        $labelAttr     = htmlspecialchars($adminPageRenderer->buildScopeLabel($scope, $cat, $page, $lang), ENT_QUOTES, CHARSET);
        // buildSaveButtonLabel() liefert über getLanguageHtml() bereits
        // attributsicher escapten Text (ENT_COMPAT deckt " ab) - ein
        // zusätzliches htmlspecialchars() würde "&auml;" etc. zu "&amp;auml;"
        // doppelt kodieren und im Attributwert als rohe Entity-Syntax sichtbar
        // bleiben.
        $saveLabelAttr = $adminPageRenderer->buildSaveButtonLabel(
            $scope === 'global' ? null : $cat,
            $scope === 'page'   ? $page : null,
            $lang
        );
        $displayStyle = $active ? '' : ' style="display:none"';
        $html = '<div class="schemaOrgData-scope card mb" data-scope="' . $scope . '"'
            . ' data-scope-cat="' . $catAttr . '" data-scope-page="' . $pageAttr . '"'
            . ' data-scope-label="' . $labelAttr . '" data-save-label="' . $saveLabelAttr . '"' . $displayStyle . '>' . "\n";
        $html .= '<h3>' . $lang->getLanguageHtml('scope_' . $scope) . '</h3>' . "\n";

        $html .= $adminPageRenderer->renderInfoBlock($scope, $lang);

        $html .= $adminPageRenderer->renderExistingJsonLdNotice($scope, $keyCat, $keyPage, $lang, $scopeResolver, $settings, $idPrefix);

        if ($selectedType !== null) {
            $html .= $adminPageRenderer->renderCollisionNotice($scope, $keyCat, $keyPage, $selectedType, $lang, $scopeResolver, $settings);
        }

        $html .= '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
            . '<div class="mo-in-li-l"><label for="schemaOrgData_' . $idPrefix . '_type">' . $lang->getLanguageHtml('label_schema_type') . '</label></div>'
            . '<div class="mo-in-li-r">' . $adminPageRenderer->renderTypeSelector($scope, $availableTypes, $selectedType, $idPrefix, $lang) . '</div>'
            . '</div>' . "\n";

        if ($familyFilterGlobalLabel !== null) {
            $html .= $adminPageRenderer->renderFamilyFilterNotice($familyFilterGlobalLabel, $lang);
        }

        // @id-Referenz-Fragmente (id_reference/id_reference_or_literal-Widgets)
        // je Sektion einmalig ermitteln - siehe SchemaOrgData_IdReferenceService.
        $availableFragments = $idReferenceService->resolveAvailableGlobalFragments(
            $scopeResolver,
            $schemaRepository,
            $settings,
            $pluginSelfDir,
            $lang,
            $personsRegistryService
        );

        foreach ($availableTypes as $type => $schema) {
            $display = ($type === $selectedType) ? '' : ' style="display:none"';
            $extensionOverride = null;

            if ($postScope !== null and $type === $selectedType) {
                $postData = is_array($postScope['data'] ?? null) ? $postScope['data'] : [];
                $data = $configSaveService->sanitizePostData($postData, $schema, $schemaRepository, $openingHoursHelper, $validator);
                $extensionOverride = (string) ($postScope['extension'][$type] ?? '');

                // Öffnungszeiten: die rohen Pro-Tag-Werte aus dem POST statt
                // des verlustbehafteten Roundtrips über buildOpeningHoursArray()/
                // parseOpeningHours() verwenden, damit Felder mit ungültigem
                // Zeitformat beim Re-Display nicht geleert werden (siehe
                // renderOpeningHoursWidget). Geo: dieselbe Regel - sanitizePostData()
                // liefert für "geo" nur dann ein Ergebnis, wenn BEIDE Werte gefüllt
                // sind (Paar-Pflicht); wird das Speichern durch genau diese
                // Paar-Pflicht blockiert (nur eines der beiden Felder gefüllt),
                // ginge ohne diesen Override auch der bereits gültige Wert des
                // angefassten Feldes beim Re-Display verloren.
                foreach ($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $schemaRepository->resolveSchemaRef($propSchema, $schema);
                    $rawWidget = $propSchema['ui:widget'] ?? '';
                    if (($rawWidget === 'opening_hours' or $rawWidget === 'geo') and is_array($postData[$propName] ?? null)) {
                        $data[$propName] = $postData[$propName];
                    }
                }
            } else {
                $data = is_array($config[$type] ?? null) ? $config[$type] : [];
            }

            $typeIdPrefix = $idPrefix . '_' . $type;
            $inheritable = $configSaveService->resolveInheritableFields($scope, $keyCat, $keyPage, $type, $lang, $scopeResolver, $settings, $adminPageRenderer);

            $html .= '<div class="schemaOrgData-type-fields" data-schema-type="' . htmlspecialchars($type, ENT_QUOTES, CHARSET) . '"' . $display . '>' . "\n";
            $html .= $formRenderer->renderTypeFields(
                $scope,
                $type,
                $schema,
                $data,
                $typeIdPrefix,
                $extensionOverride,
                $inheritable,
                $dataSplitHelper,
                $lang,
                $schemaRepository,
                $urlHelper,
                $pluginLang,
                $pluginSelfUrl,
                $openingHoursHelper,
                $validator,
                $weekdayLang,
                $availableFragments,
            );

            // Organisations-Relationen (founder/employee/member, siehe
            // SchemaOrgData_OrgRelationsService): erscheinen ausschließlich
            // global und ausschließlich für Types mit der globalen
            // Organisations-Identität ("ui:idFragment": "organization",
            // siehe README.md, "Organisations-Identität und @id-Anker"). Liegt
            // innerhalb des .schemaOrgData-type-fields-Wrappers, damit
            // applyTypeFieldsState() (validator.js) die Felder bei
            // Typ-Wechsel korrekt (de)aktiviert (last-value-wins-Schutz).
            if ($scope === 'global' and ($schema['ui:idFragment'] ?? '') === 'organization') {
                $orgRelationsRaw = ($postScope !== null and $type === $selectedType)
                    ? (is_array($postScope['org_relations'] ?? null) ? $postScope['org_relations'] : [])
                    : (is_array($config['org_relations'] ?? null) ? $config['org_relations'] : []);

                $availablePersons = [];
                foreach ($availableFragments as $fragment => $fragLabel) {
                    if (str_starts_with($fragment, 'person-')) {
                        $availablePersons[substr($fragment, strlen('person-'))] = $fragLabel;
                    }
                }

                $html .= $formRenderer->renderOrgRelationsWidget($scope, $orgRelationsRaw, $typeIdPrefix, $lang, $availablePersons);

                // Übernahme-Vorschläge für Personen-Literale im Erweiterungsfeld
                // (siehe SchemaOrgData_PersonSuggestionService): Erkennungsbasis ist
                // im Normalfall die persistierte Konfiguration. Direkt nach einem
                // erfolgreichen Import ($importApplied) liegt "employee"/"founder"/
                // "member" jedoch noch als Teil der rohen Erweiterungsfeld-JSON-
                // Zeichenkette im POST-Redisplay vor, nicht als bereits gespeichertes
                // Array (siehe SchemaOrgData_AdminRequestHandler::handleImportAction())
                // - diese Rohdaten werden hier zusätzlich dekodiert, damit der
                // Vorschlag nicht erst ein zusätzliches Speichern erfordert. Bei
                // fehlgeschlagenem Speichern ohne Import bleibt es bei keinem
                // Vorschlag, damit unvalidierte POST-Rohdaten keinen Vorschlag
                // auslösen.
                $suggestionTypeConfig = null;
                if($postScope === null) {
                    $suggestionTypeConfig = is_array($config[$type] ?? null) ? $config[$type] : [];
                } elseif($importApplied and $type === $selectedType) {
                    $extensionRaw = (string) ($postScope['extension'][$type] ?? '');
                    $decoded = ($extensionRaw !== '') ? json_decode($extensionRaw, true) : null;
                    $suggestionTypeConfig = is_array($decoded) ? $decoded : [];
                }

                if($suggestionTypeConfig !== null) {
                    $suggestions = $personSuggestionService->detectSuggestions(
                        $suggestionTypeConfig,
                        $personsRegistryService,
                        $settings
                    );
                    $html .= $personSuggestionService->renderSuggestionNotice($suggestions, $type, $lang, $typeIdPrefix, $postScope !== null);
                }
            }

            $html .= '</div>' . "\n";
        }

        if ($scope === 'global') {
            if ($postScope !== null) {
                $excludedCats = [];
                foreach ((array) ($postScope['excluded_cats'] ?? []) as $excludedCat) {
                    $excludedCat = $scopeResolver->sanitizeScopeIdentifier(trim((string) $excludedCat));
                    if ($excludedCat !== '') {
                        $excludedCats[] = $excludedCat;
                    }
                }
                $debugOutput = !empty($postScope['debug_output']);
            } else {
                $excludedCats = !empty($config['excluded_cats'])
                    ? array_map('trim', explode(',', (string) $config['excluded_cats']))
                    : [];
                $debugOutput = !empty($config['debug_output']);
            }
            $html .= $adminPageRenderer->renderExcludedCatsField($excludedCats, $debugOutput, $lang, $scopeResolver);
        }

        $html .= '</div>' . "\n";

        // Inaktive Sektionen werden vorgerendert, aber deaktiviert,
        // damit beim Speichern nur die aktive Sektion übertragen wird
        // (initScopeSelector aktiviert/deaktiviert beim Umschalten erneut)
        if (!$active) {
            $html = (string) preg_replace('/<(input|select|textarea)(\s)/i', '<$1 disabled="disabled"$2', $html);
        }

        return $html;
    }

    /***************************************************************
     *
     * Rendert die vollständige Admin-UI (schema-getriebenes
     * Konfigurationsformular, Geltungsbereiche Global / Kategorie /
     * Seite) im PLUGINADMIN-Kontext (Iframe-Dialog der Plugin-
     * Verwaltung). Wird von getContent() zurückgegeben, sobald
     * PLUGINADMIN definiert ist.
     *
     * Enthält $_POST-Daten (Formular wurde abgeschickt), werden diese
     * zuerst über handlePostRequest() validiert und gespeichert; das
     * Ergebnis wird als Hinweisblock (renderSaveResultNotice())
     * oberhalb der Geltungsbereiche ausgegeben.
     *
     * Das Formular wird mit einem echten <form>-Element ausgegeben
     * (analog MetaKeywordsDescription - PLUGINADMIN und ACTION sind
     * im Iframe-Kontext definiert): die moziloCMS-Pflichtfelder
     * "pluginadmin" und "action" werden als hidden inputs mitgesendet,
     * der Speichern-Button ist ein echter <button type="submit">.
     * saveConfig() (aufgerufen über handlePostRequest()) persistiert
     * über $this->settings->set(), das im IS_ADMIN-Kontext sofort in
     * die plugin.conf.php schreibt - kein Nachspeichern durch den Kern
     * nach Rückgabe dieser Methode, kein eigener JS-Workaround nötig.
     *
     * Damit der Scope-Wechsel ohne Page-Reload funktioniert, werden
     * alle Geltungsbereiche (Global + alle Kategorien + alle Seiten
     * aller Kategorien) vorgerendert. Nur die aktive Sektion ist
     * sichtbar und ihre Felder sind nicht disabled;
     * initScopeSelector() (validator.js) schaltet beim Wechsel des
     * Geltungsbereichs Sichtbarkeit und disabled-Status um, damit
     * beim Speichern nur die aktive Sektion übertragen wird.
     *
     * @param SchemaOrgData_AdminRequestContext $context Laufzeit-Kollaboratoren (siehe dort)
     *
     ***************************************************************/
    public function renderAdminPage(SchemaOrgData_AdminRequestContext $context): string {
        global $CatPage;
        global $CMS_CONF;

        $settings = $context->settings;
        $lang = $context->lang;
        $scopeResolver = $context->scopeResolver;
        $schemaRepository = $context->schemaRepository;
        $pluginSelfDir = $context->pluginSelfDir;
        $pluginSelfUrl = $context->pluginSelfUrl;
        $validator = $context->validator;
        $openingHoursHelper = $context->openingHoursHelper;
        $collisionDetector = $context->collisionDetector;
        $adminPageRenderer = $context->adminPageRenderer;
        $adminRequestHandler = $context->adminRequestHandler;
        $configSaveService = $context->configSaveService;

        $importService = $context->importService;
        $dataSplitHelper = $context->dataSplitHelper;

        // Personen-Registry (vierter Admin-Bereich, siehe README.md) hat
        // Vorrang vor der normalen Scope-Verarbeitung, sobald ein
        // "schemaOrgData_persons_action"-Submit-Button geklickt wurde -
        // analog zum bereits bestehenden Vorrang des Imports
        // (handleImportAction()). Die noch im POST enthaltenen
        // schemaOrgData[...]-Felder der zuletzt aktiven Scope-Sektion
        // werden in diesem Fall bewusst ignoriert (unverändertes Redisplay).
        $isPersonsAction = isset($_POST['schemaOrgData_persons_action']);
        $personsSaveResult = $isPersonsAction ? $context->personsAdminRequestHandler->handlePersonsPostRequest(
            $settings,
            $lang,
            $context->personsRegistryService,
            $validator
        ) : null;

        $saveResult = (!$isPersonsAction and $_POST !== []) ? $adminRequestHandler->handlePostRequest(
            $settings,
            $lang,
            $scopeResolver,
            $schemaRepository,
            $pluginSelfDir,
            $validator,
            $openingHoursHelper,
            $adminPageRenderer,
            $configSaveService,
            $importService,
            $dataSplitHelper,
            $context->personsRegistryService,
            $context->orgRelationsService,
            $context->personSuggestionService
        ) : null;

        // Bei fehlgeschlagenem Speichern wird die aktive Sektion in
        // renderScopeSection() mit den POST-Daten statt mit dem
        // gespeicherten Konfigurations-Stand befüllt (siehe dort).
        $saveFailed = ($saveResult !== null and $saveResult['success'] === false);

        // Nach einem Import (Erfolg ODER Fehlschlag) muss die aktive Sektion
        // ebenfalls aus POST-Daten statt aus der gespeicherten Konfiguration
        // befüllt werden - bei Erfolg enthält $_POST['schemaOrgData'][$scope]
        // das Import-Ergebnis (siehe handleImportAction()), bei Fehlschlag
        // die ursprünglichen Formularwerte.
        $importApplied = ($saveResult['import'] ?? false) === true;
        $usePostData = $saveFailed || $importApplied;

        // Aktiven Scope ermitteln: $_POST (Formular wurde abgeschickt) hat
        // Vorrang vor $_GET (initialer Aufruf der Admin-Seite)
        $selectedCat = null;
        $selectedPage = null;
        if (isset($_POST['schemaOrgData_cat'])) {
            $selectedCat = $scopeResolver->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_cat']) ?: null;
        } elseif (isset($_GET['schemaOrgData_cat'])) {
            $selectedCat = $scopeResolver->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']) ?: null;
        }
        if (isset($_POST['schemaOrgData_page'])) {
            $selectedPage = $scopeResolver->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_page']) ?: null;
        } elseif (isset($_GET['schemaOrgData_page'])) {
            $selectedPage = $scopeResolver->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_page']) ?: null;
        }

        // Aktive Personen-Ansicht (Liste/Anlegen/Bearbeiten) und ggf.
        // POST-Redisplay-Daten nach fehlgeschlagener Personen-Aktion -
        // analog zu $saveFailed/$usePostData der Scope-Sektionen oben.
        $personsAdminRenderer = $context->personsAdminRenderer;
        $personsActiveView = $personsAdminRenderer->listViewId();
        $personsRedisplayData = [];

        if ($personsSaveResult !== null and $personsSaveResult['success'] === false) {
            $personsRedisplayData = is_array($_POST['schemaOrgData_persons_data'] ?? null) ? $_POST['schemaOrgData_persons_data'] : [];
            $personsActiveView = ($personsSaveResult['action'] === 'update' and $personsSaveResult['slug'] !== null)
                ? $personsAdminRenderer->buildEditViewId($personsSaveResult['slug'])
                : $personsAdminRenderer->newViewId();
        }

        // Lokalisierte Texte für die clientseitige Validierung (validator.js)
        $messages = [
            'postalCode'         => $lang->getLanguageValue('error_postal_code_format'),
            'telephone'          => $lang->getLanguageValue('error_telephone_format'),
            'urlInvalid'         => $lang->getLanguageValue('error_url_invalid'),
            'urlHttpWarning'     => $lang->getLanguageValue('warning_url_http'),
            'emailInvalid'       => $lang->getLanguageValue('error_email_invalid'),
            'openingHoursFormat'     => $lang->getLanguageValue('error_opening_hours_format'),
            'openingHoursIncomplete' => $lang->getLanguageValue('error_opening_hours_incomplete'),
            'openingHoursOrder'      => $lang->getLanguageValue('error_opening_hours_order'),
            'openingHoursOverlap'    => $lang->getLanguageValue('error_opening_hours_overlap'),
            'geoLatitude'        => $lang->getLanguageValue('error_geo_latitude'),
            'geoLongitude'       => $lang->getLanguageValue('error_geo_longitude'),
            'geoIncomplete'      => $lang->getLanguageValue('error_geo_incomplete'),
            'dateInvalid'        => $lang->getLanguageValue('error_date_invalid'),
            'dateRangeInvalid'   => $lang->getLanguageValue('error_date_range_invalid'),
            'dateInPast'         => $lang->getLanguageValue('warning_date_in_past'),
            'sortOrderInvalid'   => $lang->getLanguageValue('warning_sort_order_invalid'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt (Default von $param1) - die Ersetzung mit dem
            // Property-Namen erfolgt erst clientseitig in
            // initExtensionFieldValidation() (validator.js).
            'unknownProperty'    => $lang->getLanguageValue('warning_unknown_property', '{PARAM1}'),
            'extensionSchemaUnavailable' => $lang->getLanguageValue('warning_extension_schema_unavailable'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt (Default von $param1) - die Ersetzung mit dem
            // Property-Namen erfolgt erst clientseitig in
            // showExtensionFeedback() (validator.js).
            'personSuggestionCandidate' => $lang->getLanguageValue('hint_extension_person_candidate', '{PARAM1}'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt - die Ersetzung mit dem Namen der kollidierenden
            // Person erfolgt erst clientseitig in
            // runPersonSlugValidation() (validator.js).
            'personSlugCollision' => $lang->getLanguageValue('error_person_slug_exists', '{PARAM1}'),
            'jsonInvalid'        => $lang->getLanguageValue('error_json_invalid'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt (Default von $param1) - die Ersetzung mit dem
            // tatsächlichen Bereichsnamen erfolgt erst clientseitig
            // in showUnsavedNotice() (validator.js).
            'unsavedChanges'     => $lang->getLanguageValue('notice_unsaved_changes', '{PARAM1}'),
        ];

        $formAction = URL_BASE . ADMIN_DIR_NAME . '/index.php';
        $saveButtonLabel = $adminPageRenderer->buildSaveButtonLabel($selectedCat, $selectedPage, $lang);

        $html = '<style>' . $adminPageRenderer->getAdminCss() . '</style>' . "\n";
        $html .= '<form method="POST" action="' . htmlspecialchars($formAction, ENT_QUOTES, CHARSET) . '">' . "\n";
        $html .= '<input type="hidden" name="pluginadmin" value="' . PLUGINADMIN . '" />' . "\n";
        $html .= '<input type="hidden" name="action" value="' . ACTION . '" />' . "\n";
        // Die lokalisierten Texte reisen als data-Attribut des
        // Formular-Containers zum Client, den validator.js über
        // .schemaOrgData-admin findet. htmlspecialchars() mit ENT_QUOTES
        // kodiert alle Zeichen, die den Attributwert vorzeitig beenden
        // oder eigenes Markup einschleusen könnten (Anführungszeichen und
        // Winkelklammern) - ein Sprachdatei-Wert bleibt damit auch dann
        // reiner Text, wenn er selbst nach HTML aussieht.
        $html .= '<div class="schemaOrgData-admin" data-messages="'
            . htmlspecialchars(
                json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES,
                CHARSET
            ) . '">' . "\n";

        if ($saveResult !== null) {
            // Import-Erfolg zeigt einen eigenen Hinweis statt der
            // Speicher-Erfolgsmeldung - es wurde nichts gespeichert.
            $successMessageKey = $importApplied ? 'notice_import_success' : 'notice_config_saved';
            $html .= $adminPageRenderer->renderSaveResultNotice($saveResult, $lang, $successMessageKey);
        }

        if ($personsSaveResult !== null) {
            $personsSuccessKey = match ($personsSaveResult['action']) {
                'create' => 'notice_person_created',
                'update' => 'notice_person_updated',
                'delete' => 'notice_person_deleted',
                default  => 'notice_config_save_error',
            };
            $html .= $adminPageRenderer->renderSaveResultNotice($personsSaveResult, $lang, $personsSuccessKey);
        }

        $html .= '<div id="schemaOrgData_scope_container"' . ($isPersonsAction ? ' style="display:none"' : '') . '>' . "\n";

        // Personen-Registry-Umschalter: blendet den Scope-Bereich aus und
        // den Personen-Bereich ein - vollständig entkoppelt von
        // initScopeSelector() (siehe SchemaOrgData_PersonsAdminRenderer,
        // Docblock). Lebt im Scope-Container, nicht davor, damit der Button
        // ausschließlich dort erscheint, wo er tatsächlich navigierbar ist -
        // das Gegenstück (Zurück-Button) rendert SchemaOrgData_PersonsAdminRenderer
        // entsprechend innerhalb des Personen-Containers.
        // Beide Buttons am Formularanfang teilen sich eine Leiste:
        // Personen-Umschalter links, Speichern rechts. Der gemeinsame
        // Flex-Container ist nötig, weil die beiden Blöcke sonst
        // untereinander und unterschiedlich ausgerichtet stehen -
        // schemaOrgData-save-bar bringt "text-align: right" mit,
        // schemaOrgData-persons-toggle nicht.
        $html .= '<div class="schemaOrgData-admin-toolbar">' . "\n";

        $html .= '<div class="schemaOrgData-persons-toggle">' . "\n";
        $html .= '<button type="button" id="schemaOrgData_persons_toggle_btn" class="mo-btn" '
            . 'data-action="persons-open">'
            . $lang->getLanguageHtml('button_manage_persons') . '</button>' . "\n";
        $html .= '</div>' . "\n";

        // Zusätzlicher Speichern-Button am Formularanfang (rechts in der
        // Leiste) - derselbe Submit wie der Button am Formularende, damit
        // lange Formulare nicht erst bis zum Ende gescrollt werden müssen
        $html .= '<div class="schemaOrgData-save-bar schemaOrgData-save-bar--top">' . "\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
            . $saveButtonLabel . '</button>' . "\n";
        $html .= '</div>' . "\n";

        $html .= '</div>' . "\n"; // schließt .schemaOrgData-admin-toolbar

        // Scope-Selektor rendern
        $html .= $adminPageRenderer->renderScopeSelector($selectedCat, $selectedPage, $lang);

        // Platzhalter-Hinweis: scope-unabhängig, da ein fehlender
        // {schemaOrgData}-Platzhalter im Layout-Template alle
        // Geltungsebenen gleichermaßen betrifft (siehe README.md).
        $placeholderStatus = $collisionDetector->detectPluginPlaceholderInTemplateAdmin($CMS_CONF, 'schemaOrgData');
        $html .= $adminPageRenderer->renderPlaceholderMissingNotice($placeholderStatus, 'schemaOrgData', $lang);

        // Basis-URL-Hinweis: ebenfalls scope-unabhängig, denn die @id-Anker
        // aller drei Geltungsbereiche entstehen aus derselben Basis-URL.
        $html .= $adminPageRenderer->renderBaseUrlNotice($context->urlHelper->resolveFrontendBaseUrl(), $lang);

        // Template-Kollisionserkennung: im Admin-Kontext (IS_ADMIN) live prüfen.
        // Ein im Layout-Template eingebundener JSON-LD-Block ist layoutweit
        // und damit kein seiten-/kategoriespezifisches Signal - das Ergebnis
        // wird deshalb unabhängig vom aktiven Scope ausschließlich dem
        // Global-Scope zugeordnet (siehe README.md). Properties::set()
        // schreibt nur im IS_ADMIN-Kontext auf die Platte; außerhalb
        // davon ist set() ein No-Op, weshalb das Frontend den
        // Layout-Zustand live liest statt ihn zu speichern.
        // Reihenfolge: erst saveScopeMeta(), dann renderScopeSection(),
        // damit renderExistingJsonLdNotice() das frisch gesetzte Flag
        // sieht.
        $templateBlocks = array_values(array_map('trim', $collisionDetector->extractExistingJsonLdBlocksFromTemplateAdmin($CMS_CONF)));
        $templateHasJsonLd = !empty($templateBlocks);
        $templateContent = implode("\n\n", $templateBlocks);

        // Schreib-Guard: nur bei tatsächlicher Änderung persistieren, um
        // nicht bei jedem Admin-Load einen file_put_contents auszulösen.
        // Der Blocks-Vergleich greift zusätzlich bei reiner
        // Reihenfolge-Änderung, bei der Flag und implodierter Content
        // gleich blieben.
        $metaGlobal = $scopeResolver->loadScopeMeta($settings, 'global');
        if (
            $metaGlobal['existing_jsonld'] !== $templateHasJsonLd
            || $metaGlobal['existing_jsonld_content'] !== $templateContent
            || $metaGlobal['existing_jsonld_blocks'] !== $templateBlocks
        ) {
            // Rückgabewert bewusst verworfen: renderAdminPage() liefert
            // ausschließlich HTML und hat keinen Fehlerkanal - die
            // Ergebnismeldung oben stammt aus der POST-Verarbeitung und
            // gehört fachlich zum Speichern der Nutzereingaben, nicht zu
            // dieser beiläufigen Kollisions-Metadatenschreibung. Ein
            // Fehlschlag ist über das error_log in saveScopeMeta()
            // nachvollziehbar und wirkt sich nur darauf aus, dass der
            // Kollisionshinweis beim nächsten Aufruf erneut ermittelt wird.
            $scopeResolver->saveScopeMeta($settings, 'global', [
                'existing_jsonld' => $templateHasJsonLd,
                'existing_jsonld_content' => $templateContent,
                'existing_jsonld_blocks' => $templateBlocks,
            ]);
        }

        // Global immer rendern (aktiv wenn keine Kategorie gewählt)
        $html .= $this->renderScopeSection(
            'global',
            null,
            null,
            active: $selectedCat === null,
            idPrefix: 'global',
            saveFailed: $usePostData,
            importApplied: $importApplied,
            context: $context
        );

        // Alle Kategorien vorrendern
        $allCats = (isset($CatPage) && is_object($CatPage))
            ? $CatPage->get_CatArray(false, false, [EXT_PAGE, EXT_HIDDEN])
            : [];

        foreach ($allCats as $cat) {
            // $selectedCat/$selectedPage stammen aus sanitizeScopeIdentifier()
            // (siehe oben) - $cat/$page von get_CatArray()/get_PageArray()
            // müssen für den Vergleich ebenso sanitiert werden, sonst bleibt
            // die gerade gespeicherte Kategorie/Seite bei Bezeichnern mit
            // Zeichen außerhalb [a-zA-Z0-9_\-%] inaktiv (display:none,
            // disabled) und renderScopeSection() füllt das Formular aus
            // $config statt aus den POST-Daten - bei fehlgeschlagenem Save
            // einer neuen Kategorie/Seite wirkt das wie geleerte Feldwerte.
            $safeCat   = $scopeResolver->sanitizeScopeIdentifier($cat);
            $catActive = ($safeCat === $selectedCat && $selectedPage === null);
            $html .= $this->renderScopeSection(
                'category',
                $cat,
                null,
                active: $catActive,
                idPrefix: 'cat_' . $safeCat,
                saveFailed: $usePostData,
                importApplied: $importApplied,
                context: $context
            );

            // Seiten aller Kategorien vorrendern - inaktive erhalten display:none
            if (
                isset($CatPage) && is_object($CatPage)
                && method_exists($CatPage, 'get_PageArray')
            ) {
                $pages = $CatPage->get_PageArray($cat, [EXT_PAGE, EXT_HIDDEN], true);
                foreach ($pages as $page) {
                    $safePage   = $scopeResolver->sanitizeScopeIdentifier($page);
                    $pageActive = ($safeCat === $selectedCat && $safePage === $selectedPage);

                    // Seiteninhalts-Kollisionserkennung: Ein Block im
                    // Seiteninhalt gilt für genau diese Seite und wird
                    // deshalb dem Seiten-Scope zugeordnet - anders als der
                    // layoutweit gültige Template-Treffer oben.
                    // Gelesen wird mit dem rohen Bezeichner (der Kern
                    // schlägt ihn im CatPageArray nach), geschrieben mit dem
                    // sanitierten: So bildet jeder andere Schreib- und
                    // Lesepfad den Settings-Schlüssel, ein roher Schlüssel
                    // hier bliebe ungelesen.
                    // Reihenfolge wie im Global-Pfad: erst schreiben, dann
                    // rendern, damit renderExistingJsonLdNotice() das frisch
                    // gesetzte Flag sieht.
                    $pageBlocks = array_values(array_map('trim',
                        $collisionDetector->extractExistingJsonLdBlocksFromPage($CatPage, $cat, $page)));
                    $pageHasJsonLd = !empty($pageBlocks);
                    $pageJsonLdContent = implode("\n\n", $pageBlocks);

                    // Schreib-Guard wie im Global-Pfad, hier zusätzlich mit
                    // Gewicht: Ohne ihn entstünde bei jedem Admin-Load für
                    // jede Seite ein config_page_*-Schlüssel mit _meta-Block.
                    // Mit Guard entsteht er nur für Seiten, die tatsächlich
                    // einen Block tragen.
                    $metaPage = $scopeResolver->loadScopeMeta($settings, 'page', $safeCat, $safePage);
                    if (
                        $metaPage['existing_jsonld'] !== $pageHasJsonLd
                        || $metaPage['existing_jsonld_content'] !== $pageJsonLdContent
                        || $metaPage['existing_jsonld_blocks'] !== $pageBlocks
                    ) {
                        $scopeResolver->saveScopeMeta($settings, 'page', [
                            'existing_jsonld' => $pageHasJsonLd,
                            'existing_jsonld_content' => $pageJsonLdContent,
                            'existing_jsonld_blocks' => $pageBlocks,
                        ], $safeCat, $safePage);
                    }
                    $html .= $this->renderScopeSection(
                        'page',
                        $cat,
                        $page,
                        active: $pageActive,
                        idPrefix: 'page_' . $safeCat . '_' . $safePage,
                        saveFailed: $usePostData,
                        importApplied: $importApplied,
                        context: $context
                    );
                }
            }
        }

        // Scope-Hidden-Inputs immer rendern - JS aktualisiert value beim
        // Scope-Wechsel (initScopeSelector); resolveScopeIdentifiers()
        // wertet sie für den POST-Geltungsbereich aus.
        $html .= '<input type="hidden" id="schemaOrgData_hidden_cat"'
            . ' name="schemaOrgData_cat"'
            . ' value="' . htmlspecialchars($selectedCat ?? '', ENT_QUOTES, CHARSET) . '" />' . "\n";
        $html .= '<input type="hidden" id="schemaOrgData_hidden_page"'
            . ' name="schemaOrgData_page"'
            . ' value="' . htmlspecialchars($selectedPage ?? '', ENT_QUOTES, CHARSET) . '" />' . "\n";

        // Speichern-Button: echter Submit-Button innerhalb des
        // umgebenden <form> - kein verschachteltes Formular und kein
        // JS-Workaround mehr nötig
        $html .= '<div class="schemaOrgData-save-bar">' . "\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
            . $saveButtonLabel . '</button>' . "\n";
        $html .= '</div>' . "\n";

        $html .= '</div>' . "\n"; // schließt #schemaOrgData_scope_container

        $html .= $personsAdminRenderer->renderPersonsSection(
            $settings,
            $lang,
            $context->personsRegistryService,
            $validator,
            $context->urlHelper,
            $context->formRenderer,
            $isPersonsAction,
            $personsActiveView,
            $personsRedisplayData,
            $personsSaveResult['errors'] ?? []
        );

        $html .= '</div>' . "\n"; // schließt .schemaOrgData-admin
        $html .= '</form>' . "\n";

        // Beide Skripte werden hier direkt als <script src="…"> ausgegeben und
        // bewusst nicht über $PLUGIN_ADMIN_ADD_HEAD angemeldet: Der Kern
        // durchsucht diesen Kopfbereich nach <script ... src=...> und zieht
        // jede Datei, deren Pfad nicht auf ".min.js" endet, aus dem <head>
        // heraus in ein gepacktes Inline-Bündel (in Kernversion 3.0.4 fest
        // aktiviert, nicht abschaltbar). validator.js käme über diesen Kanal
        // also inline im Seitenquelltext an - das Gegenteil einer
        // ausgelagerten Skriptdatei, und schwer zu bemerken, weil die
        // Einbindung im PHP-Quelltext weiterhin extern aussieht. Die direkte
        // Ausgabe samt Cache-Buster bleibt deshalb.
        $html .= '<script src="' . $pluginSelfUrl . 'js/ajv.min.js?v=' . $context->urlHelper->resolveAssetCacheBuster($pluginSelfDir, 'js/ajv.min.js') . '"></script>' . "\n";
        $html .= '<script src="' . $pluginSelfUrl . 'js/validator.js?v=' . $context->urlHelper->resolveAssetCacheBuster($pluginSelfDir, 'js/validator.js') . '"></script>' . "\n";

        return $html;
    }
}
