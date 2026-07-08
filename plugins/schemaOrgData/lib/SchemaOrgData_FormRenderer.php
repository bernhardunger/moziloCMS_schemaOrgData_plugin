<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_FormRenderer
*
* Rendert das schema-getriebene Admin-Formular (Widgets je
* "ui:widget", zusammengesetzte Widgets wie postal_address/
* opening_hours/faq_list, Validierungs-Feedback und Badges) für
* das Plugin schemaOrgData (siehe README.md, Abschnitt
* "Schema-getriebenes Formular").
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_SchemaRepository,
* SchemaOrgData_UrlHelper, SchemaOrgData_OpeningHoursHelper,
* SchemaOrgData_Validator, SchemaOrgData_DataSplitHelper, Sprachcode,
* PLUGIN_SELF_URL) werden je Aufruf als Parameter übergeben, nicht im
* Konstruktor eingefroren.
*
***************************************************************/
class SchemaOrgData_FormRenderer {

    /***************************************************************
    *
    * Rendert das Feedback-Symbol (✅/⚠️/❌) zu einem
    * Validierungsergebnis (siehe validate*-Methoden).
    *
    * @param array{status: string|null, message: string|null} $result
    * @param string|null $feedbackId Element-ID für das <span> (z. B.
    *        "<fieldId>_feedback"). validator.js (showFieldFeedback())
    *        sucht/aktualisiert das Element anhand dieser ID, statt ein
    *        zweites (falsch positioniertes) Feedback-Element anzulegen.
    * @return string HTML-Snippet oder '' wenn $result['status'] === null
    *
    ***************************************************************/
    public function renderValidationFeedback(array $result, ?string $feedbackId): string {
        $icons = ['ok' => '&#9989;', 'warning' => '&#9888;&#65039;', 'error' => '&#10060;'];

        if($result['status'] === null or !isset($icons[$result['status']])) {
            return '';
        }

        $message = $result['message'] !== null
            ? ' '.htmlspecialchars($result['message'], ENT_QUOTES, CHARSET)
            : '';

        $idAttr = $feedbackId !== null
            ? ' id="'.htmlspecialchars($feedbackId, ENT_QUOTES, CHARSET).'"'
            : '';

        return '<span'.$idAttr.' class="schemaOrgData-feedback schemaOrgData-feedback--'.$result['status'].'">'
            .$icons[$result['status']].$message.'</span>';
    }

    /***************************************************************
    *
    * Rendert die Pflichtfeld-Kennzeichnung eines Formularfeldes
    * anhand von "ui:required". Optionale Felder erhalten keine
    * Kennzeichnung.
    *
    * @param Language $lang für das Label "Pflichtfeld"
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderRequiredBadge(bool $required, Language $lang): string {
        if(!$required) {
            return '';
        }

        return ' <span class="schemaOrgData-required" title="'
            .$lang->getLanguageHtml('label_required_field').'">*</span>';
    }

    /***************************************************************
    *
    * Rendert das "ü"-Badge für ein im aktuellen Geltungsbereich
    * leeres Feld, dessen Wert von einer übergeordneten Ebene geerbt
    * würde (siehe resolveInheritableFields()), analog
    * renderRequiredBadge(). Der Tooltip nennt die Ursprungsebene
    * (z. B. "Übernommen von: Global").
    *
    * @param string|null $originLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()) oder null, wenn das Feld nicht
    *        geerbt würde
    * @param Language $lang für Badge-Text und Tooltip
    * @return string HTML-Snippet oder '' wenn $originLabel null ist
    *
    ***************************************************************/
    public function renderInheritedBadge(?string $originLabel, Language $lang): string {
        if($originLabel === null) {
            return '';
        }

        return ' <span class="schemaOrgData-inherited" title="'
            .$lang->getLanguageHtml('tooltip_inherited_from', $originLabel).'">'
            .$lang->getLanguageHtml('badge_inherited').'</span>';
    }

    /***************************************************************
    *
    * Rendert ein einfaches Textfeld (<input type="text">).
    *
    * @param string $id   HTML-id des Feldes
    * @param string $name HTML-name des Feldes
    * @param array<string, mixed> $fieldSchema Feld-Schema (für ui:placeholder)
    * @param mixed $value aktueller Wert
    * @param array<string,string> $extraAttrs zusätzliche HTML-Attribute (z. B. data-validate)
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderTextWidget(string $id, string $name, array $fieldSchema, mixed $value, array $extraAttrs): string {
        $valueAttr = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, CHARSET);
        $placeholder = htmlspecialchars((string) ($fieldSchema['ui:placeholder'] ?? ''), ENT_QUOTES, CHARSET);

        $attrs = '';
        foreach($extraAttrs as $attrName => $attrValue) {
            $attrs .= ' '.$attrName.'="'.htmlspecialchars((string) $attrValue, ENT_QUOTES, CHARSET).'"';
        }

        return '<input type="text" id="'.$id.'" name="'.$name.'" class="mo-input-text" '
            .'value="'.$valueAttr.'" placeholder="'.$placeholder.'"'.$attrs.' />';
    }

    /***************************************************************
    *
    * Rendert ein mehrzeiliges Textfeld (<textarea>).
    *
    ***************************************************************/
    public function renderTextareaWidget(string $id, string $name, array $fieldSchema, mixed $value): string {
        $valueText = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, CHARSET);
        $placeholder = htmlspecialchars((string) ($fieldSchema['ui:placeholder'] ?? ''), ENT_QUOTES, CHARSET);

        return '<textarea id="'.$id.'" name="'.$name.'" class="mo-input-text" rows="4" placeholder="'.$placeholder.'">'
            .$valueText.'</textarea>';
    }

    /***************************************************************
    *
    * Rendert eine Dropdown-Auswahl (<select>). Optionen entweder aus
    * "ui:options" (flache Liste) oder aus "enum" +
    * "ui:enumLabels" (z. B. addressCountry, sprachabhängig).
    *
    * Felder ohne "default" und ohne "ui:required" erhalten zusätzlich
    * eine leere Option ("– bitte wählen –").
    *
    * @param Language $lang für die Platzhalter-Option
    * @param string $pluginLang aktuelle Admin-Sprache (für ui:enumLabels)
    *
    ***************************************************************/
    public function renderSelectWidget(string $id, string $name, array $fieldSchema, mixed $value, Language $lang, string $pluginLang): string {
        $options = [];

        if(isset($fieldSchema['ui:options']) and is_array($fieldSchema['ui:options'])) {
            foreach($fieldSchema['ui:options'] as $option) {
                $options[(string) $option] = (string) $option;
            }
        } elseif(isset($fieldSchema['enum']) and is_array($fieldSchema['enum'])) {
            $enumLabels = $fieldSchema['ui:enumLabels'][$pluginLang] ?? [];
            foreach($fieldSchema['enum'] as $enumValue) {
                $options[(string) $enumValue] = (string) ($enumLabels[$enumValue] ?? $enumValue);
            }
        }

        $current = ($value !== null and $value !== '') ? (string) $value : (string) ($fieldSchema['default'] ?? '');
        $required = (bool) ($fieldSchema['ui:required'] ?? false);

        $html = '<div class="mo-select-div flex"><select id="'.$id.'" name="'.$name.'" class="mo-select flex-100">';

        if($current === '' and !$required) {
            $html .= '<option value="">'.$lang->getLanguageHtml('label_select_placeholder').'</option>';
        }

        foreach($options as $optionValue => $optionLabel) {
            $selected = ($optionValue === $current) ? ' selected="selected"' : '';
            $html .= '<option value="'.htmlspecialchars($optionValue, ENT_QUOTES, CHARSET).'"'.$selected.'>'
                .htmlspecialchars($optionLabel, ENT_QUOTES, CHARSET).'</option>';
        }

        $html .= '</select></div>';

        return $html;
    }

    /***************************************************************
    *
    * Rendert das Widget id_reference_or_literal:
    * Radio-Auswahl zwischen „Verknüpfen" (Dropdown globaler @id-Knoten)
    * und „Manuell" (Literal-Felder gemäß ui:literalFields).
    *
    * @param string $scope  Geltungsbereich (global/category/page)
    * @param string $name   Property-Name im Schema
    * @param array<string, mixed> $fieldSchema Schema-Definition der Property
    * @param array<string, mixed> $value   Gespeicherter Wert ['_mode' => ..., ...]
    * @param string $idPrefix Präfix für HTML-IDs
    * @param Language $lang für die Widget-Labels
    * @param array<string,string> $availableFragments Fragment => Label-Map
    *        (siehe IdReferenceService::resolveAvailableGlobalFragments()),
    *        von der Fassade einmal je renderTypeFields()-Aufruf berechnet
    * @return string HTML des Widgets - die beiden Modus-Radios tragen einen
    *         je Instanz eindeutigen (nicht submittierten) Namen, der
    *         tatsächlich gespeicherte _mode-Wert läuft über ein per JS
    *         nachgeführtes verstecktes Feld mit dem regulären Feldnamen
    *
    ***************************************************************/
    public function renderIdReferenceOrLiteralWidget(string $scope, string $name, array $fieldSchema, array $value, string $idPrefix, Language $lang, array $availableFragments): string {
        $storedMode = (string) ($value['_mode'] ?? 'reference');
        $storedFragment = (string) ($value['_fragment'] ?? '');
        $refChecked = $storedMode !== 'literal' ? ' checked="checked"' : '';
        $litChecked = $storedMode === 'literal'  ? ' checked="checked"' : '';
        $refHidden  = $storedMode === 'literal'  ? ' style="display:none"' : '';
        $litHidden  = $storedMode !== 'literal'  ? ' style="display:none"' : '';

        $fieldNameBase = 'schemaOrgData['.$scope.'][data]['.$name.']';
        $modeField     = $fieldNameBase.'[_mode]';
        $fragmentField = $fieldNameBase.'[_fragment]';
        $containerId   = 'schemaOrgData_'.$idPrefix.'_'.$name.'_idrl';

        // Radiogruppen-Name je Widget-Instanz eindeutig (über $idPrefix), NICHT
        // $modeField: da für Kategorie-/Seiten-Scopes alle vorgerenderten
        // Sektionen denselben literalen $scope ("page"/"category") verwenden,
        // würde ein gemeinsamer Radio-Name dazu führen, dass der Browser beim
        // Parsen alle gleichnamigen Radios über sämtliche Sektionen hinweg zu
        // einer einzigen Gruppe zusammenfasst und automatisch bis auf das
        // zuletzt im DOM stehende Exemplar entcheckt - sichtbar als "nach dem
        // Speichern ist kein Radio mehr ausgewählt", sobald eine andere
        // Seite/Kategorie mit demselben Feld ebenfalls vorgerendert wird. Der
        // tatsächlich zu speichernde Wert läuft deshalb über das separate
        // versteckte Feld $modeField, dessen Value die Toggle-Funktion
        // (schemaOrgDataIdRlToggle) bei jeder Radio-Auswahl mitführt.
        $radioGroupName = 'schemaOrgData_idrl_'.$idPrefix.'_'.$name.'_mode';

        $html  = '<div class="schemaOrgData-idrl-container" id="'.htmlspecialchars($containerId, ENT_QUOTES, CHARSET).'">'."\n";

        $html .= '<input type="hidden" class="schemaOrgData-idrl-mode-field"'
            .' name="'.htmlspecialchars($modeField, ENT_QUOTES, CHARSET).'"'
            .' value="'.($storedMode === 'literal' ? 'literal' : 'reference').'" />'."\n";

        // Radio: Referenz-Modus
        $html .= '<label class="schemaOrgData-idrl-radio-label">'
            .'<input type="radio" class="schemaOrgData-idrl-radio"'
            .' name="'.htmlspecialchars($radioGroupName, ENT_QUOTES, CHARSET).'" value="reference"'
            .$refChecked.' onchange="schemaOrgDataIdRlToggle(this)" />'
            .' '.$lang->getLanguageHtml('label_id_reflit_reference')
            .'</label>'."\n";

        // Referenz-Dropdown
        $html .= '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-reference"'.$refHidden.'>'."\n";
        if($availableFragments !== []) {
            $html .= '<div class="mo-select-div flex"><select name="'.htmlspecialchars($fragmentField, ENT_QUOTES, CHARSET).'" class="mo-select flex-100">'."\n";
            foreach($availableFragments as $fragment => $fragLabel) {
                $sel = $fragment === $storedFragment ? ' selected="selected"' : '';
                $html .= '<option value="'.htmlspecialchars($fragment, ENT_QUOTES, CHARSET).'"'.$sel.'>'
                    .htmlspecialchars($fragLabel, ENT_QUOTES, CHARSET).'</option>'."\n";
            }
            $html .= '</select></div>'."\n";
        } else {
            $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('hint_id_reflit_no_targets').'</p>'."\n";
            $html .= '<input type="hidden" name="'.htmlspecialchars($fragmentField, ENT_QUOTES, CHARSET).'" value="" />'."\n";
        }
        $html .= '</div>'."\n";

        // Radio: Literal-Modus
        $html .= '<label class="schemaOrgData-idrl-radio-label">'
            .'<input type="radio" class="schemaOrgData-idrl-radio"'
            .' name="'.htmlspecialchars($radioGroupName, ENT_QUOTES, CHARSET).'" value="literal"'
            .$litChecked.' onchange="schemaOrgDataIdRlToggle(this)" />'
            .' '.$lang->getLanguageHtml('label_id_reflit_literal')
            .'</label>'."\n";

        // Literal-Felder
        $html .= '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-literal"'.$litHidden.'>'."\n";
        $literalFields      = $fieldSchema['ui:literalFields']      ?? [];
        $literalFieldLabels = $fieldSchema['ui:literalFieldLabels'] ?? [];
        foreach($literalFields as $lf) {
            $lfId    = 'schemaOrgData_'.$idPrefix.'_'.$name.'_lf_'.$lf;
            $lfName  = $fieldNameBase.'['.$lf.']';
            $lfValue = (string) ($value[(string) $lf] ?? '');
            $lfLabelKey = $literalFieldLabels[(string) $lf] ?? 'label_'.$lf;
            $lfLabel = $lang->getLanguageHtml($lfLabelKey);
            $html .= '<div class="c-content schemaOrgData-field-row">'."\n"
                .'<div class="mo-in-li-l"><label for="'.htmlspecialchars($lfId, ENT_QUOTES, CHARSET).'">'.$lfLabel.'</label></div>'."\n"
                .'<div class="mo-in-li-r"><input type="text" id="'.htmlspecialchars($lfId, ENT_QUOTES, CHARSET).'"'
                .' name="'.htmlspecialchars($lfName, ENT_QUOTES, CHARSET).'"'
                .' value="'.htmlspecialchars($lfValue, ENT_QUOTES, CHARSET).'"'
                .' class="mo-input-text flex-100" /></div>'."\n"
                .'</div>'."\n";
        }
        $html .= '</div>'."\n";
        $html .= '</div>'."\n";

        // Einmalig definierte Toggle-Funktion (idempotent via window-Guard).
        // Pflegt zusätzlich das versteckte Feld schemaOrgData-idrl-mode-field
        // nach, das - anders als die (je Instanz eindeutig benannten) Radios -
        // unter dem tatsächlichen Formularfeldnamen ($modeField) übermittelt wird.
        $html .= '<script>if(!window.schemaOrgDataIdRlToggle){'
            .'window.schemaOrgDataIdRlToggle=function(r){'
            .'var c=r.closest(".schemaOrgData-idrl-container");'
            .'c.querySelectorAll(".schemaOrgData-idrl-section").forEach(function(s){s.style.display="none";});'
            .'c.querySelector(".schemaOrgData-idrl-"+r.value).style.display="";'
            .'var h=c.querySelector(".schemaOrgData-idrl-mode-field");'
            .'if(h){h.value=r.value;}'
            .'};}</script>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert das PostalAddress-Widget (streetAddress, postalCode,
    * addressLocality, addressRegion, addressCountry). addressCountry
    * wird als Select mit ISO-3166-Codes gerendert (siehe
    * "definitions.PostalAddress" in den Schema-Dateien).
    *
    * @param string $scope Geltungsbereich ('global'|'category'|'page')
    * @param string $name  Property-Name (üblicherweise "address")
    * @param array<string, mixed> $fieldSchema bereits via resolveSchemaRef() aufgelöstes Schema
    * @param array<string, mixed> $value gespeicherte Adress-Properties
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param array|null $inheritedValue Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge,
    *        wird nicht übernommen
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    * @param Language $lang für Labels/Badges (wird an renderAddressSubField() durchgereicht)
    * @param SchemaOrgData_Validator $validator für validatePostalCode() (in renderAddressSubField())
    * @param string $pluginLang aktuelle Admin-Sprache (für ui:enumLabels in renderSelectWidget())
    * @param string|null $groupPrefix wenn gesetzt (siehe renderAddressSubField()),
    *        wird der Feldname um diese Ebene verschachtelt - genutzt vom
    *        place-Widget (siehe renderPlaceWidget()), das die Adresse unter
    *        "location.address" statt "address" ablegt
    *
    ***************************************************************/
    public function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null): string {
        $idPrefix = $idPrefix ?? $scope;
        $idSegment = $groupPrefix !== null ? $groupPrefix.'_'.$name : $name;
        $countryFieldId = 'schemaOrgData_'.$idPrefix.'_'.$idSegment.'_addressCountry';
        $properties = $fieldSchema['properties'] ?? [];
        $html = '';

        // Straße und Hausnummer: schema.org kennt kein eigenes
        // Hausnummer-Feld - "Straße und Hausnummer" ist ein
        // kombiniertes streetAddress-Feld und erhält eine eigene,
        // volle Zeile.
        if(isset($properties['streetAddress'])) {
            $field = $this->renderAddressSubField($scope, $name, 'streetAddress', $properties['streetAddress'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix);
            $html .= $this->renderAddressFullRow($field);
        }

        // PLZ + Ort kompakt in einer Zeile (PLZ schmal, Ort flexibel)
        $html .= $this->renderAddressFieldGroup($scope, $name, $properties, $value, $countryFieldId, $idPrefix, [
            'postalCode'      => true,
            'addressLocality' => false,
        ], $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix);

        // Land: eigene Zeile, Select ~200px breit (siehe getAdminCss)
        if(isset($properties['addressCountry'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressCountry', $properties['addressCountry'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix);
            $html .= $this->renderAddressFullRow($field);
        }

        // Region/Bundesland: eigene Zeile, ~300px breit (siehe getAdminCss)
        if(isset($properties['addressRegion'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressRegion', $properties['addressRegion'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix);
            $html .= $this->renderAddressFullRow($field);
        }

        return $html;
    }

    /***************************************************************
    *
    * Rendert ein einzelnes Sub-Feld des PostalAddress-Widgets
    * (siehe renderAddressSubField()) als eigenständige
    * schemaOrgData-field-row (Label links, Eingabefeld rechts).
    *
    ***************************************************************/
    public function renderAddressFullRow(array $field): string {
        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$field['fieldId'].'">'.$field['label'].'</label>'.$field['badge'].'</div>'
            .'<div class="mo-in-li-r">'.$field['widget'].$field['feedback'].'</div>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Rendert ein einzelnes Sub-Feld des PostalAddress-Widgets
    * (Eingabefeld, Pflichtfeld-/PLZ-Validierungsattribute und ggf.
    * Validierungs-Feedback). Wird sowohl für eigenständige Zeilen
    * (streetAddress) als auch für gruppierte Zeilen (PLZ+Ort,
    * Region+Land, siehe renderAddressFieldGroup()) verwendet.
    *
    * @param array|null $inheritedValue Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge,
    *        wird nicht übernommen
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    * @param Language $lang für Labels/Badges/Pflichtfeld-Meldungen
    * @param SchemaOrgData_Validator $validator für validatePostalCode()
    * @param string $pluginLang aktuelle Admin-Sprache (für ui:enumLabels in renderSelectWidget())
    * @param string|null $groupPrefix wenn gesetzt (z. B. "location" für das
    *        place-Widget, siehe renderPlaceWidget()), wird der Feldname um
    *        diese Ebene verschachtelt:
    *        schemaOrgData[scope][data][$groupPrefix][$name][$subName]
    *        statt schemaOrgData[scope][data][$name][$subName]
    * @return array{fieldId:string,label:string,badge:string,widget:string,feedback:string}
    *
    ***************************************************************/
    public function renderAddressSubField(string $scope, string $name, string $subName, array $subSchema, array $value, string $countryFieldId, string $idPrefix, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null): array {
        $idSegment = $groupPrefix !== null ? $groupPrefix.'_'.$name : $name;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$idSegment.'_'.$subName;
        $fieldNameBase = $groupPrefix !== null
            ? 'schemaOrgData['.$scope.'][data]['.$groupPrefix.']['.$name.']'
            : 'schemaOrgData['.$scope.'][data]['.$name.']';
        $fieldName = $fieldNameBase.'['.$subName.']';
        $subValue = $value[$subName] ?? ($subSchema['default'] ?? null);
        $required = (bool) ($subSchema['ui:required'] ?? false);
        $label = $lang->getLanguageHtml($subSchema['ui:label'] ?? $subName);
        $badge = $this->renderRequiredBadge($required, $lang);

        // Placeholder + "ü"-Badge für ein leeres Sub-Feld, dessen Wert von
        // einer übergeordneten Ebene geerbt würde (siehe Task 1,
        // resolveInheritableFields()) - das Feld selbst bleibt leer.
        $isEmpty = !isset($value[$subName]) or $value[$subName] === '';
        $inheritedSubValue = $inheritedValue[$subName] ?? null;
        if($isEmpty and is_scalar($inheritedSubValue) and (string) $inheritedSubValue !== '') {
            if(($subSchema['ui:widget'] ?? 'text') !== 'select') {
                $subSchema['ui:placeholder'] = (string) $inheritedSubValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel, $lang);
        }

        if(($subSchema['ui:widget'] ?? 'text') === 'select') {
            $widgetHtml = $this->renderSelectWidget($fieldId, $fieldName, $subSchema, $subValue, $lang, $pluginLang);
        } else {
            $extraAttrs = [];
            if($subName === 'postalCode') {
                $extraAttrs = ['data-validate' => 'postal_code', 'data-country-field' => $countryFieldId];
            } elseif($required) {
                $extraAttrs = [
                    'data-validate' => 'required',
                    'data-required-message' => $lang->getLanguageValue('error_required_field', $lang->getLanguageValue($subSchema['ui:label'] ?? $subName)),
                ];
            }
            $widgetHtml = $this->renderTextWidget($fieldId, $fieldName, $subSchema, $subValue, $extraAttrs);
        }

        $feedback = '';
        if($subName === 'postalCode' and $subValue !== null and $subValue !== '') {
            $countryCode = (string) ($value['addressCountry'] ?? 'DE');
            $feedback = $this->renderValidationFeedback($validator->validatePostalCode((string) $subValue, $countryCode, $lang), $fieldId.'_feedback');
        }

        return ['fieldId' => $fieldId, 'label' => $label, 'badge' => $badge, 'widget' => $widgetHtml, 'feedback' => $feedback];
    }

    /***************************************************************
    *
    * Rendert eine gruppierte Zeile des PostalAddress-Widgets: mehrere
    * Sub-Felder nebeneinander, jeweils mit eigenem (kleinem) Label
    * über dem Eingabefeld (siehe schemaOrgData-address-row /
    * schemaOrgData-address-field in getAdminCss()).
    *
    * @param array<string,bool> $subNames Sub-Feldname => schmal
    *        darstellen (z. B. PLZ, max-width 80px)
    * @param array|null $inheritedValue Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    * @param Language $lang wird an renderAddressSubField() durchgereicht
    * @param SchemaOrgData_Validator $validator wird an renderAddressSubField() durchgereicht
    * @param string $pluginLang wird an renderAddressSubField() durchgereicht
    * @param string|null $groupPrefix wird an renderAddressSubField() durchgereicht
    *        (siehe dort)
    *
    ***************************************************************/
    public function renderAddressFieldGroup(string $scope, string $name, array $properties, array $value, string $countryFieldId, string $idPrefix, array $subNames, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null): string {
        $html = '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"></div>'
            .'<div class="mo-in-li-r"><div class="schemaOrgData-address-row">'."\n";

        foreach($subNames as $subName => $narrow) {
            if(!isset($properties[$subName])) {
                continue;
            }
            $field = $this->renderAddressSubField($scope, $name, $subName, $properties[$subName], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix);
            $narrowClass = $narrow ? ' schemaOrgData-address-field--narrow' : '';
            $html .= '<div class="schemaOrgData-address-field'.$narrowClass.'">'
                .'<label for="'.$field['fieldId'].'">'.$field['label'].$field['badge'].'</label>'
                .$field['widget'].$field['feedback']
                .'</div>'."\n";
        }

        $html .= '</div></div></div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert das Place-Widget (Event.location): ein einfaches
    * Textfeld für "name" sowie eine verschachtelte PostalAddress
    * unter "location.address" (siehe renderPostalAddressWidget(),
    * $groupPrefix = $name). Weg-1-Wiederverwendung, kein neues
    * generisches Nested-Object-Widget-System.
    *
    * @param string $scope Geltungsbereich
    * @param string $name  Property-Name (üblicherweise "location")
    * @param array<string, mixed> $fieldSchema Feld-Schema (properties.name, properties.address mit "$ref")
    * @param array<string, mixed> $value gespeicherter Place-Wert (name, address)
    * @param array<string, mixed> $rootSchema vollständiges Schema (für resolveSchemaRef() von properties.address)
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für Labels
    * @param SchemaOrgData_SchemaRepository $schemaRepository für resolveSchemaRef()
    * @param SchemaOrgData_Validator $validator für renderPostalAddressWidget()
    * @param string $pluginLang für renderSelectWidget() (in renderPostalAddressWidget())
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderPlaceWidget(string $scope, string $name, array $fieldSchema, array $value, array $rootSchema, ?string $idPrefix, Language $lang, SchemaOrgData_SchemaRepository $schemaRepository, SchemaOrgData_Validator $validator, string $pluginLang): string {
        $idPrefix = $idPrefix ?? $scope;
        $properties = $fieldSchema['properties'] ?? [];
        $html = '';

        if(isset($properties['name'])) {
            $nameSchema = $properties['name'];
            $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_name';
            $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.'][name]';
            $nameValue = $value['name'] ?? null;
            $label = $lang->getLanguageHtml($nameSchema['ui:label'] ?? 'label_name');
            $widgetHtml = $this->renderTextWidget($fieldId, $fieldName, $nameSchema, $nameValue, []);
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$label.'</label></div>'
                .'<div class="mo-in-li-r">'.$widgetHtml.'</div>'
                .'</div>'."\n";
        }

        if(isset($properties['address'])) {
            $addressSchema = $schemaRepository->resolveSchemaRef($properties['address'], $rootSchema);
            $addressValue = is_array($value['address'] ?? null) ? $value['address'] : [];
            $html .= $this->renderPostalAddressWidget($scope, 'address', $addressSchema, $addressValue, $idPrefix, null, null, $lang, $validator, $pluginLang, $name);
        }

        return $html;
    }

    /***************************************************************
    *
    * Rendert das Öffnungszeiten-Widget: je Wochentag (Mo-So) ein
    * Von/Bis-Zeitfeld. Leere Felder gelten als "geschlossen". Die
    * Werte werden beim Speichern (siehe parseOpeningHours/
    * buildOpeningHoursArray) zu einem openingHours-Array in
    * schema.org-Notation zusammengeführt.
    *
    * @param string $scope Geltungsbereich
    * @param string $name  Property-Name (üblicherweise "openingHours")
    * @param array<string, mixed> $fieldSchema Feld-Schema (ui:days, ui:dayLabelKeys)
    * @param array<string, mixed> $value gespeichertes openingHours-Array
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für Labels/Hinweise
    * @param Language $weekdayLang für die Wochentag-Labels
    * @param SchemaOrgData_OpeningHoursHelper $openingHoursHelper für isPerDayOpeningHoursValue()/parseOpeningHours()
    * @param SchemaOrgData_Validator $validator für validateOpeningHoursTime()
    *
    ***************************************************************/
    public function renderOpeningHoursWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix, Language $lang, Language $weekdayLang, SchemaOrgData_OpeningHoursHelper $openingHoursHelper, SchemaOrgData_Validator $validator): string {
        $idPrefix = $idPrefix ?? $scope;
        $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $dayLabelKeys = $fieldSchema['ui:dayLabelKeys'] ?? [];

        // $value liegt entweder als openingHours-Array in schema.org-Notation
        // vor (gespeicherte Konfiguration / sanitizePostData) oder als rohe
        // Pro-Tag-Werte aus dem POST (Re-Display nach fehlgeschlagenem Save,
        // siehe renderScopeSection) - im zweiten Fall die Werte unverändert
        // übernehmen, um auch ungültige Zeitformate anzuzeigen.
        $perDay = $openingHoursHelper->isPerDayOpeningHoursValue($value)
            ? $value
            : $openingHoursHelper->parseOpeningHours($value, $days);

        $secondRangeLabel = $lang->getLanguageHtml('label_opening_hours_second_range');

        $html = '<table class="schemaOrgData-opening-hours">'."\n";
        $html .= '<thead><tr><th></th><th>'.$lang->getLanguageHtml('label_opening_hours_from').' – '
            .$lang->getLanguageHtml('label_opening_hours_to').'</th></tr></thead>'."\n";
        $html .= '<tbody>'."\n";

        foreach($days as $day) {
            $dayLabel = isset($dayLabelKeys[$day]) ? $weekdayLang->getLanguageHtml($dayLabelKeys[$day]) : htmlspecialchars($day, ENT_QUOTES, CHARSET);
            $fromId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.'_from';
            $toId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.'_to';
            $from2Id = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.'_from2';
            $to2Id = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.'_to2';
            $fromName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][from]';
            $toName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][to]';
            $from2Name = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][from2]';
            $to2Name = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][to2]';
            $from  = trim((string) ($perDay[$day]['from']  ?? ''));
            $to    = trim((string) ($perDay[$day]['to']    ?? ''));
            $from2 = trim((string) ($perDay[$day]['from2'] ?? ''));
            $to2   = trim((string) ($perDay[$day]['to2']   ?? ''));

            $fromInput = $this->renderTextWidget($fromId, $fromName, ['ui:placeholder' => '09:00'], $from, [
                'data-validate' => 'opening_hours', 'data-pair' => $toId, 'maxlength' => '5',
            ]);
            $toInput = $this->renderTextWidget($toId, $toName, ['ui:placeholder' => '18:00'], $to, [
                'data-validate' => 'opening_hours', 'data-pair' => $fromId, 'maxlength' => '5',
            ]);
            $from2Input = $this->renderTextWidget($from2Id, $from2Name, ['ui:placeholder' => '13:00'], $from2, [
                'data-validate' => 'opening_hours', 'data-pair' => $to2Id, 'maxlength' => '5',
            ]);
            $to2Input = $this->renderTextWidget($to2Id, $to2Name, ['ui:placeholder' => '18:00'], $to2, [
                'data-validate' => 'opening_hours', 'data-pair' => $from2Id, 'maxlength' => '5',
            ]);

            $feedback = $this->renderValidationFeedback($validator->validateOpeningHoursTime($from, $to, $lang), $fromId.'_feedback');

            $feedback2Result = $validator->validateOpeningHoursTime($from2, $to2, $lang);
            // Eigener Format-/Reihenfolgefehler der Pause hat Vorrang: nur wenn
            // validateOpeningHoursTime() nicht bereits 'error' liefert, wird die
            // Überlappungs-Prüfung angewandt (analog zu runOpeningHoursValidation()
            // in js/validator.js, Commit 94ef495). Der ursprüngliche Vergleich auf
            // status === null war unerreichbar, da diese Methode bei nicht-leeren
            // $from2/$to2-Werten nie null zurückliefert.
            if($feedback2Result['status'] !== 'error' && $from2 !== '' && $to2 !== '' && $to !== '' && $from2 < $to) {
                $feedback2Result = ['status' => 'error', 'message' => $lang->getLanguageValue('error_opening_hours_overlap')];
            }
            $feedback2 = $this->renderValidationFeedback($feedback2Result, $from2Id.'_feedback');

            $html .= '<tr><td>'.$dayLabel.'</td>'
                .'<td>'
                .'<div class="schemaOrgData-opening-hours-group">'
                .'<span class="schemaOrgData-opening-hours-range-label" aria-hidden="true">'.$secondRangeLabel.':</span>'
                .$fromInput
                .'<span class="schemaOrgData-opening-hours-sep">–</span>'
                .$toInput.'</div>'.$feedback
                .'<div class="schemaOrgData-opening-hours-group schemaOrgData-opening-hours-second">'
                .'<span class="schemaOrgData-opening-hours-range-label">'.$secondRangeLabel.':</span>'
                .$from2Input
                .'<span class="schemaOrgData-opening-hours-sep">–</span>'
                .$to2Input.'</div>'.$feedback2
                .'</td></tr>'."\n";
        }

        $html .= '</tbody></table>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('hint_opening_hours').' '
            .$lang->getLanguageHtml('label_opening_hours_closed').'</p>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert das FAQ-Listen-Widget (FAQPage.mainEntity): je Eintrag
    * ein Frage-Feld (text) und ein Antwort-Feld (textarea), plus
    * eine zusätzliche leere Zeile zum Anlegen eines neuen Eintrags.
    *
    * @param string $scope Geltungsbereich
    * @param string $name  Property-Name (üblicherweise "mainEntity")
    * @param array<string, mixed> $fieldSchema Feld-Schema (items.properties)
    * @param array<string, mixed> $value gespeichertes mainEntity-Array
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für Labels/Badges
    *
    ***************************************************************/
    public function renderFaqListWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix, Language $lang): string {
        $idPrefix = $idPrefix ?? $scope;
        $itemSchema = $fieldSchema['items'] ?? [];
        $questionSchema = $itemSchema['properties']['name'] ?? [];
        $answerSchema = $itemSchema['properties']['acceptedAnswer']['properties']['text'] ?? [];

        // bestehende Einträge plus eine leere Zeile zum Anlegen eines neuen Eintrags
        $entries = array_values($value);
        $entries[] = ['name' => '', 'acceptedAnswer' => ['text' => '']];

        $html = '';
        foreach($entries as $index => $entry) {
            $questionId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$index.'_name';
            $answerId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$index.'_answer';
            $questionName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$index.'][name]';
            $answerName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$index.'][acceptedAnswer][text]';
            $question = $entry['name'] ?? '';
            $answer = $entry['acceptedAnswer']['text'] ?? '';

            $questionLabel = $lang->getLanguageHtml($questionSchema['ui:label'] ?? 'label_faq_question');
            $answerLabel = $lang->getLanguageHtml($answerSchema['ui:label'] ?? 'label_faq_answer');
            $badge = $this->renderRequiredBadge((bool) ($questionSchema['ui:required'] ?? false), $lang);

            $html .= '<div class="schemaOrgData-faq-entry">'."\n";
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$questionId.'">'.$questionLabel.'</label>'.$badge.'</div>'
                .'<div class="mo-in-li-r">'.$this->renderTextWidget($questionId, $questionName, $questionSchema, $question, []).'</div>'
                .'</div>'."\n";
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$answerId.'">'.$answerLabel.'</label></div>'
                .'<div class="mo-in-li-r">'.$this->renderTextareaWidget($answerId, $answerName, $answerSchema, $answer).'</div>'
                .'</div>'."\n";
            $html .= '</div>'."\n";
        }

        return $html;
    }

    /***************************************************************
    *
    * Rendert das Erweiterungsfeld (JSON-Textarea) für zusätzliche,
    * im Schema nicht abgebildete Properties. Die Live-Validierung
    * (Syntax, Property-Whitelist, Format) erfolgt clientseitig via
    * AJV (siehe js/validator.js, data-schema-url).
    *
    * @param string $scope Geltungsbereich
    * @param string $type  Schema-Type (für data-schema-url)
    * @param string $extensionJson bereits formatiertes JSON (oder '')
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für Labels/Hinweise
    * @param string $pluginSelfUrl Basis-URL des Plugin-Ordners (ersetzt $this->PLUGIN_SELF_URL)
    *
    ***************************************************************/
    public function renderExtensionFieldWidget(string $scope, string $type, string $extensionJson, ?string $idPrefix, Language $lang, string $pluginSelfUrl): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_extension';
        $fieldName = 'schemaOrgData['.$scope.'][extension]['.$type.']';
        $schemaUrl = $pluginSelfUrl.'schemas/'.$type.'.json';

        $html = '<fieldset class="schemaOrgData-fieldset">'."\n";
        $html .= '<legend>'.$lang->getLanguageHtml('label_extension_field').'</legend>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_extension_field').'</p>'."\n";
        $html .= '<textarea id="'.$fieldId.'" name="'.$fieldName.'" class="mo-input-text schemaOrgData-extension-field" '
            .'rows="6" data-schema-url="'.htmlspecialchars($schemaUrl, ENT_QUOTES, CHARSET).'">'
            .htmlspecialchars($extensionJson, ENT_QUOTES, CHARSET).'</textarea>'."\n";
        $html .= '<div id="'.$fieldId.'_feedback" class="schemaOrgData-extension-feedback"></div>'."\n";
        $html .= '</fieldset>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Ermittelt zusätzliche HTML-Attribute für die clientseitige
    * Live-Validierung eines Feldes (data-validate, ggf.
    * data-country-field für telephone - nur wenn das Schema
    * überhaupt ein address-Property besitzt, siehe README.md).
    * Pflichtfelder ("ui:required") erhalten zusätzlich
    * data-required-message, damit der Blur-Handler (validator.js,
    * runFieldValidation()) leere Pflichtfelder sofort meldet.
    *
    * @param array<string, mixed> $rootSchema aktives Schema des Types (für
    *        die address-Property-Prüfung des telephone-Zweigs)
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für die Pflichtfeld-Meldung (nur bei ui:required)
    * @return array<string,string>
    *
    ***************************************************************/
    public function buildValidationAttrs(string $scope, string $name, array $fieldSchema, array $rootSchema, ?string $idPrefix, Language $lang): array {
        $idPrefix = $idPrefix ?? $scope;
        $format = $fieldSchema['format'] ?? null;
        $required = (bool) ($fieldSchema['ui:required'] ?? false);

        if($format === 'uri') {
            $attrs = ['data-validate' => 'url'];
        } elseif($format === 'email') {
            $attrs = ['data-validate' => 'email'];
        } elseif($format === 'date-time') {
            $attrs = ['data-validate' => 'date-time'];
            // Nur endDate erhält die Gegenstück-Referenz auf startDate - der
            // Bereichsfehler wird nur einmal gemeldet (analog zur serverseitigen
            // Logik in SchemaOrgData_Validator::validateFormData(), die den
            // Fehler ebenfalls nur einmal in $errors[] einträgt).
            if($name === 'endDate') {
                $attrs['data-range-start-field'] = 'schemaOrgData_'.$idPrefix.'_startDate';
            }
        } elseif($name === 'telephone') {
            $attrs = ['data-validate' => 'telephone'];
            // Nur setzen, wenn das Schema tatsächlich ein address-Property
            // hat (Person/Organization haben keins - das Attribut zeigte
            // dort zuvor auf ein nie gerendertes Element).
            if(isset($rootSchema['properties']['address'])) {
                $attrs['data-country-field'] = 'schemaOrgData_'.$idPrefix.'_address_addressCountry';
            }
        } elseif($required) {
            $attrs = ['data-validate' => 'required'];
        } else {
            $attrs = [];
        }

        if($required) {
            $label = $lang->getLanguageValue($fieldSchema['ui:label'] ?? $name);
            $attrs['data-required-message'] = $lang->getLanguageValue('error_required_field', $label);
        }

        return $attrs;
    }

    /***************************************************************
    *
    * Rendert das serverseitige Validierungs-Feedback für ein
    * einfaches Feld (Top-Level, außerhalb von postal_address/
    * opening_hours, die ihr Feedback selbst rendern).
    *
    * @param array<string, mixed> $allData alle Formular-Properties des Schema-Types
    *                        (für telephone -> address.addressCountry)
    * @param string $feedbackId Element-ID für das Feedback-<span>
    *        (siehe renderValidationFeedback())
    * @param SchemaOrgData_Validator $validator für validateUrl()/validateEmail()/validateTelephone()/validateEventDateInput()
    * @param Language $lang für die Fehlermeldungen
    *
    ***************************************************************/
    public function renderFieldFeedback(string $name, array $fieldSchema, string $value, array $allData, string $feedbackId, SchemaOrgData_Validator $validator, Language $lang): string {
        $format = $fieldSchema['format'] ?? null;

        if($format === 'uri') {
            return $this->renderValidationFeedback($validator->validateUrl($value, $lang), $feedbackId);
        }

        if($format === 'email') {
            return $this->renderValidationFeedback($validator->validateEmail($value, $lang), $feedbackId);
        }

        if($format === 'date-time') {
            return $this->renderValidationFeedback($validator->validateEventDateInput($value, $lang), $feedbackId);
        }

        if($name === 'telephone') {
            $countryCode = (string) ($allData['address']['addressCountry'] ?? 'DE');
            return $this->renderValidationFeedback($validator->validateTelephone($value, $countryCode, $lang), $feedbackId);
        }

        return '';
    }

    /***************************************************************
    *
    * Rendert ein einzelnes Formularfeld anhand seines "ui:widget".
    * Zusammengesetzte Widgets (postal_address, opening_hours,
    * faq_list) erhalten ein eigenes <fieldset>; einfache Widgets
    * (text, textarea, select) eine Zeile im moziloCMS-Stil
    * (mo-in-li-l/mo-in-li-r).
    *
    * @param string $scope Geltungsbereich
    * @param string $name  Property-Name (Schema-Schlüssel)
    * @param array<string, mixed> $fieldSchema Schema des Feldes (ggf. mit "$ref")
    * @param mixed $value  aktueller Wert
    * @param array<string, mixed> $rootSchema vollständiges Schema (für resolveSchemaRef)
    * @param array<string, mixed> $allData alle Formular-Properties dieses Schema-Types
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param mixed $inheritedValue Wert, der von einer übergeordneten Ebene
    *        geerbt würde (siehe resolveInheritableFields()) - nur für
    *        Placeholder + "ü"-Badge, wird nicht übernommen
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    * @param Language $lang für Labels/Badges/Hinweise
    * @param SchemaOrgData_SchemaRepository $schemaRepository für resolveSchemaRef()
    * @param SchemaOrgData_UrlHelper $urlHelper für resolveFrontendBaseUrl() (nur id_reference-Widget)
    * @param string $pluginLang aktuelle Admin-Sprache (für renderSelectWidget())
    * @param SchemaOrgData_OpeningHoursHelper $openingHoursHelper für renderOpeningHoursWidget()
    * @param SchemaOrgData_Validator $validator für renderPostalAddressWidget()/renderOpeningHoursWidget()/renderFieldFeedback()
    * @param Language $weekdayLang für renderOpeningHoursWidget()
    * @param array<string,string> $availableFragments für renderIdReferenceOrLiteralWidget()
    *
    ***************************************************************/
    public function renderField(string $scope, string $name, array $fieldSchema, mixed $value, array $rootSchema, array $allData, ?string $idPrefix, mixed $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_SchemaRepository $schemaRepository, SchemaOrgData_UrlHelper $urlHelper, string $pluginLang, SchemaOrgData_OpeningHoursHelper $openingHoursHelper, SchemaOrgData_Validator $validator, Language $weekdayLang, array $availableFragments): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldSchema = $schemaRepository->resolveSchemaRef($fieldSchema, $rootSchema);
        $widget = $fieldSchema['ui:widget'] ?? 'text';
        $label = $lang->getLanguageHtml($fieldSchema['ui:label'] ?? $name);
        $required = (bool) ($fieldSchema['ui:required'] ?? false);
        $badge = $this->renderRequiredBadge($required, $lang);
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name;

        // date-time-Felder (Event.startDate/endDate): gespeicherter
        // ISO-8601-Wert wird für die Anzeige nach TT.MM.YYYY[ HH:MM]
        // zurückformatiert (Redisplay-Nachtrag zu Fahrplan-Schritt 4,
        // symmetrisch zu normalizeEventDateInput()) - wirkt sich nicht
        // auf das Speicherformat aus, nur auf Eingabefeld und Feedback.
        if(($fieldSchema['format'] ?? null) === 'date-time' and is_string($value) and $value !== '') {
            $value = $validator->formatEventDateForDisplay($value);
        }

        $isEmpty = ($value === null or $value === '' or $value === []);

        // id_reference: rein deklaratives Widget ohne Eingabefeld.
        // Der Wert wird zur Build-Zeit in buildJsonLdScript() emittiert;
        // im Formular genügt eine schreibgeschützte Info-Anzeige mit der
        // aufgelösten Ziel-URI.
        if($widget === 'id_reference') {
            $target = trim((string) ($fieldSchema['ui:idTarget'] ?? ''));
            // Admin-Anzeige: ohne "admin/"-Segment, damit die angezeigte
            // URI mit der tatsächlichen Frontend-Emission übereinstimmt
            // (siehe SchemaOrgData_UrlHelper::resolveFrontendBaseUrl()).
            $baseUrl = $urlHelper->resolveFrontendBaseUrl();
            $uri = $baseUrl !== '' ? $baseUrl.'#'.$target : '#'.$target;
            $infoText = $lang->getLanguageHtml('hint_id_reference_auto_link');
            return '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l">'.$label.$badge.'</div>'
                .'<div class="mo-in-li-r"><span class="schemaOrgData-id-reference-info">'
                .$infoText.' <code>'.htmlspecialchars($uri).'</code>'
                .'</span></div>'
                .'</div>'."\n";
        }

        if($widget === 'id_reference_or_literal') {
            $inner = $this->renderIdReferenceOrLiteralWidget(
                $scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang, $availableFragments
            );
            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        if($widget === 'place') {
            $inner = $this->renderPlaceWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $rootSchema, $idPrefix, $lang, $schemaRepository, $validator, $pluginLang);
            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        if(in_array($widget, ['postal_address', 'opening_hours', 'faq_list'], true)) {
            $inner = match($widget) {
                'postal_address' => $this->renderPostalAddressWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, is_array($inheritedValue) ? $inheritedValue : null, $inheritedLabel, $lang, $validator, $pluginLang),
                'opening_hours'  => $this->renderOpeningHoursWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang, $weekdayLang, $openingHoursHelper, $validator),
                'faq_list'       => $this->renderFaqListWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang),
                default          => '',
            };

            if($widget === 'postal_address') {
                $inner = '<p class="schemaOrgData-hint">'
                    .$lang->getLanguageHtml('hint_address_conditional_required')
                    .'</p>'."\n".$inner;
            }

            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        // Placeholder + "ü"-Badge für ein leeres Feld, dessen Wert von einer
        // übergeordneten Ebene geerbt würde (siehe Task 1,
        // resolveInheritableFields()) - das Feld selbst bleibt leer.
        if($isEmpty and is_scalar($inheritedValue) and (string) $inheritedValue !== '') {
            if($widget !== 'select') {
                $placeholderValue = (string) $inheritedValue;
                if(($fieldSchema['format'] ?? null) === 'date-time') {
                    $placeholderValue = $validator->formatEventDateForDisplay($placeholderValue);
                }
                $fieldSchema['ui:placeholder'] = $placeholderValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel, $lang);
        }

        $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']';

        $widgetHtml = match($widget) {
            'select'   => $this->renderSelectWidget($fieldId, $fieldName, $fieldSchema, $value, $lang, $pluginLang),
            'textarea' => $this->renderTextareaWidget($fieldId, $fieldName, $fieldSchema, $value),
            default    => $this->renderTextWidget($fieldId, $fieldName, $fieldSchema, $value, $this->buildValidationAttrs($scope, $name, $fieldSchema, $rootSchema, $idPrefix, $lang)),
        };

        $feedback = ($value !== null and $value !== '' and is_scalar($value))
            ? $this->renderFieldFeedback($name, $fieldSchema, (string) $value, $allData, $fieldId.'_feedback', $validator, $lang)
            : '';

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$label.'</label>'.$badge.'</div>'
            .'<div class="mo-in-li-r">'.$widgetHtml.$feedback.'</div>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Rendert alle Formularfelder eines Schema-Types (inkl.
    * Erweiterungsfeld) für eine Geltungsebene.
    *
    * @param string $scope Geltungsbereich
    * @param string $type  Schema-Type, z. B. "LocalBusiness"
    * @param array<string, mixed> $schema vollständiges Schema (schemas/{Type}.json)
    * @param array<string, mixed> $data   gespeicherte Properties dieses Types (Formular + Erweiterung gemischt)
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param string|null $extensionJsonOverride wenn gesetzt, wird dieser Wert
    *        statt der aus $data abgeleiteten Erweiterungs-Properties als
    *        Inhalt des Erweiterungsfelds verwendet (siehe renderScopeSection(),
    *        POST-Daten nach fehlgeschlagenem Speichern)
    * @param array{data: array<string,mixed>, originLabel: array<string,string>} $inheritable
    *        Werte (und deren Herkunfts-Label), die von einer übergeordneten
    *        Ebene für dieses Type geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge
    * @param SchemaOrgData_DataSplitHelper $dataSplitHelper für splitDataForRendering()
    * @param Language $lang wird an renderField()/renderExtensionFieldWidget() durchgereicht
    * @param SchemaOrgData_SchemaRepository $schemaRepository wird an renderField() durchgereicht
    * @param SchemaOrgData_UrlHelper $urlHelper wird an renderField() durchgereicht
    * @param string $pluginLang wird an renderField() durchgereicht
    * @param string $pluginSelfUrl wird an renderExtensionFieldWidget() durchgereicht
    * @param SchemaOrgData_OpeningHoursHelper $openingHoursHelper wird an renderField() durchgereicht
    * @param SchemaOrgData_Validator $validator wird an renderField() durchgereicht
    * @param Language $weekdayLang wird an renderField() durchgereicht
    * @param array<string,string> $availableFragments wird an renderField() durchgereicht
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderTypeFields(string $scope, string $type, array $schema, array $data, ?string $idPrefix, ?string $extensionJsonOverride, array $inheritable, SchemaOrgData_DataSplitHelper $dataSplitHelper, Language $lang, SchemaOrgData_SchemaRepository $schemaRepository, SchemaOrgData_UrlHelper $urlHelper, string $pluginLang, string $pluginSelfUrl, SchemaOrgData_OpeningHoursHelper $openingHoursHelper, SchemaOrgData_Validator $validator, Language $weekdayLang, array $availableFragments): string {
        $idPrefix = $idPrefix ?? $scope;
        $split = $dataSplitHelper->splitDataForRendering($data, $schema);
        $formData = $split['form'];

        if($extensionJsonOverride !== null) {
            $extensionJson = $extensionJsonOverride;
        } else {
            $extensionJson = $split['extension'] !== []
                ? json_encode($split['extension'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : '';
        }

        $html = '';
        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $html .= $this->renderField(
                $scope, $name, $fieldSchema, $formData[$name] ?? null, $schema, $formData, $idPrefix,
                $inheritable['data'][$name] ?? null, $inheritable['originLabel'][$name] ?? null,
                $lang, $schemaRepository, $urlHelper, $pluginLang, $openingHoursHelper, $validator, $weekdayLang, $availableFragments,
            );
        }

        $html .= $this->renderExtensionFieldWidget($scope, $type, $extensionJson, $idPrefix, $lang, $pluginSelfUrl);

        return $html;
    }

}
