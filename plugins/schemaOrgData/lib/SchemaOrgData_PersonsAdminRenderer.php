<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_PersonsAdminRenderer
*
* Rendert den vierten Admin-Bereich (Personen-Registry, siehe
* SchemaOrgData_PersonsRegistryService): Personenliste, "Neue Person"-
* Formular sowie ein Bearbeiten-Formular je bestehender Person. Alle
* Ansichten werden vorgerendert (analog zum Scope-/Kategorie-/Seiten-
* Muster in SchemaOrgData_AdminController::renderScopeSection()); ein
* eigenständiges, inline eingebettetes Toggle-Script
* (window.schemaOrgDataPersonsShow, siehe renderToggleScript())
* blendet die jeweils aktive Ansicht ein und deaktiviert die Felder
* der übrigen - dasselbe Muster wie das bereits bestehende
* window.schemaOrgDataIdRlToggle (SchemaOrgData_FormRenderer::renderIdReferenceOrLiteralWidget()).
* Vollständig entkoppelt vom Scope-Formular (initScopeSelector() in
* js/validator.js) - keine gemeinsame CSS-Klasse, kein Eingriff in
* dessen bestehende Logik (siehe README.md, Personen-Registry).
*
* Formularfelder nutzen die bestehenden SchemaOrgData_FormRenderer-
* Widget-Bausteine (renderTextWidget/renderTextareaWidget/
* renderSelectWidget/renderRequiredBadge/renderValidationFeedback) -
* kein neues Widget-System.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_FormRenderer,
* SchemaOrgData_PersonsRegistryService, SchemaOrgData_Validator,
* SchemaOrgData_UrlHelper, $settings) werden je Aufruf als Parameter
* übergeben, nicht im Konstruktor eingefroren (siehe README.md,
* Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_PersonsAdminRenderer {

    private const LIST_VIEW_ID = 'schemaOrgData_persons_view_list';
    private const NEW_VIEW_ID  = 'schemaOrgData_persons_view_new';

    /***************************************************************
    *
    * Rendert den vollständigen Personen-Container: Liste, "Neue
    * Person"-Formular, ein Bearbeiten-Formular je Person, Toggle-Script.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param bool $visibleInitially ob der Container beim Laden der Seite
    *        sichtbar sein soll (z. B. weil gerade eine Personen-Aktion
    *        verarbeitet wurde, siehe SchemaOrgData_AdminController::renderAdminPage())
    * @param string $activeViewId ID der initial aktiven Ansicht
    *        (self::LIST_VIEW_ID, self::NEW_VIEW_ID oder "..._edit_<slug>")
    * @param array<string, mixed> $redisplayData POST-Rohdaten für die aktive
    *        Ansicht nach fehlgeschlagener Aktion (sonst leer)
    * @param string[] $redisplayErrors Fehlermeldungen für die aktive Ansicht
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderPersonsSection(
        $settings,
        Language $lang,
        SchemaOrgData_PersonsRegistryService $registryService,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_UrlHelper $urlHelper,
        SchemaOrgData_FormRenderer $formRenderer,
        bool $visibleInitially,
        string $activeViewId,
        array $redisplayData,
        array $redisplayErrors
    ): string {
        $registry = $registryService->loadRegistry($settings);
        uasort($registry, static function(array $a, array $b): int {
            $sortA = (int) ($a['sortOrder'] ?? 100);
            $sortB = (int) ($b['sortOrder'] ?? 100);
            return $sortA <=> $sortB ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $displayAttr = $visibleInitially ? '' : ' style="display:none"';
        $html = '<div id="schemaOrgData_persons_container" class="schemaOrgData-persons-container"'.$displayAttr.'>'."\n";
        $html .= '<h3>'.$lang->getLanguageHtml('label_persons_registry_title').'</h3>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_persons_registry').'</p>'."\n";

        $html .= $this->renderListView($registry, $lang, $activeViewId === self::LIST_VIEW_ID);

        $newData = ($activeViewId === self::NEW_VIEW_ID) ? $redisplayData : [];
        $newErrors = ($activeViewId === self::NEW_VIEW_ID) ? $redisplayErrors : [];
        $html .= $this->renderPersonForm(null, $newData, $newErrors, $lang, $registryService, $validator, $urlHelper, $formRenderer, $activeViewId === self::NEW_VIEW_ID);

        foreach($registry as $slug => $person) {
            $viewId = $this->buildEditViewId($slug);
            $isActive = ($activeViewId === $viewId);
            // Redisplay-Daten (fehlgeschlagene POST-Eingabe) haben nur Vorrang,
            // wenn tatsächlich welche vorliegen - eine lediglich aktivierte,
            // aber nicht durch einen fehlgeschlagenen Speichervorgang befüllte
            // Bearbeiten-Ansicht zeigt weiterhin die gespeicherten Werte
            // (analog SchemaOrgData_AdminController::renderScopeSection(), $postScope).
            $data = ($isActive and $redisplayData !== []) ? $redisplayData : $person;
            $errors = $isActive ? $redisplayErrors : [];
            $html .= $this->renderPersonForm($slug, $data, $errors, $lang, $registryService, $validator, $urlHelper, $formRenderer, $isActive);
        }

        $html .= $this->renderToggleScript();
        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Liefert die View-ID des Bearbeiten-Formulars einer Person -
    * gemeinsam von renderPersonsSection() und
    * SchemaOrgData_AdminController::renderAdminPage() verwendet, um
    * nach einem fehlgeschlagenen "update"/erfolgreichen Redisplay die
    * richtige Ansicht zu aktivieren.
    *
    ***************************************************************/
    public function buildEditViewId(string $slug): string {
        return 'schemaOrgData_persons_view_edit_'.$slug;
    }

    /** View-ID der Personenliste (Default-Ansicht). */
    public function listViewId(): string {
        return self::LIST_VIEW_ID;
    }

    /** View-ID des "Neue Person"-Formulars. */
    public function newViewId(): string {
        return self::NEW_VIEW_ID;
    }

    /***************************************************************
    *
    * Rendert die Personenliste (Name, Titel, jobTitle, Status,
    * sortOrder) mit "Bearbeiten"/"Löschen" je Zeile sowie den
    * "+ Neue Person"-Button.
    *
    * @param array<string, array<string, mixed>> $registry bereits nach
    *        sortOrder/name sortiert (siehe renderPersonsSection())
    *
    ***************************************************************/
    private function renderListView(array $registry, Language $lang, bool $active): string {
        $displayAttr = $active ? '' : ' style="display:none"';
        $html = '<div id="'.self::LIST_VIEW_ID.'" class="schemaOrgData-persons-view" data-persons-view="1"'.$displayAttr.'>'."\n";

        if($registry === []) {
            $html .= '<p>'.$lang->getLanguageHtml('label_persons_registry_empty').'</p>'."\n";
        } else {
            $html .= '<table class="schemaOrgData-persons-table"><thead><tr>'
                .'<th>'.$lang->getLanguageHtml('label_person_name').'</th>'
                .'<th>'.$lang->getLanguageHtml('label_job_title').'</th>'
                .'<th>'.$lang->getLanguageHtml('label_person_status').'</th>'
                .'<th>'.$lang->getLanguageHtml('label_sort_order').'</th>'
                .'<th></th>'
                .'</tr></thead><tbody>'."\n";

            foreach($registry as $slug => $person) {
                $namePrefix = trim((string) ($person['honorificPrefix'] ?? ''));
                $displayName = trim($namePrefix !== '' ? $namePrefix.' '.(string) ($person['name'] ?? '') : (string) ($person['name'] ?? ''));
                $isInactive = ((string) ($person['status'] ?? SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE)) === SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE;
                $statusLabel = $lang->getLanguageHtml($isInactive ? 'label_person_status_inactive' : 'label_person_status_active');
                $slugAttr = htmlspecialchars((string) $slug, ENT_QUOTES, CHARSET);
                $editViewId = htmlspecialchars($this->buildEditViewId((string) $slug), ENT_QUOTES, CHARSET);
                $confirmText = htmlspecialchars($lang->getLanguageValue('confirm_delete_person'), ENT_QUOTES, CHARSET);

                $html .= '<tr>'
                    .'<td>'.htmlspecialchars($displayName, ENT_QUOTES, CHARSET).'</td>'
                    .'<td>'.htmlspecialchars((string) ($person['jobTitle'] ?? ''), ENT_QUOTES, CHARSET).'</td>'
                    .'<td>'.$statusLabel.'</td>'
                    .'<td>'.htmlspecialchars((string) ($person['sortOrder'] ?? 100), ENT_QUOTES, CHARSET).'</td>'
                    .'<td>'
                    .'<button type="button" class="mo-btn" onclick="schemaOrgDataPersonsShow(\''.$editViewId.'\')">'
                    .$lang->getLanguageHtml('button_edit_person').'</button> '
                    .'<button type="submit" name="schemaOrgData_persons_action" value="delete:'.$slugAttr.'" class="mo-btn"'
                    .' onclick="return confirm(\''.$confirmText.'\')">'
                    .$lang->getLanguageHtml('button_delete_person').'</button>'
                    .'</td>'
                    .'</tr>'."\n";
            }

            $html .= '</tbody></table>'."\n";
        }

        $html .= '<button type="button" class="mo-btn mo-btn--primary" onclick="schemaOrgDataPersonsShow(\''.self::NEW_VIEW_ID.'\')">'
            .$lang->getLanguageHtml('button_add_person').'</button>'."\n";
        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert das Anlegen- ($slug === null) bzw. Bearbeiten-Formular
    * ($slug !== null, Slug schreibgeschützt) einer Person.
    *
    * @param string|null $slug null = Anlegen-Formular
    * @param array<string, mixed> $data aktuelle Feldwerte (gespeicherte
    *        Person bzw. POST-Rohdaten nach fehlgeschlagener Aktion)
    * @param string[] $errors Fehlermeldungen (nur bei aktivem Redisplay befüllt)
    *
    ***************************************************************/
    private function renderPersonForm(
        ?string $slug,
        array $data,
        array $errors,
        Language $lang,
        SchemaOrgData_PersonsRegistryService $registryService,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_UrlHelper $urlHelper,
        SchemaOrgData_FormRenderer $formRenderer,
        bool $active
    ): string {
        $isEdit = ($slug !== null);
        $viewId = $isEdit ? $this->buildEditViewId((string) $slug) : self::NEW_VIEW_ID;
        $displayAttr = $active ? '' : ' style="display:none"';

        $html = '<div id="'.htmlspecialchars($viewId, ENT_QUOTES, CHARSET).'" class="schemaOrgData-persons-view" data-persons-view="1"'.$displayAttr.'>'."\n";
        $html .= '<h4>'.$lang->getLanguageHtml($isEdit ? 'label_edit_person_title' : 'label_add_person_title').'</h4>'."\n";

        if($errors !== []) {
            $html .= '<div class="schemaOrgData-notice schemaOrgData-notice--error"><ul>'."\n";
            foreach($errors as $error) {
                $html .= '<li>'.htmlspecialchars((string) $error, ENT_QUOTES, CHARSET).'</li>'."\n";
            }
            $html .= '</ul></div>'."\n";
        }

        if($isEdit) {
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l">'.$lang->getLanguageHtml('label_person_slug').'</div>'
                .'<div class="mo-in-li-r"><code>'.htmlspecialchars((string) $slug, ENT_QUOTES, CHARSET).'</code></div>'
                .'</div>'."\n";
        } else {
            $slugFieldId = 'schemaOrgData_persons_new_slug';
            $slugValue = (string) ($data['slug'] ?? '');
            $slugPlaceholder = $registryService->generateSlugSuggestion((string) ($data['name'] ?? ''));
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$slugFieldId.'">'.$lang->getLanguageHtml('label_person_slug').'</label></div>'
                .'<div class="mo-in-li-r">'.$formRenderer->renderTextWidget($slugFieldId, 'schemaOrgData_persons_data[slug]', ['ui:placeholder' => $slugPlaceholder], $slugValue, []).'</div>'
                .'</div>'."\n";
            $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('hint_person_slug').'</p>'."\n";
        }

        $idPrefix = $isEdit ? 'edit_'.$slug : 'new';

        $html .= $this->renderTextRow($idPrefix, 'name', 'label_person_name', (string) ($data['name'] ?? ''), true, $lang, $formRenderer);
        $html .= $this->renderTextRow($idPrefix, 'honorificPrefix', 'label_honorific_prefix', (string) ($data['honorificPrefix'] ?? ''), false, $lang, $formRenderer);
        $html .= $this->renderTextRow($idPrefix, 'jobTitle', 'label_job_title', (string) ($data['jobTitle'] ?? ''), false, $lang, $formRenderer);
        $html .= $this->renderTextareaRow($idPrefix, 'description', 'label_person_description', (string) ($data['description'] ?? ''), $lang, $formRenderer);
        $html .= $this->renderTextRow($idPrefix, 'url', 'label_person_url', (string) ($data['url'] ?? ''), false, $lang, $formRenderer);

        $sameAsValue = is_array($data['sameAs'] ?? null) ? implode("\n", $data['sameAs']) : (string) ($data['sameAs'] ?? '');
        $html .= $this->renderTextareaRow($idPrefix, 'sameAs', 'label_person_same_as', $sameAsValue, $lang, $formRenderer);

        $imageValue = (string) ($data['image'] ?? '');
        $html .= $this->renderImageRow($idPrefix, $imageValue, $lang, $formRenderer, $registryService, $validator, $urlHelper);

        $html .= $this->renderStatusRow($idPrefix, (string) ($data['status'] ?? SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE), $lang, $formRenderer);
        $html .= $this->renderTextRow($idPrefix, 'sortOrder', 'label_sort_order', (string) ($data['sortOrder'] ?? '100'), false, $lang, $formRenderer);

        $submitValue = $isEdit ? 'update:'.htmlspecialchars((string) $slug, ENT_QUOTES, CHARSET) : 'create';
        $html .= '<div class="schemaOrgData-persons-form-actions">'."\n";
        $html .= '<button type="submit" name="schemaOrgData_persons_action" value="'.$submitValue.'" class="mo-btn mo-btn--primary">'
            .$lang->getLanguageHtml($isEdit ? 'button_save_person' : 'button_create_person').'</button> '."\n";
        $html .= '<button type="button" class="mo-btn" onclick="schemaOrgDataPersonsShow(\''.self::LIST_VIEW_ID.'\')">'
            .$lang->getLanguageHtml('button_cancel_person').'</button>'."\n";
        $html .= '</div>'."\n";

        $html .= '</div>'."\n";

        if(!$active) {
            $html = (string) preg_replace('/<(input|select|textarea)(\s)/i', '<$1 disabled="disabled"$2', $html);
        }

        return $html;
    }

    /** Rendert eine einzelne Textfeld-Zeile (Label links, Eingabefeld rechts). */
    private function renderTextRow(string $idPrefix, string $field, string $labelKey, string $value, bool $required, Language $lang, SchemaOrgData_FormRenderer $formRenderer): string {
        $fieldId = 'schemaOrgData_persons_'.$idPrefix.'_'.$field;
        $fieldName = 'schemaOrgData_persons_data['.$field.']';
        $badge = $formRenderer->renderRequiredBadge($required, $lang);

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$lang->getLanguageHtml($labelKey).'</label>'.$badge.'</div>'
            .'<div class="mo-in-li-r">'.$formRenderer->renderTextWidget($fieldId, $fieldName, [], $value, []).'</div>'
            .'</div>'."\n";
    }

    /** Rendert eine Textarea-Zeile (Label links, mehrzeiliges Eingabefeld rechts). */
    private function renderTextareaRow(string $idPrefix, string $field, string $labelKey, string $value, Language $lang, SchemaOrgData_FormRenderer $formRenderer): string {
        $fieldId = 'schemaOrgData_persons_'.$idPrefix.'_'.$field;
        $fieldName = 'schemaOrgData_persons_data['.$field.']';

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$lang->getLanguageHtml($labelKey).'</label></div>'
            .'<div class="mo-in-li-r">'.$formRenderer->renderTextareaWidget($fieldId, $fieldName, [], $value).'</div>'
            .'</div>'."\n";
    }

    /** Rendert die image-Zeile inkl. nicht blockierender "Datei nicht gefunden"-Warnung. */
    private function renderImageRow(
        string $idPrefix,
        string $value,
        Language $lang,
        SchemaOrgData_FormRenderer $formRenderer,
        SchemaOrgData_PersonsRegistryService $registryService,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_UrlHelper $urlHelper
    ): string {
        $fieldId = 'schemaOrgData_persons_'.$idPrefix.'_image';
        $fieldName = 'schemaOrgData_persons_data[image]';
        $feedback = ($value !== '')
            ? $formRenderer->renderValidationFeedback($registryService->checkImageAvailability($value, $lang, $validator, $urlHelper), $fieldId.'_feedback')
            : '';

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$lang->getLanguageHtml('label_person_image').'</label></div>'
            .'<div class="mo-in-li-r">'.$formRenderer->renderTextWidget($fieldId, $fieldName, ['ui:placeholder' => 'persons/max-mustermann.jpg'], $value, []).$feedback.'</div>'
            .'</div>'."\n";
    }

    /** Rendert die status-Select-Zeile (active/inactive, lokalisiert). */
    private function renderStatusRow(string $idPrefix, string $value, Language $lang, SchemaOrgData_FormRenderer $formRenderer): string {
        $fieldId = 'schemaOrgData_persons_'.$idPrefix.'_status';
        $fieldName = 'schemaOrgData_persons_data[status]';
        $fieldSchema = [
            'enum' => [SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE, SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE],
            'ui:required' => true,
            'ui:enumLabels' => [
                'current' => [
                    SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE   => $lang->getLanguageValue('label_person_status_active'),
                    SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE => $lang->getLanguageValue('label_person_status_inactive'),
                ],
            ],
        ];

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$lang->getLanguageHtml('label_person_status').'</label></div>'
            .'<div class="mo-in-li-r">'.$formRenderer->renderSelectWidget($fieldId, $fieldName, $fieldSchema, $value, $lang, 'current').'</div>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Einmalig definierte, idempotente Toggle-Funktion (analog
    * window.schemaOrgDataIdRlToggle, siehe
    * SchemaOrgData_FormRenderer::renderIdReferenceOrLiteralWidget()):
    * blendet die Ansicht mit passender ID ein (alle anderen
    * [data-persons-view]-Ansichten aus) und (de-)aktiviert deren
    * Formularfelder, damit beim Speichern ausschließlich die aktive
    * Ansicht übertragen wird (mehrere vorgerenderte Formulare teilen
    * sich dieselben Feldnamen "schemaOrgData_persons_data[...]").
    *
    ***************************************************************/
    private function renderToggleScript(): string {
        return '<script>if(!window.schemaOrgDataPersonsShow){'
            .'window.schemaOrgDataPersonsShow=function(viewId){'
            .'document.querySelectorAll("[data-persons-view]").forEach(function(el){'
            .'var active=(el.id===viewId);'
            .'el.style.display=active?"":"none";'
            .'el.querySelectorAll("input, select, textarea").forEach(function(f){f.disabled=!active;});'
            .'});'
            .'};}</script>'."\n";
    }
}
