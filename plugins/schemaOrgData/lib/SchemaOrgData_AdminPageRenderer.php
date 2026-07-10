<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminPageRenderer
*
* Reine Anzeige-Bausteine der Admin-Seite: Info-Block, Scope-Label/Selektor,
* Speichern-Button-Beschriftung, Speicher-Ergebnis-Hinweis, Hinweis
* auf vorhandenes/kollidierendes JSON-LD, Ausschlussliste, Admin-CSS
* sowie die Schema-Type-Auswahl.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
* $settings) werden je Aufruf als Parameter übergeben, nicht im
* Konstruktor eingefroren (siehe README.md, Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_AdminPageRenderer {

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
.schemaOrgData-admin { max-width: 900px; }
.schemaOrgData-admin .schemaOrgData-required-legend { color: #666; font-size: .85em; margin: 0 0 .75em; }
/* Freitext-Textarea-Zeilen (description u. a.) verzichten auf die 200px-Label-Spalte
   und stapeln Label/Feld stattdessen, damit die Textarea dieselbe Breite wie das
   Erweiterungsfeld-Textarea (schemaOrgData-wide-textarea, s. u.) erreichen kann. */
.schemaOrgData-admin .schemaOrgData-field-row:has(textarea) { grid-template-columns: 1fr !important; align-items: flex-start !important; }
.schemaOrgData-admin .schemaOrgData-info { background: #eef6ff; border: 1px solid #b6d4f5; padding: .75em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--info, .schemaOrgData-admin .schemaOrgData-notice--unsaved { background: #fff8e1; border: 1px solid #ffe082; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--success { background: #e8f5e9; border: 1px solid #a5d6a7; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error { background: #fdecea; border: 1px solid #f5c6c2; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error ul { margin: .25em 0 0; padding-left: 1.5em; }
.schemaOrgData-admin .schemaOrgData-placeholder-notice { background: #fdecea; border: 2px solid #c0392b; padding: .75em 1em; margin-bottom: 1.25em; border-radius: 4px; }
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
.schemaOrgData-admin .schemaOrgData-wide-textarea { width: 100%; box-sizing: border-box; }
/* Das Erweiterungsfeld-Textarea steckt (anders als Freitext-Textareas wie description)
   in einem schemaOrgData-fieldset mit eigenem Padding/Rahmen (s. o.) - "width: 100%"
   allein bezieht sich auf den dadurch bereits verengten Innenraum des Fieldsets und
   ergibt eine sichtbar schmalere Textarea als bei den ungerahmten Freitextfeldern.
   Negative Margins kompensieren exakt das Fieldset-Padding+Rahmen (1em + 1px je Seite),
   sodass beide Textarea-Typen auf dieselbe absolute Breite kommen. */
.schemaOrgData-admin .schemaOrgData-extension-field { font-family: monospace; resize: vertical; width: calc(100% + 2em + 2px); margin-left: calc(-1em - 1px); margin-right: calc(-1em - 1px); }
.schemaOrgData-admin .schemaOrgData-import-textarea { width: 100%; box-sizing: border-box; }
.schemaOrgData-admin select[id$="_addressCountry"] { max-width: 200px; }
.schemaOrgData-admin input[id$="_addressRegion"] { max-width: 300px; }
.schemaOrgData-admin .schemaOrgData-idrl-container { margin-bottom: .25em; }
.schemaOrgData-admin .schemaOrgData-idrl-radio-label { display: block; margin: .4em 0 .15em; cursor: pointer; }
.schemaOrgData-admin .schemaOrgData-idrl-section { padding-left: 1.5em; margin-bottom: .25em; }
.schemaOrgData-admin .schemaOrgData-jsonld-notice { background: #fff3e0; border: 1px solid #ffb74d; padding: .75em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-jsonld-notice__title { margin-top: 0; }
.schemaOrgData-admin .schemaOrgData-jsonld-notice__multiblock-hint { color: #b8860b; font-weight: bold; }
.schemaOrgData-admin .schemaOrgData-jsonld-preview { width: 100%; max-height: 300px; overflow: auto; box-sizing: border-box; font-family: monospace; font-size: .85em; background: #fafafa; border: 1px solid #ddd; border-radius: 4px; padding: .6em .75em; margin: .5em 0; }
.schemaOrgData-admin .schemaOrgData-jsonld-preview-dialog { max-width: 800px; width: 90vw; max-height: 85vh; overflow: auto; border-radius: 6px; border: 1px solid #ccc; box-shadow: 0 4px 24px rgba(0,0,0,.2); padding: 1.25em; }
.schemaOrgData-admin .schemaOrgData-jsonld-preview-dialog__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; border-bottom: 1px solid #eee; padding-bottom: .75em; }
.schemaOrgData-admin .schemaOrgData-jsonld-preview-dialog__close { background: none; border: none; font-size: 1.3em; cursor: pointer; color: #666; padding: .1em .4em; }
.schemaOrgData-admin .schemaOrgData-jsonld-preview-full { font-family: monospace; font-size: .85em; background: #fafafa; border: 1px solid #ddd; border-radius: 4px; padding: .75em; overflow: auto; white-space: pre-wrap; margin: 0; }
/* Der moziloCMS-Core setzt "details summary { display: flex }" mit einem generierten
   Pfeil-Pseudoelement (summary:before) voraus, dass der sichtbare Text in einem eigenen
   Kind-Element steckt (Core-Konvention: <summary><span class="flex">Text</span></summary>),
   dessen .flex-Klasse "align-items: center" mitbringt. Die <summary>-Elemente dieses
   Plugins enthalten stattdessen reinen Text ohne Wrapper-Element - ohne eigenes
   "align-items: center" fällt der Flex-Container auf den Default "stretch" zurück, wodurch
   der Pfeil sichtbar versetzt zum Text erscheint statt sauber davor. */
.schemaOrgData-admin details summary { align-items: center; gap: .3em; }
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

        // Allgemeiner Absatz (und im Global-Scope der Template-Hinweis) hinter
        // <details> - der scope-spezifische Absatz bleibt immer sichtbar.
        return '<div class="schemaOrgData-info">'
            .'<p>'.$lang->getLanguageHtml($key).'</p>'
            .'<details><summary>'.$lang->getLanguageHtml('label_info_more_details').'</summary>'
            .$templateNotice
            .'<p>'.$lang->getLanguageHtml('info_text_general').'</p>'
            .'</details>'
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
    * @param string $successMessageKey Sprachschlüssel für den Erfolgsfall -
    *        abweichend z. B. "notice_import_success" statt der Standard-
    *        Speicher-Meldung
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderSaveResultNotice(array $result, Language $lang, string $successMessageKey = 'notice_config_saved'): string {
        if($result['success']) {
            return '<div id="schemaOrgData_save_notice" class="schemaOrgData-notice schemaOrgData-notice--success">'
                .$lang->getLanguageHtml($successMessageKey)
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
    * vorhandenen Block aus - dieser Konsequenz-Hinweis wird unterhalb
    * der Radio-Gruppe ausgegeben. Im Global-Scope stammt ein erkannter
    * Block aus dem Layout-Template statt "von dieser Seite", daher
    * eigener Titel-Sprachschlüssel. Der Import-Bereich ist per
    * <details> einklappbar, initial offen nur wenn bereits ein
    * automatisch befüllbarer Block erkannt wurde. Deutet der Inhalt auf
    * mehrere aneinandergereihte Root-Objekte hin (Mehrblock-Heuristik),
    * wird der Autofill-Button durch einen erklärenden Hinweistext
    * ersetzt statt den ungültigen konkatenierten Text ins Import-Feld
    * zu übernehmen.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param Language $lang Admin-Sprachobjekt
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $importTextareaValue Rohwert für das Import-Textarea, z. B. nach
    *        fehlgeschlagenem Import - sonst leer
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
        $settings,
        string $importTextareaValue = ''
    ): string {
        $meta = $scopeResolver->loadScopeMeta($settings, $scope, $cat, $page);

        if(!$meta['existing_jsonld']) {
            return '';
        }

        $fieldName = 'schemaOrgData_jsonld_mode_'.$scope;
        $options = ['keep' => 'option_keep_existing_jsonld', 'override' => 'option_override_existing_jsonld'];

        // Im Global-Scope stammt ein erkannter Block aus dem Layout-Template,
        // nicht "von dieser Seite" - eigener Titel-Schlüssel analog zu
        // renderInfoBlock() (siehe README.md, "Verhalten bei vorhandenem JSON-LD").
        $titleKey = ($scope === 'global') ? 'notice_existing_jsonld_title_global' : 'notice_existing_jsonld_title';

        $html  = '<div class="schemaOrgData-jsonld-notice">'."\n";
        $html .= '<p class="schemaOrgData-jsonld-notice__title"><strong>'.$lang->getLanguageHtml($titleKey).'</strong></p>'."\n";
        $html .= '<p>'.$lang->getLanguageHtml('notice_existing_jsonld_text').'</p>'."\n";

        foreach($options as $value => $labelKey) {
            $checked = ($meta['jsonld_mode'] === $value) ? ' checked="checked"' : '';
            $html .= '<label><input type="radio" name="'.$fieldName.'" value="'.$value.'"'.$checked.' /> '
                  .$lang->getLanguageHtml($labelKey).'</label><br />'."\n";
        }

        $html .= '<p class="schemaOrgData-jsonld-notice__keep-hint">'.$lang->getLanguageHtml('notice_keep_consequence_hint').'</p>'."\n";

        // Import-Bereich per <details> einklappbar - initial offen nur wenn ein
        // Autofill-Button angeboten wird (Nutzer soll ihn sofort sehen),
        // ansonsten geschlossen, da manuelles Einfügen der Ausnahmefall ist.
        $detailsOpenAttr = !empty($meta['existing_jsonld_content']) ? ' open="open"' : '';
        $html .= '<details'.$detailsOpenAttr.'>'."\n";
        $html .= '<summary>'.$lang->getLanguageHtml('label_import_jsonld').'</summary>'."\n";
        $html .= '<p>'."\n";

        if(!empty($meta['existing_jsonld_content'])) {
            $rawContent = (string) $meta['existing_jsonld_content'];
            $escaped    = htmlspecialchars($rawContent, ENT_QUOTES, CHARSET);

            // Pretty-Print nur für die Anzeige - der Autofill-Button
            // überträgt weiterhin $rawContent (data-existing-content), damit
            // "Erkannten Block übernehmen" den unveränderten Rohtext liefert.
            $decoded = json_decode($rawContent, true);
            $prettyContent = (json_last_error() === JSON_ERROR_NONE)
                ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $rawContent;
            $escapedPretty = htmlspecialchars($prettyContent, ENT_QUOTES, CHARSET);

            // existing_jsonld_content entsteht durch implode("\n\n", ...)
            // mehrerer erkannter <script>-Blöcke (siehe
            // AdminController::renderAdminPage()/FrontendRenderer::renderFrontend()) -
            // bei mehr als einem Block ist das Ergebnis kein gültiges Einzel-JSON
            // mehr. Einfache Heuristik statt eines Parsers: ungültiges JSON UND
            // ein "}"-gefolgt-von-"{"-Übergang deutet auf mehrere aneinandergereihte
            // Root-Objekte hin. Bewusst kein automatischer Block-Splitter,
            // nur ein transparenter Hinweis.
            $looksLikeMultipleBlocks = json_last_error() !== JSON_ERROR_NONE
                && preg_match('/\}\s*\{/', $rawContent) === 1;

            $dialogId  = 'schemaOrgData-preview-dialog-'.$scope;
            $triggerId = 'schemaOrgData-preview-trigger-'.$scope;
            $closeId   = 'schemaOrgData-preview-close-'.$scope;

            $html .= '<pre class="schemaOrgData-jsonld-preview">'.$escapedPretty.'</pre>'."\n";
            $html .= '<button type="button" id="'.$triggerId.'" class="mo-btn schemaOrgData-preview-trigger-btn">'
                .$lang->getLanguageHtml('button_show_full_jsonld').'</button>'."\n";

            $html .= '<dialog id="'.$dialogId.'" class="schemaOrgData-jsonld-preview-dialog">'."\n";
            $html .= '<div class="schemaOrgData-jsonld-preview-dialog__header">'."\n";
            $html .= '<strong>'.$lang->getLanguageHtml('label_full_jsonld_preview').'</strong>'."\n";
            $html .= '<button type="button" id="'.$closeId.'" class="schemaOrgData-jsonld-preview-dialog__close" aria-label="&#x2715;">&#x2715;</button>'."\n";
            $html .= '</div>'."\n";
            $html .= '<pre class="schemaOrgData-jsonld-preview-full">'.$escapedPretty.'</pre>'."\n";
            $html .= '</dialog>'."\n";

            $html .= '<script>(function(){'
                .'var t=document.getElementById("'.$triggerId.'");'
                .'var d=document.getElementById("'.$dialogId.'");'
                .'var c=document.getElementById("'.$closeId.'");'
                .'if(t&&d&&d.showModal){t.addEventListener("click",function(){d.showModal();});}'
                .'if(c&&d){c.addEventListener("click",function(){d.close();});}'
                .'})();</script>'."\n";

            if($looksLikeMultipleBlocks) {
                // Der Autofill-Button würde hier den konkatenierten, ungültigen
                // Mehrblock-Text ins Import-Feld schreiben und den Import mit
                // einem JSON-Fehler abbrechen lassen - daher hier bewusst
                // unterdrückt statt angeboten. Der Hinweistext leitet zum
                // manuellen Kopieren des passenden Blocks aus der Vorschau an.
                $html .= '<p class="schemaOrgData-jsonld-notice__multiblock-hint">'
                    .$lang->getLanguageHtml('notice_multiple_jsonld_blocks').'</p>'."\n";
            } else {
                $html .= '<button type="button" class="mo-btn schemaOrgData-autofill-btn"'
                    .' data-target="schemaOrgData_import_'.$scope.'"'
                    .' data-existing-content="'.$escaped.'">'
                    .$lang->getLanguageHtml('button_use_detected_jsonld').'</button><br />'."\n";
            }
        }

        // Doppel-Label-Fix: <summary> ist bereits die sichtbare
        // Beschriftung, das Textarea erhält stattdessen ein aria-label.
        // Zusätzliche sichtbare <p>-Beschriftung direkt über der Textarea
        // (kein <label for="...">, sonst Doppel-Label-Regression).
        $html .= '<p class="schemaOrgData-import-target-label">'.$lang->getLanguageHtml('label_import_target').'</p>'."\n";
        $importAriaLabel = htmlspecialchars($lang->getLanguageValue('label_import_jsonld'), ENT_QUOTES, CHARSET);
        $importValueAttr = htmlspecialchars($importTextareaValue, ENT_QUOTES, CHARSET);
        $html .= '<textarea id="schemaOrgData_import_'.$scope.'" name="schemaOrgData_import_'.$scope.'"'
            .' class="schemaOrgData-import-textarea" rows="8" aria-label="'.$importAriaLabel.'">'.$importValueAttr.'</textarea><br />'."\n";
        $html .= '<button type="submit" name="schemaOrgData_import_action" value="'.$scope.'" class="mo-btn">'
            .$lang->getLanguageHtml('button_import').'</button>'."\n";
        $html .= '</p>'."\n";
        $html .= '<p class="schemaOrgData-jsonld-notice__hint">'.$lang->getLanguageHtml('description_import_jsonld').'</p>'."\n";
        $html .= '</details>'."\n";
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
    * Rendert einen scope-unabhängigen Hinweis zum Plugin-Platzhalter
    * im aktiven Layout-Template (siehe
    * SchemaOrgData_CollisionDetector::detectPluginPlaceholderInTemplateAdmin()).
    * Fehlt der Platzhalter, ruft der Kern getContent() des Plugins im
    * Frontend nirgends auf - das Plugin bleibt dann unabhängig von
    * seiner Konfiguration wirkungslos. Steht der Platzhalter zwar im
    * Template, aber hinter </head>, erscheint stattdessen ein
    * zurückhaltender Hinweis (keine Fehlermeldung), da die Ausgabe in
    * der Praxis meist trotzdem funktioniert (Browser reichen den
    * Platzhalterinhalt faktisch in den <body> durch).
    *
    * @param string $placeholderStatus Ergebnis von detectPluginPlaceholderInTemplateAdmin()
    *               (eine der SchemaOrgData_CollisionDetector::PLACEHOLDER_*-Konstanten)
    * @param string $pluginName Klassenname/Platzhaltername des Plugins
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet oder '' wenn der Platzhalter innerhalb <head> gefunden wurde
    *
    ***************************************************************/
    public function renderPlaceholderMissingNotice(string $placeholderStatus, string $pluginName, Language $lang): string {
        if($placeholderStatus === SchemaOrgData_CollisionDetector::PLACEHOLDER_OK) {
            return '';
        }

        if($placeholderStatus === SchemaOrgData_CollisionDetector::PLACEHOLDER_OUTSIDE_HEAD) {
            return '<div class="schemaOrgData-notice schemaOrgData-notice--info">'
                .'<p>'.$lang->getLanguageHtml('notice_placeholder_outside_head', $pluginName).'</p>'
                .'</div>'."\n";
        }

        return '<div class="schemaOrgData-notice schemaOrgData-placeholder-notice">'
            .'<p><strong>'.$lang->getLanguageHtml('notice_placeholder_missing_title').'</strong></p>'
            .'<p>'.$lang->getLanguageHtml('notice_placeholder_missing_text', $pluginName).'</p>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Rendert die Ausschlussliste für die globale Ausgabe (nur
    * Geltungsbereich "global"): eine Checkbox je vorhandener
    * Kategorie. Angehakte Kategorien erhalten keine globale
    * JSON-LD-Ausgabe (siehe README.md, Abschnitt "Ausschlussliste").
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
    * Rendert den Hinweis, dass in der LocalBusiness-Familie weitere
    * Geschäftsklassifikationen ausgeblendet wurden, weil bei Global
    * bereits ein Familien-Type konfiguriert ist.
    *
    * @param string $globalTypeLabelHtml bereits sprachaufgelöstes,
    *        HTML-escaptes Label des bei Global aktiven Types
    * @param Language $lang Admin-Sprachobjekt
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderFamilyFilterNotice(string $globalTypeLabelHtml, Language $lang): string {
        return '<p class="schemaOrgData-hint schemaOrgData-hint--family-filtered">'
            .$lang->getLanguageHtml('notice_family_options_filtered', $globalTypeLabelHtml)
            .'</p>'."\n";
    }

    /***************************************************************
    *
    * Rendert die Schema-Type-Auswahl (<select>) einer Geltungsebene.
    * Enthält zusätzlich die Option "– kein Schema –"
    * (schema_type_none).
    *
    * @param string $scope Geltungsbereich
    * @param array<string, array<string, mixed>> $availableTypes Type => Schema, für diese Ebene zulässig (ui:scopes)
    * @param string|null $selectedType aktuell konfigurierter Type oder null
    * @param string|null $idPrefix Präfix für die HTML-ID des <select> (Fallback: $scope)
    * @param Language $lang für Labels
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderTypeSelector(string $scope, array $availableTypes, ?string $selectedType, ?string $idPrefix, Language $lang): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_type';
        $fieldName = 'schemaOrgData['.$scope.'][type]';

        $html = '<div class="mo-select-div flex">';
        $html .= '<select id="'.$fieldId.'" name="'.$fieldName.'" class="mo-select flex-100 schemaOrgData-type-select">';

        $noneSelected = ($selectedType === null) ? ' selected="selected"' : '';
        $html .= '<option value=""'.$noneSelected.'>'.$lang->getLanguageHtml('schema_type_none').'</option>';

        foreach($availableTypes as $type => $schema) {
            $selected = ($selectedType === $type) ? ' selected="selected"' : '';
            $typeLabel = $lang->getLanguageHtml($schema['ui:typeLabel'] ?? $type);
            $html .= '<option value="'.htmlspecialchars($type, ENT_QUOTES, CHARSET).'"'.$selected.'>'.$typeLabel.'</option>';
        }

        $html .= '</select></div>';

        return $html;
    }
}
