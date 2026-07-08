<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminController
*
* Admin-Formular: Orchestrierung (Refactoring-Schritt 12b, "Ebene B"):
* Sektions-Rendering (renderScopeSection()) und die vollständige
* Admin-Seite (renderAdminPage()). Siehe doc/adr_komponenten_refactoring.md.
* Die reinen Anzeige-Bausteine (Info-Block, Scope-Label/Selektor,
* Speichern-Button-Beschriftung, Speicher-Ergebnis-Hinweis, Hinweis
* auf vorhandenes/kollidierendes JSON-LD, Ausschlussliste, Admin-CSS,
* Schema-Type-Auswahl) sind seit Fahrplan-Schritt 4 in
* SchemaOrgData_AdminPageRenderer ausgelagert, die POST-Verarbeitung
* (handlePostRequest()) seit Fahrplan-Schritt 5 in
* SchemaOrgData_AdminRequestHandler, die feldweise Vererbungsanzeige
* (resolveInheritableFields()), POST-Sanitizing (sanitizePostData(),
* sanitizeAddressData()) und Speichern/Validieren (saveConfig()) seit
* Fahrplan-Schritt 6 in SchemaOrgData_ConfigSaveService - alle drei
* siehe doc/adr_ziel_architektur.md.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
* SchemaOrgData_SchemaRepository, SchemaOrgData_FormRenderer,
* SchemaOrgData_Validator, SchemaOrgData_OpeningHoursHelper,
* SchemaOrgData_CollisionDetector, SchemaOrgData_IdReferenceService,
* SchemaOrgData_AdminPageRenderer, SchemaOrgData_ConfigSaveService,
* $this->settings, PLUGIN_SELF_DIR/PLUGIN_SELF_URL) werden je Aufruf
* als Parameter übergeben, nicht im Konstruktor eingefroren (siehe
* README.md, Abschnitt "Architektur").
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
    *        renderAdminPage(), $usePostData, sowie
    *        doc/adr_import_verdrahtung.md, Entscheidung (e))
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

        $idPrefix = $idPrefix ?? $scope;
        $config = $scopeResolver->loadScopeConfig($settings, $scope, $cat, $page);

        // Bei fehlgeschlagenem Speichern: die aktive Sektion mit den
        // POST-Daten statt mit dem gespeicherten Konfigurations-Stand
        // befüllen, damit fehlerhafte Eingaben nicht verloren gehen
        // (siehe renderAdminPage()).
        $postScope = null;
        if($active and $saveFailed and is_array($_POST['schemaOrgData'][$scope] ?? null)) {
            $postScope = $_POST['schemaOrgData'][$scope];
        }

        // verfügbare Schema-Types für diesen Geltungsbereich ermitteln
        $availableTypes = [];
        foreach($schemaRepository->getAvailableSchemaTypes($pluginSelfDir) as $type) {
            $schema = $schemaRepository->loadSchema($pluginSelfDir, $type);
            if($schema !== null and in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $availableTypes[$type] = $schema;
            }
        }

        // LocalBusiness-Familie: bei Kategorie/Seite nur den bei Global
        // aktiven Familien-Type anbieten, siehe
        // doc/adr_localbusiness_familie_scope.md.
        $familyFilterGlobalLabel = null;
        if($scope !== 'global') {
            $globalConfig = $scopeResolver->loadScopeConfig($settings, 'global');
            $globalActiveType = $schemaRepository->resolveActiveType($globalConfig, $pluginSelfDir);
            $globalSchema = $globalActiveType !== null
                ? $schemaRepository->loadSchema($pluginSelfDir, $globalActiveType) : null;
            $globalFamily = $globalSchema['ui:family'] ?? null;

            if($globalFamily !== null) {
                foreach($availableTypes as $type => $schema) {
                    $family = $schema['ui:family'] ?? null;
                    if($family === $globalFamily and $type !== $globalActiveType) {
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
        if($postScope !== null) {
            $postedType = (string) ($postScope['type'] ?? '');
            if(isset($availableTypes[$postedType])) {
                $selectedType = $postedType;
            }
        }
        if($selectedType === null) {
            foreach(array_keys($config) as $type) {
                if(isset($availableTypes[$type])) {
                    $selectedType = $type;
                    break;
                }
            }
        }

        $catAttr       = htmlspecialchars($cat ?? '', ENT_QUOTES, CHARSET);
        $pageAttr      = htmlspecialchars($page ?? '', ENT_QUOTES, CHARSET);
        $labelAttr     = htmlspecialchars($adminPageRenderer->buildScopeLabel($scope, $cat, $page, $lang), ENT_QUOTES, CHARSET);
        $saveLabelAttr = htmlspecialchars($adminPageRenderer->buildSaveButtonLabel(
            $scope === 'global' ? null : $cat,
            $scope === 'page'   ? $page : null,
            $lang
        ), ENT_QUOTES, CHARSET);
        $displayStyle = $active ? '' : ' style="display:none"';
        $html = '<div class="schemaOrgData-scope card mb" data-scope="'.$scope.'"'
              . ' data-scope-cat="'.$catAttr.'" data-scope-page="'.$pageAttr.'"'
              . ' data-scope-label="'.$labelAttr.'" data-save-label="'.$saveLabelAttr.'"'.$displayStyle.'>'."\n";
        $html .= '<h3>'.$lang->getLanguageHtml('scope_'.$scope).'</h3>'."\n";

        $html .= $adminPageRenderer->renderInfoBlock($scope, $lang);

        // Rohe Textarea-Eingabe nur erhalten, wenn der Import-Button für
        // GENAU diese Sektion abgeschickt wurde - handleImportAction()
        // (SchemaOrgData_AdminRequestHandler) löscht den POST-Rohwert bei
        // Erfolg, sodass hier zuverlässig nur der Fehlerfall übrig bleibt
        // (siehe doc/adr_import_verdrahtung.md, Entscheidung (g)).
        $importTextareaValue = $active && ($_POST['schemaOrgData_import_action'] ?? null) === $scope
            ? (string) ($_POST['schemaOrgData_import_'.$scope] ?? '')
            : '';
        $html .= $adminPageRenderer->renderExistingJsonLdNotice($scope, $cat, $page, $lang, $scopeResolver, $settings, $importTextareaValue);

        if($selectedType !== null) {
            $html .= $adminPageRenderer->renderCollisionNotice($scope, $cat, $page, $selectedType, $lang, $scopeResolver, $settings);
        }

        $html .= '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
            .'<div class="mo-in-li-l"><label for="schemaOrgData_'.$idPrefix.'_type">'.$lang->getLanguageHtml('label_schema_type').'</label></div>'
            .'<div class="mo-in-li-r">'.$adminPageRenderer->renderTypeSelector($scope, $availableTypes, $selectedType, $idPrefix, $lang).'</div>'
            .'</div>'."\n";

        if($familyFilterGlobalLabel !== null) {
            $html .= $adminPageRenderer->renderFamilyFilterNotice($familyFilterGlobalLabel, $lang);
        }

        // @id-Referenz-Fragmente (id_reference/id_reference_or_literal-Widgets)
        // je Sektion einmalig ermitteln - siehe SchemaOrgData_IdReferenceService.
        $availableFragments = $idReferenceService->resolveAvailableGlobalFragments(
            $scopeResolver, $schemaRepository, $settings, $pluginSelfDir, $lang
        );

        foreach($availableTypes as $type => $schema) {
            $display = ($type === $selectedType) ? '' : ' style="display:none"';
            $extensionOverride = null;

            if($postScope !== null and $type === $selectedType) {
                $postData = is_array($postScope['data'] ?? null) ? $postScope['data'] : [];
                $data = $configSaveService->sanitizePostData($postData, $schema, $schemaRepository, $openingHoursHelper, $validator);
                $extensionOverride = (string) ($postScope['extension'][$type] ?? '');

                // Öffnungszeiten: die rohen Pro-Tag-Werte aus dem POST statt
                // des verlustbehafteten Roundtrips über buildOpeningHoursArray()/
                // parseOpeningHours() verwenden, damit Felder mit ungültigem
                // Zeitformat beim Re-Display nicht geleert werden (siehe
                // renderOpeningHoursWidget).
                foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $schemaRepository->resolveSchemaRef($propSchema, $schema);
                    if(($propSchema['ui:widget'] ?? '') === 'opening_hours' and is_array($postData[$propName] ?? null)) {
                        $data[$propName] = $postData[$propName];
                    }
                }
            } else {
                $data = is_array($config[$type] ?? null) ? $config[$type] : [];
            }

            $typeIdPrefix = $idPrefix.'_'.$type;
            $inheritable = $configSaveService->resolveInheritableFields($scope, $cat, $page, $type, $lang, $scopeResolver, $settings, $adminPageRenderer);

            $html .= '<div class="schemaOrgData-type-fields" data-schema-type="'.htmlspecialchars($type, ENT_QUOTES, CHARSET).'"'.$display.'>'."\n";
            $html .= $formRenderer->renderTypeFields(
                $scope, $type, $schema, $data, $typeIdPrefix, $extensionOverride, $inheritable,
                $dataSplitHelper, $lang, $schemaRepository, $urlHelper,
                $pluginLang, $pluginSelfUrl, $openingHoursHelper, $validator,
                $weekdayLang, $availableFragments,
            );
            $html .= '</div>'."\n";
        }

        if($scope === 'global') {
            if($postScope !== null) {
                $excludedCats = [];
                foreach((array) ($postScope['excluded_cats'] ?? []) as $excludedCat) {
                    $excludedCat = $scopeResolver->sanitizeScopeIdentifier(trim((string) $excludedCat));
                    if($excludedCat !== '') {
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
            $html .= $adminPageRenderer->renderExcludedCatsField($excludedCats, $debugOutput, $lang);
        }

        $html .= '</div>'."\n";

        // Inaktive Sektionen werden vorgerendert, aber deaktiviert,
        // damit beim Speichern nur die aktive Sektion übertragen wird
        // (initScopeSelector aktiviert/deaktiviert beim Umschalten erneut)
        if(!$active) {
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
    * moziloCMS speichert $this->settings nach Rückgabe dieser Methode
    * automatisch - saveConfig() (aufgerufen über handlePostRequest())
    * persistiert daher zuverlässig über $this->settings->set(), ohne
    * eigenen JS-Workaround.
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

        $saveResult = ($_POST !== []) ? $adminRequestHandler->handlePostRequest(
            $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper,
            $adminPageRenderer, $configSaveService, $importService, $dataSplitHelper
        ) : null;

        // Bei fehlgeschlagenem Speichern wird die aktive Sektion in
        // renderScopeSection() mit den POST-Daten statt mit dem
        // gespeicherten Konfigurations-Stand befüllt (siehe dort).
        $saveFailed = ($saveResult !== null and $saveResult['success'] === false);

        // Nach einem Import (Erfolg ODER Fehlschlag) muss die aktive Sektion
        // ebenfalls aus POST-Daten statt aus der gespeicherten Konfiguration
        // befüllt werden - bei Erfolg enthält $_POST['schemaOrgData'][$scope]
        // das Import-Ergebnis (siehe handleImportAction()), bei Fehlschlag die
        // ursprünglichen Formularwerte (siehe doc/adr_import_verdrahtung.md,
        // Entscheidung (e)).
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

        $formAction = URL_BASE . ADMIN_DIR_NAME . '/index.php';
        $saveButtonLabel = $adminPageRenderer->buildSaveButtonLabel($selectedCat, $selectedPage, $lang);

        $html = '<style>'.$adminPageRenderer->getAdminCss().'</style>'."\n";
        $html .= '<form method="POST" action="'.htmlspecialchars($formAction, ENT_QUOTES, CHARSET).'">'."\n";
        $html .= '<input type="hidden" name="pluginadmin" value="'.PLUGINADMIN.'" />'."\n";
        $html .= '<input type="hidden" name="action" value="'.ACTION.'" />'."\n";
        $html .= '<div class="schemaOrgData-admin">'."\n";

        if($saveResult !== null) {
            // Import-Erfolg zeigt einen eigenen Hinweis statt der
            // Speicher-Erfolgsmeldung - es wurde nichts gespeichert (siehe
            // doc/adr_import_verdrahtung.md, Entscheidung (f)).
            $successMessageKey = $importApplied ? 'notice_import_success' : 'notice_config_saved';
            $html .= $adminPageRenderer->renderSaveResultNotice($saveResult, $lang, $successMessageKey);
        }

        // Zusätzlicher Speichern-Button am Formularanfang (oben rechts) -
        // derselbe Submit wie der Button am Formularende, damit lange
        // Formulare nicht erst bis zum Ende gescrollt werden müssen
        $html .= '<div class="schemaOrgData-save-bar schemaOrgData-save-bar--top">'."\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
               . $saveButtonLabel.'</button>'."\n";
        $html .= '</div>'."\n";

        // Scope-Selektor rendern
        $html .= $adminPageRenderer->renderScopeSelector($selectedCat, $selectedPage, $lang);

        // Platzhalter-Hinweis: scope-unabhängig, da ein fehlender
        // {schemaOrgData}-Platzhalter im Layout-Template alle
        // Geltungsebenen gleichermaßen betrifft (siehe README.md).
        $placeholderFound = $collisionDetector->detectPluginPlaceholderInTemplateAdmin($CMS_CONF, 'schemaOrgData');
        $html .= $adminPageRenderer->renderPlaceholderMissingNotice($placeholderFound, 'schemaOrgData', $lang);

        // Template-Kollisionserkennung: im Admin-Kontext (IS_ADMIN) live prüfen.
        // Ein im Layout-Template eingebundener JSON-LD-Block ist layoutweit
        // und damit kein seiten-/kategoriespezifisches Signal - das Ergebnis
        // wird deshalb unabhängig vom aktiven Scope ausschließlich dem
        // Global-Scope zugeordnet (siehe README.md). Properties::set()
        // schreibt im IS_ADMIN-Kontext auf die Platte (im Frontend war
        // set() ein No-Op). Reihenfolge: erst saveScopeMeta(), dann
        // renderScopeSection(), damit renderExistingJsonLdNotice() das
        // frisch gesetzte Flag und den Inhalt (Autofill-Button) sieht.
        $templateBlocks = $collisionDetector->extractExistingJsonLdBlocksFromTemplateAdmin($CMS_CONF);
        $templateHasJsonLd = !empty($templateBlocks);
        $templateContent = implode("\n\n", array_map('trim', $templateBlocks));

        // Schreib-Guard: nur bei tatsächlicher Änderung persistieren, um
        // nicht bei jedem Admin-Load einen file_put_contents auszulösen.
        $metaGlobal = $scopeResolver->loadScopeMeta($settings, 'global');
        if ($metaGlobal['existing_jsonld'] !== $templateHasJsonLd
            || $metaGlobal['existing_jsonld_content'] !== $templateContent) {
            $scopeResolver->saveScopeMeta($settings, 'global', [
                'existing_jsonld' => $templateHasJsonLd,
                'existing_jsonld_content' => $templateContent,
            ]);
        }

        // Global immer rendern (aktiv wenn keine Kategorie gewählt)
        $html .= $this->renderScopeSection(
            'global', null, null,
            active: $selectedCat === null,
            idPrefix: 'global',
            saveFailed: $usePostData,
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
                'category', $cat, null,
                active: $catActive,
                idPrefix: 'cat_' . $safeCat,
                saveFailed: $usePostData,
                context: $context
            );

            // Seiten aller Kategorien vorrendern - inaktive erhalten display:none
            if (isset($CatPage) && is_object($CatPage)
                && method_exists($CatPage, 'get_PageArray')) {
                $pages = $CatPage->get_PageArray($cat, [EXT_PAGE, EXT_HIDDEN], true);
                foreach ($pages as $page) {
                    $safePage   = $scopeResolver->sanitizeScopeIdentifier($page);
                    $pageActive = ($safeCat === $selectedCat && $safePage === $selectedPage);
                    $html .= $this->renderScopeSection(
                        'page', $cat, $page,
                        active: $pageActive,
                        idPrefix: 'page_' . $safeCat . '_' . $safePage,
                        saveFailed: $usePostData,
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
               . ' value="'.htmlspecialchars($selectedCat ?? '', ENT_QUOTES, CHARSET).'" />'."\n";
        $html .= '<input type="hidden" id="schemaOrgData_hidden_page"'
               . ' name="schemaOrgData_page"'
               . ' value="'.htmlspecialchars($selectedPage ?? '', ENT_QUOTES, CHARSET).'" />'."\n";

        // Speichern-Button: echter Submit-Button innerhalb des
        // umgebenden <form> - kein verschachteltes Formular und kein
        // JS-Workaround mehr nötig
        $html .= '<div class="schemaOrgData-save-bar">'."\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
               . $saveButtonLabel.'</button>'."\n";
        $html .= '</div>'."\n";

        $html .= '</div>'."\n";
        $html .= '</form>'."\n";

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
            'dateInvalid'        => $lang->getLanguageValue('error_date_invalid'),
            'dateRangeInvalid'   => $lang->getLanguageValue('error_date_range_invalid'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt (Default von $param1) - die Ersetzung mit dem
            // Property-Namen erfolgt erst clientseitig in
            // initExtensionFieldValidation() (validator.js).
            'unknownProperty'    => $lang->getLanguageValue('warning_unknown_property', '{PARAM1}'),
            'jsonInvalid'        => $lang->getLanguageValue('error_json_invalid'),
            // '{PARAM1}' wird hier als Wert übergeben, damit
            // getLanguageValue() den Platzhalter NICHT durch ""
            // ersetzt (Default von $param1) - die Ersetzung mit dem
            // tatsächlichen Bereichsnamen erfolgt erst clientseitig
            // in showUnsavedNotice() (validator.js).
            'unsavedChanges'     => $lang->getLanguageValue('notice_unsaved_changes', '{PARAM1}'),
        ];

        // JSON_HEX_TAG kodiert Winkelklammern in Sprachstring-Werten als Unicode-
        // Escapes und verhindert so einen Script-Break-out, falls ein
        // Sprachdatei-Wert jemals "</script>" enthalten sollte (analoges
        // Härtungsmuster zu buildJsonLdScript()/buildDebugWidget(), siehe
        // README.md, Abschnitt "JSON-LD-Ausgabe").
        $html .= '<script>window.schemaOrgDataMessages = '
            .json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).';</script>'."\n";
        $html .= '<script src="'.$pluginSelfUrl.'js/ajv.min.js"></script>'."\n";
        $html .= '<script src="'.$pluginSelfUrl.'js/validator.js"></script>'."\n";
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            .' if(window.schemaOrgDataValidator) { window.schemaOrgDataValidator.initAdminForm(); }'
            .' });</script>'."\n";

        return $html;
    }
}
