<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminController
*
* Admin-Formular: Anzeige-Bausteine (Refactoring-Schritt 12a, "Ebene A")
* - Info-Block, Scope-Label/Selektor, Speichern-Button-Beschriftung,
* Speicher-Ergebnis-Hinweis, Hinweis auf vorhandenes/kollidierendes
* JSON-LD, Ausschlussliste, Admin-CSS sowie die feldweise
* Vererbungsanzeige (resolveInheritableFields()) - sowie Orchestrierung/
* Persistenz (Refactoring-Schritt 12b, "Ebene B"): Sektions-Rendering
* (renderScopeSection()), POST-Sanitizing (sanitizePostData(),
* sanitizeAddressData()), Speichern/Validieren (saveConfig()),
* POST-Verarbeitung (handlePostRequest()) und die vollständige
* Admin-Seite (renderAdminPage()). Siehe doc/adr_komponenten_refactoring.md.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
* SchemaOrgData_SchemaRepository, SchemaOrgData_FormRenderer,
* SchemaOrgData_Validator, SchemaOrgData_OpeningHoursHelper,
* SchemaOrgData_CollisionDetector, SchemaOrgData_IdReferenceService,
* $this->settings, PLUGIN_SELF_DIR/PLUGIN_SELF_URL) werden je Aufruf
* als Parameter übergeben, nicht im Konstruktor eingefroren (siehe
* README.md, Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_AdminController {

    /***************************************************************
    *
    * Liefert das CSS für das Admin-Formular (Feedback-Farben,
    * Pflichtfeld-Kennzeichnung, Öffnungszeiten-Tabelle, FAQ-Liste
    * usw.). Wird in getConfig() in einen <style>-Block eingebettet,
    * da das Plugin keine eigene CSS-Datei in das Admin-Layout
    * einbinden kann.
    *
    ***************************************************************/
    public function getAdminCss(): string {
        return '
.schemaOrgData-admin .schemaOrgData-info { background: #eef6ff; border: 1px solid #b6d4f5; padding: .75em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--info, .schemaOrgData-admin .schemaOrgData-notice--unsaved { background: #fff8e1; border: 1px solid #ffe082; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--success { background: #e8f5e9; border: 1px solid #a5d6a7; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error { background: #fdecea; border: 1px solid #f5c6c2; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error ul { margin: .25em 0 0; padding-left: 1.5em; }
.schemaOrgData-admin .schemaOrgData-required { color: #c0392b; font-weight: bold; }
.schemaOrgData-admin .schemaOrgData-inherited { color: #888; font-weight: normal; font-style: italic; cursor: help; }
.schemaOrgData-admin input.mo-input-text::placeholder,
.schemaOrgData-admin textarea.mo-input-text::placeholder { color: #aaa; }
.schemaOrgData-admin .schemaOrgData-fieldset { border: 1px solid #ddd; border-radius: 4px; padding: 1em; margin-bottom: 1em; }
.schemaOrgData-admin .schemaOrgData-fieldset legend { font-weight: bold; padding: 0 .5em; }
.schemaOrgData-admin .schemaOrgData-hint { color: #666; font-size: .85em; margin: 0 0 .5em; }
.schemaOrgData-admin .schemaOrgData-feedback { display: block; margin-top: .25em; font-size: .9em; }
.schemaOrgData-admin .schemaOrgData-feedback--ok { color: #2e7d32; }
.schemaOrgData-admin .schemaOrgData-feedback--warning { color: #b8860b; }
.schemaOrgData-admin .schemaOrgData-feedback--error { color: #c0392b; }
.schemaOrgData-admin .schemaOrgData-opening-hours { border-collapse: collapse; }
.schemaOrgData-admin .schemaOrgData-opening-hours th, .schemaOrgData-admin .schemaOrgData-opening-hours td { padding: .25em .5em; text-align: left; }
.schemaOrgData-admin .schemaOrgData-opening-hours-group { display: flex; align-items: center; gap: 4px; }
.schemaOrgData-admin .schemaOrgData-opening-hours-group input { max-width: 80px; }
.schemaOrgData-admin .schemaOrgData-opening-hours-sep { color: #999; }
.schemaOrgData-admin .schemaOrgData-opening-hours-second { margin-top: 2px; opacity: .75; }
.schemaOrgData-admin .schemaOrgData-opening-hours-range-label { font-size: .85em; color: #666; white-space: nowrap; }
.schemaOrgData-admin .schemaOrgData-opening-hours-range-label[aria-hidden="true"] { visibility: hidden; }
.schemaOrgData-admin .schemaOrgData-faq-entry { border-top: 1px solid #eee; padding-top: .5em; margin-top: .5em; }
.schemaOrgData-admin .schemaOrgData-faq-entry:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.schemaOrgData-admin .schemaOrgData-checkbox { display: inline-block; margin: 0 1em .25em 0; }
.schemaOrgData-admin .schemaOrgData-checkbox--all { font-weight: bold; border-left: 1px solid #ccc; padding-left: 1em; }
.schemaOrgData-admin .schemaOrgData-scope-selector { display: flex; align-items: center; gap: .75em; flex-wrap: wrap; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: .6em 1em; margin-bottom: 1.25em; }
.schemaOrgData-admin .schemaOrgData-scope-selector__label { font-weight: bold; white-space: nowrap; }
.schemaOrgData-admin .schemaOrgData-scope-selector__select { min-width: 200px; }
.schemaOrgData-admin .schemaOrgData-save-bar { margin-top: 1.5em; padding: .75em 0; border-top: 1px solid #ddd; text-align: right; }
.schemaOrgData-admin .schemaOrgData-save-bar--top { margin: 0 0 1.25em; padding: 0 0 .75em; border-top: none; border-bottom: 1px solid #ddd; }
.schemaOrgData-admin .schemaOrgData-field-row { display: grid !important; grid-template-columns: 200px 1fr !important; align-items: baseline !important; gap: 4px 12px !important; margin-bottom: .5em; }
.schemaOrgData-admin .schemaOrgData-field-row .mo-in-li-l, .schemaOrgData-admin .schemaOrgData-field-row .mo-in-li-r { float: none !important; width: auto !important; padding: 0; margin: 0; }
.schemaOrgData-admin .schemaOrgData-type-selector-row { background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: .75em 1em; margin-bottom: 1.25em; }
.schemaOrgData-admin .schemaOrgData-type-selector-row .mo-in-li-l label { font-weight: bold; font-size: 1.05em; }
.schemaOrgData-admin .schemaOrgData-address-row { display: flex !important; flex-wrap: wrap; gap: 8px 12px; }
.schemaOrgData-admin .schemaOrgData-address-field { display: flex !important; flex-direction: column; flex: 1 1 160px !important; }
.schemaOrgData-admin .schemaOrgData-address-field label { font-size: .85em; color: #666; margin-bottom: 2px; }
.schemaOrgData-admin .schemaOrgData-address-field--narrow { flex: 0 0 80px !important; }
.schemaOrgData-admin .schemaOrgData-address-field--narrow input { max-width: 80px; }
.schemaOrgData-admin textarea.mo-input-text { min-height: 7.5em; }
.schemaOrgData-admin select[id$="_addressCountry"] { max-width: 200px; }
.schemaOrgData-admin input[id$="_addressRegion"] { max-width: 300px; }
.schemaOrgData-admin .schemaOrgData-idrl-container { margin-bottom: .25em; }
.schemaOrgData-admin .schemaOrgData-idrl-radio-label { display: block; margin: .4em 0 .15em; cursor: pointer; }
.schemaOrgData-admin .schemaOrgData-idrl-section { padding-left: 1.5em; margin-bottom: .25em; }
';
    }

    /***************************************************************
    *
    * Rendert den Info-Block oberhalb der Konfigurationsfelder einer
    * Geltungsebene. Der Text erklärt das Ausgabeverhalten für die
    * jeweilige Ebene (Global/Kategorie/Seite) sowie allgemein, dass
    * das JSON-LD im <head> ausgegeben wird (unsichtbar im
    * Seiteninhalt) und mit https://validator.schema.org geprüft
    * werden kann.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderInfoBlock(string $scope, Language $lang): string {
        $key = match($scope) {
            'global'   => 'info_text_global',
            'category' => 'info_text_category',
            'page'     => 'info_text_page',
            default    => '',
        };

        if($key === '') {
            return '';
        }

        // Im Global-Scope zusätzlicher Hinweis, dass eine im Layout-Template
        // erkannte JSON-LD-Kollision ausschließlich hier angezeigt wird
        // (siehe renderAdminPage(), Template-Detection ist layoutweit).
        $templateNotice = ($scope === 'global')
            ? '<p>'.$lang->getLanguageHtml('info_text_template_global').'</p>'
            : '';

        return '<div class="schemaOrgData-info">'
            .'<p>'.$lang->getLanguageHtml($key).'</p>'
            .$templateNotice
            .'<p>'.$lang->getLanguageHtml('info_text_general').'</p>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Liefert die für den Nutzer lesbare Bezeichnung eines
    * Geltungsbereichs, z. B. "Global", "Kategorie Über-uns" oder
    * "Seite kontakt". Wird als data-scope-label in
    * renderScopeSection() ausgegeben und von initScopeSelector()
    * (validator.js) für den Hinweis auf ungespeicherte Eingaben
    * beim Scope-Wechsel verwendet (Sprachschlüssel
    * notice_unsaved_changes, Platzhalter {PARAM1}).
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param Language $lang Admin-Sprachobjekt
    *
    ***************************************************************/
    public function buildScopeLabel(string $scope, ?string $cat, ?string $page, Language $lang): string {
        return match($scope) {
            'global'   => $lang->getLanguageValue('scope_global'),
            'category' => $lang->getLanguageValue('scope_category').' '.rawurldecode((string) $cat),
            'page'     => $lang->getLanguageValue('scope_page').' '.rawurldecode((string) $page),
            default    => $lang->getLanguageValue('scope_'.$scope),
        };
    }

    /***************************************************************
    *
    * Liefert den Text des Speichern-Buttons für den aktuell aktiven
    * Geltungsbereich, z. B. "Globale Konfiguration speichern",
    * "Konfiguration Kategorie Über-uns speichern" oder
    * "Konfiguration Seite kontakt speichern". Wird in
    * renderAdminPage() für beide Speichern-Buttons (oben und unten)
    * verwendet, analog zu buildScopeLabel().
    *
    * @param string|null $selectedCat  sanitierter Kategorie-Bezeichner
    *        des aktiven Scopes (siehe sanitizeScopeIdentifier()) oder
    *        null für den globalen Scope
    * @param string|null $selectedPage sanitierter Seiten-Bezeichner des
    *        aktiven Scopes oder null für Global/Kategorie
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML (bereits escaped via getLanguageHtml())
    *
    ***************************************************************/
    public function buildSaveButtonLabel(?string $selectedCat, ?string $selectedPage, Language $lang): string {
        if($selectedCat === null) {
            return $lang->getLanguageHtml('button_save_global');
        }

        if($selectedPage === null) {
            return $lang->getLanguageHtml('button_save_category', rawurldecode($selectedCat));
        }

        return $lang->getLanguageHtml('button_save_page', rawurldecode($selectedPage));
    }

    /***************************************************************
    *
    * Rendert das Ergebnis von handlePostRequest() als Hinweisblock
    * (Erfolg oder Fehlerliste) oberhalb der Geltungsebenen.
    *
    * @param array{success: bool, errors: string[]} $result
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderSaveResultNotice(array $result, Language $lang): string {
        if($result['success']) {
            return '<div id="schemaOrgData_save_notice" class="schemaOrgData-notice schemaOrgData-notice--success">'
                .$lang->getLanguageHtml('notice_config_saved')
                .'</div>'."\n";
        }

        $html = '<div id="schemaOrgData_save_notice" class="schemaOrgData-notice schemaOrgData-notice--error">'."\n";
        $html .= '<p>'.$lang->getLanguageHtml('notice_config_save_error').'</p>'."\n";
        $html .= '<ul>'."\n";

        foreach($result['errors'] as $error) {
            $html .= '<li>'.htmlspecialchars($error, ENT_QUOTES, CHARSET).'</li>'."\n";
        }

        $html .= '</ul></div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert den Hinweis- und Auswahl-Block für bereits vorhandenes
    * JSON-LD sowie das Import-Feld einer Geltungsebene.
    *
    * Vorgesehen zur Einbindung in das schema-getriebene Admin-Formular
    * (siehe render-form) innerhalb des jeweiligen Geltungsbereich-Tabs.
    * Gibt einen leeren String zurück, wenn für diese Ebene kein
    * vorhandenes JSON-LD erkannt wurde (existing_jsonld = false).
    *
    * Wichtig: kein automatischer Merge - "Vorhandenes beibehalten"
    * unterdrückt lediglich die eigene Ausgabe dieser Ebene,
    * "Überschreiben" gibt das eigene JSON-LD zusätzlich zum
    * vorhandenen Block aus.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param Language $lang Admin-Sprachobjekt
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return string HTML-Snippet (Hinweis, Radio-Buttons, Import-Textarea)
    *                 oder '' wenn kein vorhandenes JSON-LD erkannt wurde
    *
    ***************************************************************/
    public function renderExistingJsonLdNotice(
        string $scope,
        ?string $cat,
        ?string $page,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        $settings
    ): string {
        $meta = $scopeResolver->loadScopeMeta($settings, $scope, $cat, $page);

        if(!$meta['existing_jsonld']) {
            return '';
        }

        $fieldName = 'schemaOrgData_jsonld_mode_'.$scope;
        $options = ['keep' => 'option_keep_existing_jsonld', 'override' => 'option_override_existing_jsonld'];

        $html  = '<div class="schemaOrgData-jsonld-notice">'."\n";
        $html .= '<p class="schemaOrgData-jsonld-notice__title"><strong>'.$lang->getLanguageHtml('notice_existing_jsonld_title').'</strong></p>'."\n";
        $html .= '<p>'.$lang->getLanguageHtml('notice_existing_jsonld_text').'</p>'."\n";

        foreach($options as $value => $labelKey) {
            $checked = ($meta['jsonld_mode'] === $value) ? ' checked="checked"' : '';
            $html .= '<label><input type="radio" name="'.$fieldName.'" value="'.$value.'"'.$checked.' /> '
                  .$lang->getLanguageHtml($labelKey).'</label><br />'."\n";
        }

        $html .= '<p><label for="schemaOrgData_import_'.$scope.'">'.$lang->getLanguageHtml('label_import_jsonld').'</label><br />'."\n";

        if(!empty($meta['existing_jsonld_content'])) {
            $escaped = htmlspecialchars((string) $meta['existing_jsonld_content'], ENT_QUOTES, CHARSET);
            $html .= '<button type="button" class="mo-btn schemaOrgData-autofill-btn"'
                .' data-target="schemaOrgData_import_'.$scope.'"'
                .' data-existing-content="'.$escaped.'">'
                .$lang->getLanguageHtml('button_use_detected_jsonld').'</button><br />'."\n";
        }

        $html .= '<textarea id="schemaOrgData_import_'.$scope.'" name="schemaOrgData_import_'.$scope.'" rows="6"></textarea></p>'."\n";
        $html .= '<p class="schemaOrgData-jsonld-notice__hint">'.$lang->getLanguageHtml('description_import_jsonld').'</p>'."\n";
        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert den Hinweis auf eine Vererbung von einer allgemeineren
    * Ebene für denselben Type (siehe
    * SchemaOrgData_ScopeResolver::detectTypeCollision()).
    *
    * @param Language $lang Admin-Sprachobjekt
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return string HTML-Snippet oder '' wenn keine Vererbung vorliegt
    *
    ***************************************************************/
    public function renderCollisionNotice(
        string $scope,
        ?string $cat,
        ?string $page,
        string $selectedType,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        $settings
    ): string {
        $collisions = $scopeResolver->detectTypeCollision($settings, $scope, $cat, $page, $selectedType);

        if($collisions === []) {
            return '';
        }

        $scopeNames = implode(', ', array_map(
            fn($higherScope) => $lang->getLanguageValue('scope_'.$higherScope),
            $collisions
        ));

        return '<div class="schemaOrgData-notice schemaOrgData-notice--info">'
            .$lang->getLanguageHtml('notice_type_collision', $selectedType, $scopeNames)
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Ermittelt für eine Kategorie-/Seiten-Sektion, welche Feldwerte
    * eines Schema-Types von einer übergeordneten Ebene (Global bzw.
    * Global+Kategorie) geerbt würden (siehe
    * SchemaOrgData_ScopeResolver::resolveTypeInheritance()), sowie
    * die jeweilige Ursprungsebene als lesbares Label (siehe
    * buildScopeLabel()). Dient ausschließlich der Anzeige im Formular
    * (Placeholder + "ü"-Badge, siehe
    * SchemaOrgData_FormRenderer::renderInheritedBadge()) - die
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
        $settings
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
            $label = $this->buildScopeLabel($higherScope, $higherCat, $higherPage, $lang);
            foreach($higherConfig[$type] as $field => $value) {
                $data[$field] = $value;
                $originLabel[$field] = $label;
            }
        }

        return ['data' => $data, 'originLabel' => $originLabel];
    }

    /***************************************************************
    *
    * Rendert die Ausschlussliste für die globale Ausgabe (nur
    * Geltungsbereich "global"): eine Checkbox je vorhandener
    * Kategorie. Angehakte Kategorien erhalten keine globale
    * JSON-LD-Ausgabe (siehe README.md, "excluded_cats").
    * Zusätzlich wird die Debug-Modus-Checkbox gerendert.
    *
    * @param string[] $excludedCats aktuell ausgeschlossene Kategorien
    * @param bool $debugOutput      aktueller Zustand des Debug-Flags
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderExcludedCatsField(array $excludedCats, bool $debugOutput, Language $lang): string {
        global $CatPage;
        $html = '';

        // Ausschlussliste nur wenn Kategorienliste verfügbar
        if(isset($CatPage) and is_object($CatPage)) {
            $cats = $CatPage->get_CatArray(true);

            $html .= '<fieldset class="schemaOrgData-fieldset">'."\n";
            $html .= '<legend>'.$lang->getLanguageHtml('label_excluded_cats').'</legend>'."\n";
            $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_excluded_cats').'</p>'."\n";

            foreach($cats as $cat) {
                // get_CatArray(true) liefert auch das Wurzelverzeichnis
                // "kategorien" selbst zurück - das ist keine echte Kategorie
                // und wird daher nicht als Checkbox angeboten.
                if(strtolower(rawurldecode($cat)) === 'kategorien') {
                    continue;
                }

                $checked = in_array($cat, $excludedCats, true) ? ' checked="checked"' : '';
                $catLabel = htmlspecialchars($cat, ENT_QUOTES, CHARSET);
                // rawurldecode() dekodiert den moziloCMS-Bezeichner nur für die
                // Anzeige - der value-Attributwert bleibt roh (% erhalten),
                // damit excluded_cats weiterhin zu CAT_REQUEST passt.
                $catDisplayLabel = htmlspecialchars(rawurldecode($cat), ENT_QUOTES, CHARSET);
                $fieldId = 'schemaOrgData_global_excluded_cats_'.md5($cat);
                $html .= '<label class="schemaOrgData-checkbox" for="'.$fieldId.'">'
                    .'<input type="checkbox" id="'.$fieldId.'" name="schemaOrgData[global][excluded_cats][]" value="'.$catLabel.'"'.$checked.' /> '
                    .$catDisplayLabel.'</label>'."\n";
            }

            // "Alle Kategorien"-Select-All-Toggle: rein clientseitig (kein
            // name-Attribut, daher kein Einfluss auf saveConfig()/excluded_cats).
            // initExcludedCatsSelectAll() (validator.js) setzt/leert beim
            // Anklicken alle Kategorie-Checkboxen oben und zeigt bei
            // Teilauswahl einen indeterminate-Zustand.
            $html .= '<label class="schemaOrgData-checkbox schemaOrgData-checkbox--all" for="schemaOrgData_global_excluded_cats_all">'
                .'<input type="checkbox" id="schemaOrgData_global_excluded_cats_all" data-select-all="schemaOrgData[global][excluded_cats][]" /> '
                .$lang->getLanguageHtml('label_excluded_cats_all').'</label>'."\n";

            $html .= '</fieldset>'."\n";
        }

        // Debug-Modus-Checkbox (immer sichtbar, unabhängig von $CatPage)
        $checkedAttr = $debugOutput ? ' checked="checked"' : '';
        $html .= '<fieldset class="schemaOrgData-fieldset">'."\n";
        $html .= '<legend>'.$lang->getLanguageHtml('label_debug_output').'</legend>'."\n";
        $html .= '<label class="schemaOrgData-checkbox" for="schemaOrgData_global_debug_output">'
            .'<input type="checkbox" id="schemaOrgData_global_debug_output" name="schemaOrgData[global][debug_output]" value="1"'.$checkedAttr.' /> '
            .$lang->getLanguageHtml('label_debug_output').'</label>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('hint_debug_output').'</p>'."\n";
        $html .= '</fieldset>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert den Scope-Selektor als zweistufiges Select-Paar.
    *
    * Stufe 1 (#schemaOrgData_scope_cat) enthält "Global" und alle
    * Kategorien. Stufe 2 (#schemaOrgData_scope_page) enthält die
    * Seiten der gewählten Kategorie und wird clientseitig
    * (initScopeSelector(), validator.js) anhand der im data-pages-
    * Attribut hinterlegten JSON-Map (Kategorie => Seiten) befüllt
    * und ein-/ausgeblendet - ohne PHP-Roundtrip.
    *
    * moziloCMS öffnet die Plugin-Einstellungen über einen
    * JavaScript-Tab-Mechanismus — ein Page-Reload würde diesen Tab
    * schließen und auf die Info-Seite zurückspringen. Die Auswahl
    * blendet daher nur die passende .schemaOrgData-scope-Sektion
    * ein, ohne die Seite neu zu laden. Ist $CatPage nicht verfügbar,
    * wird ein leerer String zurückgegeben.
    *
    * @param string|null $selectedCat  aktuell gewählte Kategorie
    * @param string|null $selectedPage aktuell gewählte Seite
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderScopeSelector(?string $selectedCat, ?string $selectedPage, Language $lang): string {
        global $CatPage;

        if (!isset($CatPage) || !is_object($CatPage)) {
            return '';
        }

        $cats = $CatPage->get_CatArray(false, false, [EXT_PAGE, EXT_HIDDEN]);

        $html  = '<div class="schemaOrgData-scope-selector">'."\n";
        $html .= '<label class="schemaOrgData-scope-selector__label" for="schemaOrgData_scope_cat">'
               . $lang->getLanguageHtml('label_scope_selector') . '</label>'."\n";

        // Stufe 1: Global + alle Kategorien
        $html .= '<select id="schemaOrgData_scope_cat" class="mo-select schemaOrgData-scope-selector__select">'."\n";
        $html .= '<option value="">'.$lang->getLanguageHtml('scope_global').'</option>'."\n";

        // Seiten je Kategorie als JSON-Map für Stufe 2 sammeln - rawurldecode()
        // dekodiert den moziloCMS-URL-kodierten Bezeichner ("%C3%9CBer..." →
        // "Über...") nur für die Anzeige, der value-Attributwert bleibt roh.
        $pagesByCat = [];

        foreach ($cats as $cat) {
            $catAttr  = htmlspecialchars($cat, ENT_QUOTES, CHARSET);
            $catLabel = htmlspecialchars(rawurldecode($cat), ENT_QUOTES, CHARSET);
            $html .= '<option value="'.$catAttr.'">'.$catLabel.'</option>'."\n";

            $pages = $CatPage->get_PageArray($cat, [EXT_PAGE, EXT_HIDDEN], true);
            $pagesByCat[$cat] = array_map(
                fn($page) => ['value' => $page, 'label' => rawurldecode($page)],
                $pages
            );
        }

        $html .= '</select>'."\n";

        // Stufe 2: Seiten der gewählten Kategorie - wird von
        // initScopeSelector() (validator.js) anhand von data-pages befüllt,
        // initial nur sichtbar wenn bereits eine Kategorie aktiv ist
        $pagesJson = json_encode($pagesByCat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pageStyle = ($selectedCat === null) ? ' style="display:none"' : '';
        $html .= '<select id="schemaOrgData_scope_page" class="mo-select schemaOrgData-scope-selector__select"'
               . ' data-pages="'.htmlspecialchars($pagesJson, ENT_QUOTES, CHARSET).'"'.$pageStyle.'>'."\n";
        $html .= '<option value="">— '.$lang->getLanguageHtml('scope_category').' —</option>'."\n";
        $html .= '</select>'."\n";

        $html .= '</div>'."\n";

        return $html;
    }

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
    * @param Language $lang Admin-Sprachobjekt
    * @param Language $weekdayLang Sprachobjekt für Wochentag-Labels (openingHours)
    * @param string $pluginLang aktuell aufgelöste Admin-Locale (ui:enumLabels)
    * @param string $pluginSelfUrl PLUGIN_SELF_URL (Extension-Feld-Schema-URL)
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
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
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        $settings,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        SchemaOrgData_FormRenderer $formRenderer,
        SchemaOrgData_DataSplitHelper $dataSplitHelper,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginLang,
        string $pluginSelfUrl,
        Language $weekdayLang,
        SchemaOrgData_IdReferenceService $idReferenceService,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_Validator $validator
    ): string {
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
        $labelAttr     = htmlspecialchars($this->buildScopeLabel($scope, $cat, $page, $lang), ENT_QUOTES, CHARSET);
        $saveLabelAttr = htmlspecialchars($this->buildSaveButtonLabel(
            $scope === 'global' ? null : $cat,
            $scope === 'page'   ? $page : null,
            $lang
        ), ENT_QUOTES, CHARSET);
        $displayStyle = $active ? '' : ' style="display:none"';
        $html = '<div class="schemaOrgData-scope card mb" data-scope="'.$scope.'"'
              . ' data-scope-cat="'.$catAttr.'" data-scope-page="'.$pageAttr.'"'
              . ' data-scope-label="'.$labelAttr.'" data-save-label="'.$saveLabelAttr.'"'.$displayStyle.'>'."\n";
        $html .= '<h3>'.$lang->getLanguageHtml('scope_'.$scope).'</h3>'."\n";
        $html .= $this->renderInfoBlock($scope, $lang);
        $html .= $this->renderExistingJsonLdNotice($scope, $cat, $page, $lang, $scopeResolver, $settings);

        if($selectedType !== null) {
            $html .= $this->renderCollisionNotice($scope, $cat, $page, $selectedType, $lang, $scopeResolver, $settings);
        }

        $html .= '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
            .'<div class="mo-in-li-l"><label for="schemaOrgData_'.$idPrefix.'_type">'.$lang->getLanguageHtml('label_schema_type').'</label></div>'
            .'<div class="mo-in-li-r">'.$formRenderer->renderTypeSelector($scope, $availableTypes, $selectedType, $idPrefix, $lang).'</div>'
            .'</div>'."\n";

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
                $data = $this->sanitizePostData($postData, $schema, $schemaRepository, $openingHoursHelper, $validator);
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
            $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type, $lang, $scopeResolver, $settings);

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
            $html .= $this->renderExcludedCatsField($excludedCats, $debugOutput, $lang);
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
    * Ablauf: Schema des gewählten Types laden, Formularfelder und
    * Erweiterungsfeld validieren (validateFormData/json_decode/
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
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper
    ): array {
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

                $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type, $lang, $scopeResolver, $settings);
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

    /***************************************************************
    *
    * Verarbeitet die $_POST-Daten des Admin-Formulars. Für jede
    * übermittelte Geltungsebene (schemaOrgData[global|category|page],
    * siehe renderScopeSection) wird je nach Flag
    * "schemaOrgData_delete_{scope}" deleteConfig() oder saveConfig()
    * aufgerufen.
    *
    * Wird von getConfig() aufgerufen, bevor das Formular gerendert
    * wird, sofern $_POST nicht leer ist.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return ?array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function handlePostRequest(
        $settings,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper
    ): ?array {
        $scopes = $_POST['schemaOrgData'] ?? null;

        // Keine schemaOrgData-Formulardaten im POST - kein Speichervorgang,
        // kein Ergebnis zurückgeben (verhindert falsche Erfolgsmeldung).
        if(!is_array($scopes)) {
            return null;
        }

        $success = true;
        $errors = [];

        // Globaler Geltungsbereich (Sonderfall): schemaOrgData_cat und
        // schemaOrgData_page sind beide leer, wenn "Global" der aktive
        // Scope ist (siehe renderAdminPage()). saveConfig('global', ...)
        // wird ausschließlich hier ausgeführt, mit den tatsächlichen
        // POST-Daten - auch wenn $scopes['global'] aus dem POST nicht
        // als Array vorliegt (dann mit leerem Array). Der Scope-Loop
        // unten iteriert nur noch über 'category' und 'page', damit
        // Global nicht zusätzlich (mit ggf. leeren Daten) erneut
        // gespeichert wird.
        $catParam  = $scopeResolver->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_cat']  ?? ''));
        $pageParam = $scopeResolver->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_page'] ?? ''));
        $isGlobalScope = ($catParam === '' && $pageParam === '');

        if($isGlobalScope) {
            $globalData = (isset($scopes['global']) and is_array($scopes['global']))
                ? $scopes['global'] : [];

            $result = !empty($_POST['schemaOrgData_delete_global'])
                ? $scopeResolver->deleteConfig($settings, 'global')
                : $this->saveConfig('global', $globalData, $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper);

            $success = $success && $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        foreach(['category', 'page'] as $scope) {
            $hasData = isset($scopes[$scope]) and is_array($scopes[$scope]);

            if(!$hasData) {
                continue;
            }

            $result = !empty($_POST['schemaOrgData_delete_'.$scope])
                ? $scopeResolver->deleteConfig($settings, $scope)
                : $this->saveConfig($scope, $scopes[$scope], $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper);

            $success = $success && $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        return ['success' => $success, 'errors' => $errors];
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
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $pluginSelfDir PLUGIN_SELF_DIR
    * @param string $pluginLang aktuell aufgelöste Admin-Locale (ui:enumLabels)
    * @param string $pluginSelfUrl PLUGIN_SELF_URL
    *
    ***************************************************************/
    public function renderAdminPage(
        $settings,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        SchemaOrgData_FormRenderer $formRenderer,
        SchemaOrgData_DataSplitHelper $dataSplitHelper,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginLang,
        string $pluginSelfUrl,
        Language $weekdayLang,
        SchemaOrgData_IdReferenceService $idReferenceService,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_CollisionDetector $collisionDetector
    ): string {
        global $CatPage;
        global $CMS_CONF;

        $saveResult = ($_POST !== []) ? $this->handlePostRequest(
            $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper
        ) : null;

        // Bei fehlgeschlagenem Speichern wird die aktive Sektion in
        // renderScopeSection() mit den POST-Daten statt mit dem
        // gespeicherten Konfigurations-Stand befüllt (siehe dort).
        $saveFailed = ($saveResult !== null and $saveResult['success'] === false);

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
        $saveButtonLabel = $this->buildSaveButtonLabel($selectedCat, $selectedPage, $lang);

        $html = '<style>'.$this->getAdminCss().'</style>'."\n";
        $html .= '<form method="POST" action="'.htmlspecialchars($formAction, ENT_QUOTES, CHARSET).'">'."\n";
        $html .= '<input type="hidden" name="pluginadmin" value="'.PLUGINADMIN.'" />'."\n";
        $html .= '<input type="hidden" name="action" value="'.ACTION.'" />'."\n";
        $html .= '<div class="schemaOrgData-admin">'."\n";

        if($saveResult !== null) {
            $html .= $this->renderSaveResultNotice($saveResult, $lang);
        }

        // Zusätzlicher Speichern-Button am Formularanfang (oben rechts) -
        // derselbe Submit wie der Button am Formularende, damit lange
        // Formulare nicht erst bis zum Ende gescrollt werden müssen
        $html .= '<div class="schemaOrgData-save-bar schemaOrgData-save-bar--top">'."\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
               . $saveButtonLabel.'</button>'."\n";
        $html .= '</div>'."\n";

        // Scope-Selektor rendern
        $html .= $this->renderScopeSelector($selectedCat, $selectedPage, $lang);

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
            saveFailed: $saveFailed,
            lang: $lang, scopeResolver: $scopeResolver, settings: $settings, schemaRepository: $schemaRepository,
            pluginSelfDir: $pluginSelfDir, formRenderer: $formRenderer, dataSplitHelper: $dataSplitHelper,
            urlHelper: $urlHelper, pluginLang: $pluginLang, pluginSelfUrl: $pluginSelfUrl,
            weekdayLang: $weekdayLang, idReferenceService: $idReferenceService,
            openingHoursHelper: $openingHoursHelper, validator: $validator
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
                saveFailed: $saveFailed,
                lang: $lang, scopeResolver: $scopeResolver, settings: $settings, schemaRepository: $schemaRepository,
                pluginSelfDir: $pluginSelfDir, formRenderer: $formRenderer, dataSplitHelper: $dataSplitHelper,
                urlHelper: $urlHelper, pluginLang: $pluginLang, pluginSelfUrl: $pluginSelfUrl,
                weekdayLang: $weekdayLang, idReferenceService: $idReferenceService,
                openingHoursHelper: $openingHoursHelper, validator: $validator
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
                        saveFailed: $saveFailed,
                        lang: $lang, scopeResolver: $scopeResolver, settings: $settings, schemaRepository: $schemaRepository,
                        pluginSelfDir: $pluginSelfDir, formRenderer: $formRenderer, dataSplitHelper: $dataSplitHelper,
                        urlHelper: $urlHelper, pluginLang: $pluginLang, pluginSelfUrl: $pluginSelfUrl,
                        weekdayLang: $weekdayLang, idReferenceService: $idReferenceService,
                        openingHoursHelper: $openingHoursHelper, validator: $validator
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

        $html .= '<script>window.schemaOrgDataMessages = '
            .json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).';</script>'."\n";
        $html .= '<script src="'.$pluginSelfUrl.'js/ajv.min.js"></script>'."\n";
        $html .= '<script src="'.$pluginSelfUrl.'js/validator.js"></script>'."\n";
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            .' if(window.schemaOrgDataValidator) { window.schemaOrgDataValidator.initAdminForm(); }'
            .' });</script>'."\n";

        return $html;
    }
}
