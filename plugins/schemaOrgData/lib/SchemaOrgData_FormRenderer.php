<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_FormRenderer
*
* Rendert das schema-getriebene Admin-Formular (Widgets je
* "ui:widget", zusammengesetzte Widgets wie postal_address/
* opening_hours/faq_list, Validierungs-Feedback und Badges) für
* das Plugin schemaOrgData.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_SchemaRepository,
* SchemaOrgData_UrlHelper, SchemaOrgData_OpeningHoursHelper,
* SchemaOrgData_Validator, SchemaOrgData_DataSplitHelper, Sprachcode,
* PLUGIN_SELF_URL) werden je Aufruf als Parameter übergeben, nicht im
* Konstruktor eingefroren.
*
***************************************************************/
class SchemaOrgData_FormRenderer {

    /**
     * Bindung an die Geltungsebenen aus SchemaOrgData_ScopeResolver - das
     * Literal steht dort an einer Stelle, hier nur der Verweis darauf.
     */
    private const SCOPE_GLOBAL = SchemaOrgData_ScopeResolver::SCOPE_GLOBAL;

    /**
     * Bindung an SchemaOrgData_Validator - die Literale stehen dort an
     * einer Stelle, hier nur der Verweis darauf.
     */
    private const SHARED_STATUS_OK      = SchemaOrgData_Validator::SHARED_STATUS_OK;
    private const SHARED_STATUS_WARNING = SchemaOrgData_Validator::SHARED_STATUS_WARNING;
    private const SHARED_STATUS_ERROR   = SchemaOrgData_Validator::SHARED_STATUS_ERROR;

    /**
     * Klassenstamm der Feld-Rueckmeldung. Der Statuswert wird direkt
     * angehaengt; js/validator.js baut denselben Namen und die
     * CSS-Regeln in SchemaOrgData_AdminPageRenderer::getAdminCss()
     * setzen darauf auf. Ein Waechter haelt beide Sprachen zusammen und
     * prueft, dass jeder Statuswert eine CSS-Regel hat.
     */
    public const SHARED_CLASS_FEEDBACK = 'schemaOrgData-feedback schemaOrgData-feedback--';

    /**
     * Suffix der Element-ID einer Feld-Rueckmeldung: Die ID des
     * Rueckmeldungs-Elements ist die Feld-ID plus dieses Suffix.
     * js/validator.js bildet dieselbe Ableitung an vierzehn Stellen;
     * tests/PhpJsParityTest.php haelt beide Seiten gegeneinander und
     * laesst dort kein zweites angehaengtes Suffix zu.
     *
     * Oeffentlich, weil SchemaOrgData_PersonsAdminRenderer sie bindet.
     */
    public const SHARED_ID_SUFFIX_FEEDBACK = '_feedback';

    /**
     * Suffixe der Zeitfenster im Oeffnungszeiten-Widget. js/validator.js
     * leitet aus ihnen eine Rolle ab, teils per regulaerem Ausdruck ueber
     * die fertige Element-ID - deshalb queren sie die Sprachgrenze,
     * obwohl sie nur Namensbestandteile sind.
     *
     * '_to2' steht hier bewusst nicht: JavaScript baut diese ID nirgends,
     * es springt vom zweiten Von-Feld auf das erste Bis-Feld zurueck. Ohne
     * Gegenstelle traegt ein Wert kein SHARED_-Praefix.
     */
    private const SHARED_ID_SLOT_FROM  = '_from';
    private const SHARED_ID_SLOT_TO    = '_to';
    private const SHARED_ID_SLOT_FROM2 = '_from2';

    /**
     * Name des HTML-Attributs, über das die Live-Validierung angesteuert
     * wird, und sein Wertevorrat. Der Renderer schreibt diese Werte in die
     * Ausgabe, js/validator.js liest sie zurück; sie stammen nicht aus den
     * Schema-Dateien. Ein unbekannter Wert läuft dort in den default-Zweig
     * und bleibt folgenlos - ein Tippfehler schaltet die Live-Prüfung eines
     * Feldes also still ab, statt aufzufallen.
     *
     * Öffentlich, weil SchemaOrgData_PersonsAdminRenderer dieselben Attribute
     * emittiert und der Wertevorrat die PHP-Seite eines Sprachgrenzen-
     * Kontrakts ist.
     *
     * VALIDATE_GEO und VALIDATE_OPENING_HOURS sind wortgleich mit
     * SchemaOrgData_SchemaRepository::WIDGET_GEO bzw. WIDGET_OPENING_HOURS,
     * gehören aber einem anderen Vokabular an: dort ein Widget-Name aus dem
     * Schema, hier ein Attributwert für das JavaScript. Dasselbe gilt für
     * VALIDATE_REQUIRED, VALIDATE_EMAIL und VALIDATE_DATE_TIME gegenüber dem
     * JSON-Schema-Schlüsselwort "required" und den format-Werten. Alle sind
     * deshalb literal definiert und keine von einer anderen abgeleitet.
     */
    public const ATTR_VALIDATE = 'data-validate';

    public const VALIDATE_URL              = 'url';
    public const VALIDATE_EMAIL            = 'email';
    public const VALIDATE_DATE_TIME        = 'date-time';
    public const VALIDATE_TELEPHONE        = 'telephone';
    public const VALIDATE_POSTAL_CODE      = 'postal_code';
    public const VALIDATE_REQUIRED         = 'required';
    public const VALIDATE_ADDRESS_REQUIRED = 'address_required';
    public const VALIDATE_OPENING_HOURS    = 'opening_hours';
    public const VALIDATE_GEO              = 'geo';
    public const VALIDATE_PERSON_SLUG      = 'person_slug';
    public const VALIDATE_SORT_ORDER       = 'sort_order';

    /**
     * Bindung an das Widget-Vokabular aus
     * SchemaOrgData_SchemaRepository - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const WIDGET_TEXT                    = SchemaOrgData_SchemaRepository::WIDGET_TEXT;
    private const WIDGET_TEXTAREA                = SchemaOrgData_SchemaRepository::WIDGET_TEXTAREA;
    private const WIDGET_SELECT                  = SchemaOrgData_SchemaRepository::WIDGET_SELECT;
    private const WIDGET_POSTAL_ADDRESS          = SchemaOrgData_SchemaRepository::WIDGET_POSTAL_ADDRESS;
    private const WIDGET_OPENING_HOURS           = SchemaOrgData_SchemaRepository::WIDGET_OPENING_HOURS;
    private const WIDGET_GEO                     = SchemaOrgData_SchemaRepository::WIDGET_GEO;
    private const WIDGET_PLACE                   = SchemaOrgData_SchemaRepository::WIDGET_PLACE;
    private const WIDGET_FAQ_LIST                = SchemaOrgData_SchemaRepository::WIDGET_FAQ_LIST;
    private const WIDGET_ID_REFERENCE            = SchemaOrgData_SchemaRepository::WIDGET_ID_REFERENCE;
    private const WIDGET_ID_REFERENCE_OR_LITERAL = SchemaOrgData_SchemaRepository::WIDGET_ID_REFERENCE_OR_LITERAL;
    /**
     * Bindung an die ui:-Schluesselnamen aus
     * SchemaOrgData_SchemaRepository - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const UI_WIDGET                     = SchemaOrgData_SchemaRepository::UI_WIDGET;
    private const UI_LABEL                      = SchemaOrgData_SchemaRepository::UI_LABEL;
    private const UI_REQUIRED                   = SchemaOrgData_SchemaRepository::UI_REQUIRED;
    private const UI_PLACEHOLDER                = SchemaOrgData_SchemaRepository::UI_PLACEHOLDER;
    private const UI_PLACEHOLDER_KEY            = SchemaOrgData_SchemaRepository::UI_PLACEHOLDER_KEY;
    private const UI_ENUM_LABELS                = SchemaOrgData_SchemaRepository::UI_ENUM_LABELS;
    private const UI_ALLOW_LITERAL              = SchemaOrgData_SchemaRepository::UI_ALLOW_LITERAL;
    private const UI_LITERAL_FIELDS             = SchemaOrgData_SchemaRepository::UI_LITERAL_FIELDS;
    private const UI_LITERAL_FIELD_PLACEHOLDERS = SchemaOrgData_SchemaRepository::UI_LITERAL_FIELD_PLACEHOLDERS;
    private const UI_ID_FRAGMENT                = SchemaOrgData_SchemaRepository::UI_ID_FRAGMENT;
    private const UI_ID_TARGET                  = SchemaOrgData_SchemaRepository::UI_ID_TARGET;
    private const UI_DAY_LABEL_KEYS             = SchemaOrgData_SchemaRepository::UI_DAY_LABEL_KEYS;

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
        $icons = [
            self::SHARED_STATUS_OK      => '&#9989;',
            self::SHARED_STATUS_WARNING => '&#9888;&#65039;',
            self::SHARED_STATUS_ERROR   => '&#10060;',
        ];

        if($result['status'] === null or !isset($icons[$result['status']])) {
            return '';
        }

        $message = $result['message'] !== null
            ? ' '.htmlspecialchars($result['message'], ENT_QUOTES, CHARSET)
            : '';

        $idAttr = $feedbackId !== null
            ? ' id="'.htmlspecialchars($feedbackId, ENT_QUOTES, CHARSET).'"'
            : '';

        return '<span'.$idAttr.' class="'.self::SHARED_CLASS_FEEDBACK
            .$result['status'].'">'
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
        $placeholder = htmlspecialchars((string) ($fieldSchema[self::UI_PLACEHOLDER] ?? ''), ENT_QUOTES, CHARSET);

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
        $placeholder = htmlspecialchars((string) ($fieldSchema[self::UI_PLACEHOLDER] ?? ''), ENT_QUOTES, CHARSET);

        return '<textarea id="'.$id.'" name="'.$name.'" class="mo-input-text schemaOrgData-wide-textarea" rows="4" placeholder="'.$placeholder.'">'
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
            $enumLabels = $fieldSchema[self::UI_ENUM_LABELS][$pluginLang] ?? [];
            foreach($fieldSchema['enum'] as $enumValue) {
                $options[(string) $enumValue] = (string) ($enumLabels[$enumValue] ?? $enumValue);
            }
        }

        $current = ($value !== null and $value !== '') ? (string) $value : (string) ($fieldSchema['default'] ?? '');
        $required = (bool) ($fieldSchema[self::UI_REQUIRED] ?? false);

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
    * und „Manuell" (Literal-Felder gemäß ui:literalFields). Literal-Felder
    * erhalten optional einen Placeholder mit Beispieltext, sofern das
    * Schema für das jeweilige Feld einen Sprachschlüssel in
    * ui:literalFieldPlaceholders hinterlegt.
    *
    * ui:literalFieldLabels ist eine Reserve, kein gepflegter Bestand:
    * Der Schlüssel ordnet einem Literal-Feld einen eigenen
    * Sprachschlüssel zu, wird von keinem produktiven Schema gesetzt und
    * ist daher unerprobt. Ohne ihn greift der Fallback 'label_'.$lf, der
    * aus dem Schema-Feldnamen einen Sprachschlüssel bildet - für die
    * drei heutigen Literal-Felder ist das label_name. Ein künftiges
    * ui:literalFields-Feld ohne passenden label_<feldname>-Eintrag zeigt
    * im Formular den Platzhaltertext des Kerns statt eines Labels.
    *
    * @param string $scope  Geltungsbereich (global/category/page)
    * @param string $name   Property-Name im Schema
    * @param array<string, mixed> $fieldSchema Schema-Definition der Property -
    *        die optionalen Properties ui:referenceTargets (Array aus
    *        "organization"/"persons", schränkt die Dropdown-Optionen ein)
    *        und ui:allowLiteral (bool, Default true, blendet bei false den
    *        Literal-Modus inkl. Umschalter vollständig aus) steuern die
    *        Einschränkbarkeit dieses Widgets, siehe README.md
    * @param array<string, mixed> $value   Gespeicherter Wert ['_mode' => ..., ...]
    * @param string $idPrefix Präfix für HTML-IDs
    * @param Language $lang für die Widget-Labels
    * @param array<string,string> $availableFragments Fragment => Label-Map
    *        (siehe IdReferenceService::resolveAvailableGlobalFragments()),
    *        von der Fassade einmal je renderTypeFields()-Aufruf berechnet -
    *        wird hier zusätzlich je Feldkonfiguration über
    *        IdReferenceService::filterFragmentsByReferenceTargets() gefiltert
    * @return string HTML des Widgets - die beiden Modus-Radios tragen einen
    *         je Instanz eindeutigen (nicht submittierten) Namen, der
    *         tatsächlich gespeicherte _mode-Wert läuft über ein verstecktes
    *         Feld mit dem regulären Feldnamen, das der data-action-Handler
    *         "idrl-toggle" (js/validator.js) nachführt
    *
    ***************************************************************/
    public function renderIdReferenceOrLiteralWidget(string $scope, string $name, array $fieldSchema, array $value, string $idPrefix, Language $lang, array $availableFragments): string {
        $availableFragments = SchemaOrgData_IdReferenceService::filterFragmentsByReferenceTargets($availableFragments, $fieldSchema);

        // ui:allowLiteral = false reduziert das Widget auf einen einzigen
        // Modus (Referenz) - ein gespeicherter Literal-Altwert wird dann
        // nicht mehr redisplayt, da diese Feldkonfiguration den Modus gar
        // nicht mehr anbietet (Umschalter/Literal-Felder/POST-Feld entfallen
        // komplett statt nur versteckt zu werden).
        $allowLiteral = (bool) ($fieldSchema[self::UI_ALLOW_LITERAL] ?? true);
        $storedMode = $allowLiteral ? (string) ($value['_mode'] ?? 'reference') : 'reference';
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
        // versteckte Feld $modeField, dessen Value der data-action-Handler
        // "idrl-toggle" (js/validator.js) bei jeder Radio-Auswahl mitführt.
        $radioGroupName = 'schemaOrgData_idrl_'.$idPrefix.'_'.$name.'_mode';

        $html  = '<div class="schemaOrgData-idrl-container" id="'.htmlspecialchars($containerId, ENT_QUOTES, CHARSET).'">'."\n";

        $html .= '<input type="hidden" class="schemaOrgData-idrl-mode-field"'
            .' name="'.htmlspecialchars($modeField, ENT_QUOTES, CHARSET).'"'
            .' value="'.($storedMode === 'literal' ? 'literal' : 'reference').'" />'."\n";

        // Radio: Referenz-Modus - nur bei zwei verbleibenden Modi ein
        // Umschalter nötig; bei ui:allowLiteral === false ist Referenz der
        // einzige Modus, ein Umschalter wäre hier bedeutungslos.
        if($allowLiteral) {
            $html .= '<label class="schemaOrgData-idrl-radio-label">'
                .'<input type="radio" class="schemaOrgData-idrl-radio"'
                .' name="'.htmlspecialchars($radioGroupName, ENT_QUOTES, CHARSET).'" value="reference"'
                .$refChecked.' data-action="idrl-toggle" />'
                .' '.$lang->getLanguageHtml('label_id_reflit_reference')
                .'</label>'."\n";
        }

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

        // Radio: Literal-Modus + Literal-Felder - bei ui:allowLiteral === false
        // vollständig ausgeblendet: kein Umschalter, keine Eingabefelder, kein
        // zugehöriges POST-Feld.
        if($allowLiteral) {
            $html .= '<label class="schemaOrgData-idrl-radio-label">'
                .'<input type="radio" class="schemaOrgData-idrl-radio"'
                .' name="'.htmlspecialchars($radioGroupName, ENT_QUOTES, CHARSET).'" value="literal"'
                .$litChecked.' data-action="idrl-toggle" />'
                .' '.$lang->getLanguageHtml('label_id_reflit_literal')
                .'</label>'."\n";

            $html .= '<div class="schemaOrgData-idrl-section schemaOrgData-idrl-literal"'.$litHidden.'>'."\n";
            $literalFields       = $fieldSchema[self::UI_LITERAL_FIELDS]       ?? [];
            $literalFieldLabels  = $fieldSchema['ui:literalFieldLabels']  ?? [];
            $literalFieldPlaceholders = $fieldSchema[self::UI_LITERAL_FIELD_PLACEHOLDERS] ?? [];
            foreach($literalFields as $lf) {
                $lfId    = 'schemaOrgData_'.$idPrefix.'_'.$name.'_lf_'.$lf;
                $lfName  = $fieldNameBase.'['.$lf.']';
                $lfValue = (string) ($value[(string) $lf] ?? '');
                $lfLabelKey = $literalFieldLabels[(string) $lf] ?? 'label_'.$lf;
                $lfLabel = $lang->getLanguageHtml($lfLabelKey);
                $lfPlaceholderKey = $literalFieldPlaceholders[(string) $lf] ?? null;
                $lfPlaceholder = $lfPlaceholderKey !== null ? $lang->getLanguageValue($lfPlaceholderKey) : '';
                $html .= '<div class="c-content schemaOrgData-field-row">'."\n"
                    .'<div class="mo-in-li-l"><label for="'.htmlspecialchars($lfId, ENT_QUOTES, CHARSET).'">'.$lfLabel.'</label></div>'."\n"
                    .'<div class="mo-in-li-r"><input type="text" id="'.htmlspecialchars($lfId, ENT_QUOTES, CHARSET).'"'
                    .' name="'.htmlspecialchars($lfName, ENT_QUOTES, CHARSET).'"'
                    .' value="'.htmlspecialchars($lfValue, ENT_QUOTES, CHARSET).'"'
                    .' placeholder="'.htmlspecialchars($lfPlaceholder, ENT_QUOTES, CHARSET).'"'
                    .' class="mo-input-text flex-100" /></div>'."\n"
                    .'</div>'."\n";
            }
            $html .= '</div>'."\n";
        }
        $html .= '</div>'."\n";

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
    * @param bool $forceRequired wird unverändert an renderAddressSubField()
    *        durchgereicht (siehe dort)
    *
    ***************************************************************/
    public function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null, bool $forceRequired = false): string {
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
            $field = $this->renderAddressSubField($scope, $name, 'streetAddress', $properties['streetAddress'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix, $forceRequired);
            $html .= $this->renderAddressFullRow($field);
        }

        // PLZ + Ort kompakt in einer Zeile (PLZ schmal, Ort flexibel)
        $html .= $this->renderAddressFieldGroup($scope, $name, $properties, $value, $countryFieldId, $idPrefix, [
            'postalCode'      => true,
            'addressLocality' => false,
        ], $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix, $forceRequired);

        // Land: eigene Zeile, Select ~200px breit (siehe getAdminCss)
        if(isset($properties['addressCountry'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressCountry', $properties['addressCountry'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix, $forceRequired);
            $html .= $this->renderAddressFullRow($field);
        }

        // Region/Bundesland: eigene Zeile, ~300px breit (siehe getAdminCss)
        if(isset($properties['addressRegion'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressRegion', $properties['addressRegion'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix, $forceRequired);
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
    * @param bool $forceRequired erzwingt eine unconditional-Live-Pflichtmeldung
    *        (data-validate="required") statt der sonst üblichen
    *        gruppen-bedingten Prüfung (data-validate="address_required",
    *        siehe unten) - nur für ein als Ganzes ui:required markiertes
    *        place-Widget (z. B. JobPosting.jobLocation), das auch bei
    *        komplett leerer Adresse "Ort" erzwingen muss (siehe
    *        SchemaOrgData_Validator::validateFormData())
    * @return array{fieldId:string,label:string,badge:string,widget:string,feedback:string}
    *
    ***************************************************************/
    public function renderAddressSubField(string $scope, string $name, string $subName, array $subSchema, array $value, string $countryFieldId, string $idPrefix, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null, bool $forceRequired = false): array {
        $idSegment = $groupPrefix !== null ? $groupPrefix.'_'.$name : $name;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$idSegment.'_'.$subName;
        $fieldNameBase = $groupPrefix !== null
            ? 'schemaOrgData['.$scope.'][data]['.$groupPrefix.']['.$name.']'
            : 'schemaOrgData['.$scope.'][data]['.$name.']';
        $fieldName = $fieldNameBase.'['.$subName.']';
        $subValue = $value[$subName] ?? ($subSchema['default'] ?? null);
        $required = (bool) ($subSchema[self::UI_REQUIRED] ?? false);
        $label = $lang->getLanguageHtml($subSchema[self::UI_LABEL] ?? $subName);
        $badge = $this->renderRequiredBadge($required, $lang);

        // Placeholder + "ü"-Badge für ein leeres Sub-Feld, dessen Wert von
        // einer übergeordneten Ebene geerbt würde (siehe Task 1,
        // resolveInheritableFields()) - das Feld selbst bleibt leer.
        $isEmpty = !isset($value[$subName]) or $value[$subName] === '';
        $inheritedSubValue = $inheritedValue[$subName] ?? null;
        if($isEmpty and is_scalar($inheritedSubValue) and (string) $inheritedSubValue !== '') {
            if(($subSchema[self::UI_WIDGET] ?? self::WIDGET_TEXT) !== self::WIDGET_SELECT) {
                $subSchema[self::UI_PLACEHOLDER] = (string) $inheritedSubValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel, $lang);
        }

        // Sub-Felder ohne "default" (streetAddress, postalCode, addressLocality,
        // addressRegion - nicht addressCountry) zählen für die clientseitige
        // Gruppen-Prüfung "wurde überhaupt etwas in dieser Adresse ausgefüllt",
        // analog zu isAddressProvided() (siehe js/validator.js,
        // runAddressRequiredValidation()).
        $groupId = 'schemaOrgData_'.$idPrefix.'_'.$idSegment;

        if(($subSchema[self::UI_WIDGET] ?? self::WIDGET_TEXT) === self::WIDGET_SELECT) {
            $widgetHtml = $this->renderSelectWidget($fieldId, $fieldName, $subSchema, $subValue, $lang, $pluginLang);
        } else {
            $extraAttrs = [];
            if(!array_key_exists('default', $subSchema)) {
                $extraAttrs['data-address-group'] = $groupId;
            }
            if($subName === 'postalCode') {
                $extraAttrs[self::ATTR_VALIDATE] = self::VALIDATE_POSTAL_CODE;
                $extraAttrs['data-country-field'] = $countryFieldId;
            } elseif($required) {
                // Ohne $forceRequired hängt die Pflicht dieses Feldes davon ab,
                // ob überhaupt ein Geschwisterfeld der Adressgruppe befüllt ist
                // (data-validate="address_required", siehe js/validator.js) -
                // eine komplett leere, nicht als Ganzes ui:required markierte
                // Adresse (bzw. ein leeres place-Widget) bleibt sonst
                // fälschlich als "Ort fehlt" markiert.
                $extraAttrs[self::ATTR_VALIDATE] = $forceRequired ? self::VALIDATE_REQUIRED : self::VALIDATE_ADDRESS_REQUIRED;
                $extraAttrs['data-required-message'] = $lang->getLanguageValue('error_required_field', $lang->getLanguageValue($subSchema[self::UI_LABEL] ?? $subName));
            }
            $widgetHtml = $this->renderTextWidget($fieldId, $fieldName, $subSchema, $subValue, $extraAttrs);
        }

        $feedback = '';
        if($subName === 'postalCode' and $subValue !== null and $subValue !== '') {
            $countryCode = (string) ($value['addressCountry'] ?? 'DE');
            $feedback = $this->renderValidationFeedback($validator->validatePostalCode((string) $subValue, $countryCode, $lang), $fieldId.self::SHARED_ID_SUFFIX_FEEDBACK);
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
    * @param bool $forceRequired wird an renderAddressSubField() durchgereicht
    *        (siehe dort)
    *
    ***************************************************************/
    public function renderAddressFieldGroup(string $scope, string $name, array $properties, array $value, string $countryFieldId, string $idPrefix, array $subNames, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator, string $pluginLang, ?string $groupPrefix = null, bool $forceRequired = false): string {
        $html = '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"></div>'
            .'<div class="mo-in-li-r"><div class="schemaOrgData-address-row">'."\n";

        foreach($subNames as $subName => $narrow) {
            if(!isset($properties[$subName])) {
                continue;
            }
            $field = $this->renderAddressSubField($scope, $name, $subName, $properties[$subName], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel, $lang, $validator, $pluginLang, $groupPrefix, $forceRequired);
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
    * Rendert das Geo-Widget (GeoCoordinates: latitude/longitude) als
    * gruppierte Zeile (analog renderAddressFieldGroup(), PLZ+Ort).
    * Paar-Pflicht "beides oder nichts": ist nur eines der beiden Felder
    * gefüllt, wird am jeweils leeren Feld error_geo_incomplete
    * angezeigt (siehe resolveGeoFieldFeedback()); sind beide gefüllt,
    * wird jedes einzeln gegen seinen Wertebereich geprüft
    * (validateGeoLatitude/validateGeoLongitude - dieselben Methoden,
    * die bereits für das Erweiterungsfeld verwendet werden, siehe
    * validateExtensionGeo()).
    *
    * @param string $scope Geltungsbereich ('global'|'category'|'page')
    * @param string $name  Property-Name (üblicherweise "geo")
    * @param array<string, mixed> $fieldSchema bereits via resolveSchemaRef() aufgelöstes Schema
    * @param array<string, mixed> $value gespeicherte latitude/longitude-Werte
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param array|null $inheritedValue latitude/longitude, die von einer
    *        übergeordneten Ebene geerbt würden - nur für Placeholder +
    *        "ü"-Badge, wird nicht übernommen (analog renderAddressSubField())
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    * @param Language $lang für Labels/Badges/Fehlermeldungen
    * @param SchemaOrgData_Validator $validator für validateGeoLatitude()/validateGeoLongitude()
    *
    ***************************************************************/
    public function renderGeoWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix, ?array $inheritedValue, ?string $inheritedLabel, Language $lang, SchemaOrgData_Validator $validator): string {
        $idPrefix = $idPrefix ?? $scope;
        $properties = $fieldSchema['properties'] ?? [];
        $latSchema = $properties['latitude'] ?? [];
        $lonSchema = $properties['longitude'] ?? [];

        $latValue = $value['latitude'] ?? null;
        $lonValue = $value['longitude'] ?? null;
        $latString = trim((string) ($latValue ?? ''));
        $lonString = trim((string) ($lonValue ?? ''));

        $latId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_latitude';
        $lonId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_longitude';
        $latName = 'schemaOrgData['.$scope.'][data]['.$name.'][latitude]';
        $lonName = 'schemaOrgData['.$scope.'][data]['.$name.'][longitude]';

        // Placeholder + "ü"-Badge für ein leeres Sub-Feld, dessen Wert von
        // einer übergeordneten Ebene geerbt würde, analog
        // renderAddressSubField() - das Feld selbst bleibt leer.
        $latBadge = '';
        $lonBadge = '';
        $inheritedLat = $inheritedValue['latitude'] ?? null;
        $inheritedLon = $inheritedValue['longitude'] ?? null;
        if($latString === '' and is_scalar($inheritedLat) and (string) $inheritedLat !== '') {
            $latSchema[self::UI_PLACEHOLDER] = (string) $inheritedLat;
            $latBadge = $this->renderInheritedBadge($inheritedLabel, $lang);
        }
        if($lonString === '' and is_scalar($inheritedLon) and (string) $inheritedLon !== '') {
            $lonSchema[self::UI_PLACEHOLDER] = (string) $inheritedLon;
            $lonBadge = $this->renderInheritedBadge($inheritedLabel, $lang);
        }

        $latInput = $this->renderTextWidget($latId, $latName, $latSchema, $latValue, [
            self::ATTR_VALIDATE => self::VALIDATE_GEO, 'data-pair' => $lonId,
        ]);
        $lonInput = $this->renderTextWidget($lonId, $lonName, $lonSchema, $lonValue, [
            self::ATTR_VALIDATE => self::VALIDATE_GEO, 'data-pair' => $latId,
        ]);

        $latFeedback = $this->renderValidationFeedback(
            $this->resolveGeoFieldFeedback($latString, $lonString, true, $validator, $lang), $latId.self::SHARED_ID_SUFFIX_FEEDBACK
        );
        $lonFeedback = $this->renderValidationFeedback(
            $this->resolveGeoFieldFeedback($lonString, $latString, false, $validator, $lang), $lonId.self::SHARED_ID_SUFFIX_FEEDBACK
        );

        $latLabel = $lang->getLanguageHtml($latSchema[self::UI_LABEL] ?? 'latitude');
        $lonLabel = $lang->getLanguageHtml($lonSchema[self::UI_LABEL] ?? 'longitude');

        return '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"></div>'
            .'<div class="mo-in-li-r"><div class="schemaOrgData-address-row">'."\n"
            .'<div class="schemaOrgData-address-field">'
            .'<label for="'.$latId.'">'.$latLabel.$latBadge.'</label>'
            .$latInput.$latFeedback
            .'</div>'."\n"
            .'<div class="schemaOrgData-address-field">'
            .'<label for="'.$lonId.'">'.$lonLabel.$lonBadge.'</label>'
            .$lonInput.$lonFeedback
            .'</div>'."\n"
            .'</div></div></div>'."\n";
    }

    /***************************************************************
    *
    * Ermittelt das Validierungs-Feedback für ein einzelnes Feld des
    * Geo-Widgets (siehe renderGeoWidget()): Paar-Pflicht hat Vorrang -
    * ist das Gegenstück gefüllt, dieses Feld aber leer, ist das ein
    * Fehler (error_geo_incomplete), unabhängig vom eigenen Wertebereich.
    * Sind beide Felder leer, gilt das Paar als nicht angegeben (kein
    * Fehler). Sind beide gefüllt, entscheidet der eigene Wertebereich
    * (validateGeoLatitude()/validateGeoLongitude()).
    *
    * @param string $ownValue  getrimmter Wert dieses Feldes
    * @param string $pairValue getrimmter Wert des Gegenstücks
    * @param bool $isLatitude  true = $ownValue ist latitude, false = longitude
    * @param SchemaOrgData_Validator $validator für validateGeoLatitude()/validateGeoLongitude()
    * @param Language $lang für error_geo_incomplete
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function resolveGeoFieldFeedback(string $ownValue, string $pairValue, bool $isLatitude, SchemaOrgData_Validator $validator, Language $lang): array {
        if($ownValue === '' and $pairValue === '') {
            return ['status' => null, 'message' => null];
        }

        if($ownValue === '') {
            return ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_geo_incomplete')];
        }

        return $isLatitude ? $validator->validateGeoLatitude($ownValue, $lang) : $validator->validateGeoLongitude($ownValue, $lang);
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
    * @param bool $forceRequired unverändert das ui:required-Flag des gesamten
    *        place-Widgets (z. B. JobPosting.jobLocation) - erzwingt bei true
    *        eine unconditional-Live-Pflichtmeldung für "Ort" (siehe
    *        renderAddressSubField()); bei false (Default, z. B. Event.location)
    *        wird "Ort" nur dann live als Pflichtfeld gemeldet, wenn "name"
    *        oder ein anderes Adressfeld bereits befüllt ist (siehe
    *        js/validator.js, runAddressRequiredValidation() und
    *        SchemaOrgData_Validator::validateFormData(), $placeNameFilled)
    * @return string HTML-Snippet
    *
    ***************************************************************/
    public function renderPlaceWidget(string $scope, string $name, array $fieldSchema, array $value, array $rootSchema, ?string $idPrefix, Language $lang, SchemaOrgData_SchemaRepository $schemaRepository, SchemaOrgData_Validator $validator, string $pluginLang, bool $forceRequired = false): string {
        $idPrefix = $idPrefix ?? $scope;
        $properties = $fieldSchema['properties'] ?? [];
        $html = '';

        // Gruppen-Id der verschachtelten Adresse (siehe renderAddressSubField()) -
        // das "name"-Feld liegt außerhalb von "address", zählt aber ebenfalls
        // zur "wurde dieses place-Widget überhaupt angefasst"-Prüfung
        // (data-address-group, siehe js/validator.js).
        $addressGroupId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_address';

        if(isset($properties['name'])) {
            $nameSchema = $properties['name'];
            $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_name';
            $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.'][name]';
            $nameValue = $value['name'] ?? null;
            $label = $lang->getLanguageHtml($nameSchema[self::UI_LABEL] ?? 'label_name');
            $widgetHtml = $this->renderTextWidget($fieldId, $fieldName, $nameSchema, $nameValue, ['data-address-group' => $addressGroupId]);
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$label.'</label></div>'
                .'<div class="mo-in-li-r">'.$widgetHtml.'</div>'
                .'</div>'."\n";
        }

        if(isset($properties['address'])) {
            $addressSchema = $schemaRepository->resolveSchemaRef($properties['address'], $rootSchema);
            $addressValue = is_array($value['address'] ?? null) ? $value['address'] : [];
            $html .= $this->renderPostalAddressWidget($scope, 'address', $addressSchema, $addressValue, $idPrefix, null, null, $lang, $validator, $pluginLang, $name, $forceRequired);
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
        $days = SchemaOrgData_OpeningHoursHelper::resolveDays($fieldSchema);
        $dayLabelKeys = $fieldSchema[self::UI_DAY_LABEL_KEYS] ?? [];

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
            $fromId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.self::SHARED_ID_SLOT_FROM;
            $toId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.self::SHARED_ID_SLOT_TO;
            $from2Id = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.self::SHARED_ID_SLOT_FROM2;
            $to2Id = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$day.'_to2';
            $fromName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][from]';
            $toName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][to]';
            $from2Name = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][from2]';
            $to2Name = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][to2]';
            $from  = trim((string) ($perDay[$day]['from']  ?? ''));
            $to    = trim((string) ($perDay[$day]['to']    ?? ''));
            $from2 = trim((string) ($perDay[$day]['from2'] ?? ''));
            $to2   = trim((string) ($perDay[$day]['to2']   ?? ''));

            $fromInput = $this->renderTextWidget($fromId, $fromName, [self::UI_PLACEHOLDER => '09:00'], $from, [
                self::ATTR_VALIDATE => self::VALIDATE_OPENING_HOURS, 'data-pair' => $toId, 'maxlength' => '5',
            ]);
            $toInput = $this->renderTextWidget($toId, $toName, [self::UI_PLACEHOLDER => '18:00'], $to, [
                self::ATTR_VALIDATE => self::VALIDATE_OPENING_HOURS, 'data-pair' => $fromId, 'maxlength' => '5',
            ]);
            $from2Input = $this->renderTextWidget($from2Id, $from2Name, [self::UI_PLACEHOLDER => '13:00'], $from2, [
                self::ATTR_VALIDATE => self::VALIDATE_OPENING_HOURS, 'data-pair' => $to2Id, 'maxlength' => '5',
            ]);
            $to2Input = $this->renderTextWidget($to2Id, $to2Name, [self::UI_PLACEHOLDER => '18:00'], $to2, [
                self::ATTR_VALIDATE => self::VALIDATE_OPENING_HOURS, 'data-pair' => $from2Id, 'maxlength' => '5',
            ]);

            $feedback = $this->renderValidationFeedback($validator->validateOpeningHoursTime($from, $to, $lang), $fromId.self::SHARED_ID_SUFFIX_FEEDBACK);

            $feedback2Result = $validator->validateOpeningHoursTime($from2, $to2, $lang);
            // Eigener Format-/Reihenfolgefehler der Pause hat Vorrang: nur wenn
            // validateOpeningHoursTime() nicht bereits 'error' liefert, wird die
            // Überlappungs-Prüfung angewandt (analog zu runOpeningHoursValidation()
            // in js/validator.js). Der ursprüngliche Vergleich auf
            // status === null war unerreichbar, da diese Methode bei nicht-leeren
            // $from2/$to2-Werten nie null zurückliefert.
            if($feedback2Result['status'] !== self::SHARED_STATUS_ERROR && $from2 !== '' && $to2 !== '' && $to !== '' && $from2 < $to) {
                $feedback2Result = ['status' => self::SHARED_STATUS_ERROR, 'message' => $lang->getLanguageValue('error_opening_hours_overlap')];
            }
            $feedback2 = $this->renderValidationFeedback($feedback2Result, $from2Id.self::SHARED_ID_SUFFIX_FEEDBACK);

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

            $questionLabel = $lang->getLanguageHtml($questionSchema[self::UI_LABEL] ?? 'label_faq_question');
            $answerLabel = $lang->getLanguageHtml($answerSchema[self::UI_LABEL] ?? 'label_faq_answer');
            $badge = $this->renderRequiredBadge((bool) ($questionSchema[self::UI_REQUIRED] ?? false), $lang);

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
    * Rendert das Widget der Organisations-Relationen (founder/employee/
    * member zwischen der globalen Organisations-Identität und
    * Registry-Personen, siehe SchemaOrgData_OrgRelationsService und
    * README.md, Abschnitt "Organisations-Identität und @id-Anker"): je Relation
    * ein Personen-Dropdown und ein Rollen-Dropdown, plus eine
    * zusätzliche leere Zeile zum Anlegen einer neuen Relation (analog
    * renderFaqListWidget()). Erscheint im Formular ausschließlich für
    * global konfigurierte Types mit "ui:idFragment": "organization"
    * (siehe SchemaOrgData_AdminController::renderScopeSection()).
    *
    * @param string $scope stets "global" (org_relations ist ein reiner Global-Meta-Schlüssel)
    * @param array<int, array{person?: mixed, role?: mixed}> $orgRelations gespeicherte bzw. POST-Rohdaten
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param Language $lang für Labels/Hinweise
    * @param array<string,string> $availablePersons Slug => Label, nur aktive Registry-Personen
    *        (gefiltert aus SchemaOrgData_IdReferenceService::resolveAvailableGlobalFragments())
    *
    ***************************************************************/
    public function renderOrgRelationsWidget(string $scope, array $orgRelations, ?string $idPrefix, Language $lang, array $availablePersons): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldNameBase = 'schemaOrgData['.$scope.'][org_relations]';
        $markerName = 'schemaOrgData['.$scope.'][org_relations_marker]';

        $html = '<fieldset class="schemaOrgData-fieldset">'."\n";
        $html .= '<legend>'.$lang->getLanguageHtml('label_org_relations').'</legend>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_org_relations').'</p>'."\n";

        // Marker-Feld, unabhängig von $availablePersons: signalisiert dem
        // Server "dieses Widget wurde tatsächlich gerendert und abgeschickt",
        // auch wenn 0 Personen verfügbar sind und daher keine
        // org_relations[]-Zeilen existieren. Ohne diesen Marker greift der
        // array_key_exists("org_relations", ...)-Guard in
        // SchemaOrgData_ConfigSaveService::saveConfig() fälschlich den für
        // Type-Wechsel gedachten "Feld fehlt komplett"-Fall, und eine zuvor
        // gespeicherte, jetzt verwaiste Relation bleibt unbereinigt stehen.
        $html .= '<input type="hidden" name="'.htmlspecialchars($markerName, ENT_QUOTES, CHARSET).'" value="1">'."\n";

        // Der Hinweistext ist seit dem Sichtbarkeits-Fix (siehe README.md,
        // Abschnitt "Organisations-Relationen") kein Ersatz mehr für die
        // Entries-Liste, sondern ein zusätzlicher, erklärender Hinweis: auch
        // ohne aktive Registry-Personen sollen bereits gespeicherte
        // Relationen-Zeilen sichtbar bleiben, statt kommentarlos zu
        // verschwinden.
        if($availablePersons === []) {
            $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('hint_org_relations_no_persons').'</p>'."\n";
        }

        $entries = array_values($orgRelations);
        $entries[] = ['person' => '', 'role' => ''];

        foreach($entries as $index => $entry) {
            $personId = 'schemaOrgData_'.$idPrefix.'_org_relations_'.$index.'_person';
            $roleId = 'schemaOrgData_'.$idPrefix.'_org_relations_'.$index.'_role';
            $personName = $fieldNameBase.'['.$index.'][person]';
            $roleName = $fieldNameBase.'['.$index.'][role]';
            $personValue = (string) ($entry['person'] ?? '');
            $roleValue = (string) ($entry['role'] ?? '');

            $html .= '<div class="schemaOrgData-org-relation-entry">'."\n";

            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$personId.'">'.$lang->getLanguageHtml('label_org_relation_person').'</label></div>'
                .'<div class="mo-in-li-r"><div class="mo-select-div flex"><select id="'.$personId.'" name="'.htmlspecialchars($personName, ENT_QUOTES, CHARSET).'" class="mo-select flex-100">'."\n"
                .'<option value="">'.$lang->getLanguageHtml('label_select_placeholder').'</option>'."\n";
            foreach($availablePersons as $slug => $personLabel) {
                $selected = ((string) $slug === $personValue) ? ' selected="selected"' : '';
                $html .= '<option value="'.htmlspecialchars((string) $slug, ENT_QUOTES, CHARSET).'"'.$selected.'>'
                    .htmlspecialchars($personLabel, ENT_QUOTES, CHARSET).'</option>'."\n";
            }
            // Ein gespeicherter Slug, der nicht (mehr) unter den aktiven
            // Personen gelistet ist (inaktiv gesetzt oder gelöscht), erhält
            // trotzdem eine ausgewählte <option> - ohne sie überträgt der
            // Browser beim nächsten Speichern keinen Wert für dieses Select,
            // und die Relation ginge dadurch ungewollt verloren statt
            // unverändert bestehen zu bleiben, bis der Admin sie bewusst
            // entfernt. Die stets angehängte leere Anlege-Zeile hat einen
            // leeren $personValue und ist von dieser Fallback-Option nicht
            // betroffen.
            if($personValue !== '' and !array_key_exists($personValue, $availablePersons)) {
                $html .= '<option value="'.htmlspecialchars($personValue, ENT_QUOTES, CHARSET).'" selected="selected">'
                    .$lang->getLanguageHtml('label_org_relation_person_unavailable', $personValue).'</option>'."\n";
            }
            $html .= '</select></div></div></div>'."\n";

            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$roleId.'">'.$lang->getLanguageHtml('label_org_relation_role').'</label></div>'
                .'<div class="mo-in-li-r"><div class="mo-select-div flex"><select id="'.$roleId.'" name="'.htmlspecialchars($roleName, ENT_QUOTES, CHARSET).'" class="mo-select flex-100">'."\n";
            foreach(SchemaOrgData_OrgRelationsService::roles() as $role) {
                $selected = ($role === $roleValue) ? ' selected="selected"' : '';
                $html .= '<option value="'.$role.'"'.$selected.'>'.$lang->getLanguageHtml('label_role_'.$role).'</option>'."\n";
            }
            $html .= '</select></div></div></div>'."\n";

            $html .= '</div>'."\n";
        }

        // Wiederverwendung des bereits am Formularanfang vorhandenen
        // Personen-Registry-Umschalters (siehe SchemaOrgData_AdminController::
        // renderAdminPage()) - kein Modal, kein eigener JS-Mechanismus: beide
        // Buttons lösen dieselbe Aktion "persons-open" aus.
        $html .= '<p class="schemaOrgData-hint">'
            .'<button type="button" class="mo-btn" data-action="persons-open">'
            .$lang->getLanguageHtml('button_manage_persons').'</button></p>'."\n";

        $html .= '</fieldset>'."\n";

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
    * @param bool $personSuggestionContext true, wenn dieses Erweiterungsfeld
    *        im globalen Geltungsbereich eines Organisations-Identity-Types
    *        ("ui:idFragment": "organization") steht - nur dort bietet die
    *        Serverseite eine Übernahme in die Personen-Registry an, und nur
    *        dort darf js/validator.js den Info-Hinweis an die Stelle der
    *        Unbekannt-Warnung setzen (siehe README.md, Abschnitt
    *        "Erweiterungsfeld"). Gebildet wird die Bedingung in
    *        renderTypeFields(), das beide Werte bereits führt.
    *
    ***************************************************************/
    public function renderExtensionFieldWidget(string $scope, string $type, string $extensionJson, ?string $idPrefix, Language $lang, string $pluginSelfUrl, bool $personSuggestionContext = false): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_extension';
        $fieldName = 'schemaOrgData['.$scope.'][extension]['.$type.']';
        $schemaUrl = $pluginSelfUrl.'schemas/'.$type.'.json';

        // Die Anwesenheit des Attributs ist die Aussage, es trägt keinen Wert -
        // Muster der booleschen HTML-Attribute (disabled/required). Damit gibt
        // es über die Grenze PHP/JS kein Literal, dessen Wert synchron gehalten
        // werden müsste; js/validator.js liest hasAttribute().
        $suggestionAttr = $personSuggestionContext ? ' data-person-suggestions' : '';

        $html = '<fieldset class="schemaOrgData-fieldset">'."\n";
        $html .= '<legend>'.$lang->getLanguageHtml('label_extension_field').'</legend>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_extension_field').'</p>'."\n";
        $html .= '<textarea id="'.$fieldId.'" name="'.$fieldName.'" class="mo-input-text schemaOrgData-wide-textarea schemaOrgData-extension-field" '
            .'rows="12" data-schema-url="'.htmlspecialchars($schemaUrl, ENT_QUOTES, CHARSET).'"'.$suggestionAttr.'>'
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
        $required = (bool) ($fieldSchema[self::UI_REQUIRED] ?? false);

        if($format === 'uri') {
            $attrs = [self::ATTR_VALIDATE => self::VALIDATE_URL];
        } elseif($format === 'email') {
            $attrs = [self::ATTR_VALIDATE => self::VALIDATE_EMAIL];
        } elseif($format === 'date-time') {
            $attrs = [self::ATTR_VALIDATE => self::VALIDATE_DATE_TIME];
            // Nur das jeweils spätere Feld eines bekannten Datumsbereichs
            // (Event endDate, JobPosting validThrough) erhält die
            // Gegenstück-Referenz auf das frühere Feld - der Bereichsfehler
            // wird nur einmal gemeldet (analog zur serverseitigen Logik in
            // SchemaOrgData_Validator::validateDateRange(), die den Fehler
            // ebenfalls nur einmal in $errors[] einträgt).
            $dateRangeStartFields = ['endDate' => 'startDate', 'validThrough' => 'datePosted'];
            if(isset($dateRangeStartFields[$name])) {
                $attrs['data-range-start-field'] = 'schemaOrgData_'.$idPrefix.'_'.$dateRangeStartFields[$name];
            }
            // Vergangenheits-Hinweis (nicht-blockierende Warnung, siehe validator.js
            // isEventDateInPast()) ausschließlich für Event.startDate - anders als
            // z. B. JobPosting.datePosted, das seiner Natur nach regelmäßig in der
            // Vergangenheit liegt, deutet ein vergangener Event-Termin typischerweise
            // auf ein versehentliches Datum hin.
            if($name === 'startDate') {
                $attrs['data-check-past'] = '1';
            }
        } elseif($name === 'telephone') {
            $attrs = [self::ATTR_VALIDATE => self::VALIDATE_TELEPHONE];
            // Nur setzen, wenn das Schema tatsächlich ein address-Property
            // hat (Person/Organization haben keins - das Attribut zeigte
            // dort zuvor auf ein nie gerendertes Element).
            if(isset($rootSchema['properties']['address'])) {
                $attrs['data-country-field'] = 'schemaOrgData_'.$idPrefix.'_address_addressCountry';
            }
        } elseif($required) {
            $attrs = [self::ATTR_VALIDATE => self::VALIDATE_REQUIRED];
        } else {
            $attrs = [];
        }

        if($required) {
            $label = $lang->getLanguageValue($fieldSchema[self::UI_LABEL] ?? $name);
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
        $widget = $fieldSchema[self::UI_WIDGET] ?? self::WIDGET_TEXT;
        $label = $lang->getLanguageHtml($fieldSchema[self::UI_LABEL] ?? $name);
        $required = (bool) ($fieldSchema[self::UI_REQUIRED] ?? false);
        $badge = $this->renderRequiredBadge($required, $lang);
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name;

        // date-time-Felder (Event.startDate/endDate): gespeicherter
        // ISO-8601-Wert wird für die Anzeige nach TT.MM.YYYY[ HH:MM]
        // zurückformatiert (symmetrisch zu normalizeEventDateInput()) -
        // wirkt sich nicht auf das Speicherformat aus, nur auf
        // Eingabefeld und Feedback.
        if(($fieldSchema['format'] ?? null) === 'date-time' and is_string($value) and $value !== '') {
            $value = $validator->formatEventDateForDisplay($value);
        }

        $isEmpty = ($value === null or $value === '' or $value === []);

        // id_reference: rein deklaratives Widget ohne Eingabefeld.
        // Der Wert wird zur Build-Zeit in buildJsonLdScript() emittiert;
        // im Formular genügt eine schreibgeschützte Info-Anzeige mit der
        // aufgelösten Ziel-URI.
        if($widget === self::WIDGET_ID_REFERENCE) {
            $target = trim((string) ($fieldSchema[self::UI_ID_TARGET] ?? ''));
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

        if($widget === self::WIDGET_ID_REFERENCE_OR_LITERAL) {
            // Lesekompatibilität für Freitext-Bestandsdaten (z. B. Article.author
            // vor der Umstellung auf dieses Widget): ein gespeicherter reiner
            // String wird beim Redisplay transparent als Literal-Wert im ersten
            // konfigurierten Literal-Feld interpretiert, ohne die Settings zu
            // verändern - erst ein erneutes Speichern schreibt das reguläre
            // {_mode, ...}-Format.
            if(is_string($value) and $value !== '') {
                $literalFields = $fieldSchema[self::UI_LITERAL_FIELDS] ?? [];
                $primaryField = (string) ($literalFields[0] ?? 'name');
                $value = ['_mode' => 'literal', $primaryField => $value];
            }
            $inner = $this->renderIdReferenceOrLiteralWidget(
                $scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang, $availableFragments
            );
            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        if($widget === self::WIDGET_PLACE) {
            // Das ui:required-Flag des gesamten Widgets (z. B. JobPosting.jobLocation)
            // wird als $forceRequired durchgereicht - nur dann bleibt die
            // Live-Pflichtmeldung für "Ort" unconditional (siehe renderPlaceWidget()).
            $inner = $this->renderPlaceWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $rootSchema, $idPrefix, $lang, $schemaRepository, $validator, $pluginLang, $required);
            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        if(in_array($widget, [self::WIDGET_POSTAL_ADDRESS, self::WIDGET_OPENING_HOURS, self::WIDGET_FAQ_LIST, self::WIDGET_GEO], true)) {
            $inner = match($widget) {
                self::WIDGET_POSTAL_ADDRESS => $this->renderPostalAddressWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, is_array($inheritedValue) ? $inheritedValue : null, $inheritedLabel, $lang, $validator, $pluginLang),
                self::WIDGET_OPENING_HOURS  => $this->renderOpeningHoursWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang, $weekdayLang, $openingHoursHelper, $validator),
                self::WIDGET_FAQ_LIST       => $this->renderFaqListWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, $lang),
                self::WIDGET_GEO            => $this->renderGeoWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, is_array($inheritedValue) ? $inheritedValue : null, $inheritedLabel, $lang, $validator),
                default          => '',
            };

            if($widget === self::WIDGET_POSTAL_ADDRESS) {
                $inner = '<p class="schemaOrgData-hint">'
                    .$lang->getLanguageHtml('hint_address_conditional_required')
                    .'</p>'."\n".$inner;
            }

            if($widget === self::WIDGET_GEO) {
                $inner = '<p class="schemaOrgData-hint">'
                    .$lang->getLanguageHtml('hint_geo_conditional_required')
                    .'</p>'."\n".$inner;
            }

            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        // Formatanleitende Platzhalter stehen als Sprachschlüssel im Schema
        // (ui:placeholderKey) statt literal, damit sie der Admin-Sprache
        // folgen. Aufgelöst wird hier statt in den Widgets: die nehmen kein
        // Language-Objekt entgegen. Die Zuweisung steht bewusst vor der
        // Vererbungsanzeige, deren Platzhalter den Formathinweis
        // überschreiben soll, nicht umgekehrt.
        if(isset($fieldSchema[self::UI_PLACEHOLDER_KEY])) {
            $fieldSchema[self::UI_PLACEHOLDER] = $lang->getLanguageValue((string) $fieldSchema[self::UI_PLACEHOLDER_KEY]);
        }

        // Placeholder + "ü"-Badge für ein leeres Feld, dessen Wert von einer
        // übergeordneten Ebene geerbt würde (siehe Task 1,
        // resolveInheritableFields()) - das Feld selbst bleibt leer.
        if($isEmpty and is_scalar($inheritedValue) and (string) $inheritedValue !== '') {
            if($widget !== self::WIDGET_SELECT) {
                $placeholderValue = (string) $inheritedValue;
                if(($fieldSchema['format'] ?? null) === 'date-time') {
                    $placeholderValue = $validator->formatEventDateForDisplay($placeholderValue);
                }
                $fieldSchema[self::UI_PLACEHOLDER] = $placeholderValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel, $lang);
        }

        $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']';

        $widgetHtml = match($widget) {
            self::WIDGET_SELECT   => $this->renderSelectWidget($fieldId, $fieldName, $fieldSchema, $value, $lang, $pluginLang),
            self::WIDGET_TEXTAREA => $this->renderTextareaWidget($fieldId, $fieldName, $fieldSchema, $value),
            default    => $this->renderTextWidget($fieldId, $fieldName, $fieldSchema, $value, $this->buildValidationAttrs($scope, $name, $fieldSchema, $rootSchema, $idPrefix, $lang)),
        };

        $feedback = ($value !== null and $value !== '' and is_scalar($value))
            ? $this->renderFieldFeedback($name, $fieldSchema, (string) $value, $allData, $fieldId.self::SHARED_ID_SUFFIX_FEEDBACK, $validator, $lang)
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

        $hasRequiredField = false;
        foreach($schema['properties'] ?? [] as $fieldSchema) {
            if(!empty($fieldSchema[self::UI_REQUIRED])) {
                $hasRequiredField = true;
                break;
            }
        }

        $html = $hasRequiredField
            ? '<p class="schemaOrgData-required-legend">'.$lang->getLanguageHtml('label_required_legend').'</p>'."\n"
            : '';
        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $html .= $this->renderField(
                $scope, $name, $fieldSchema, $formData[$name] ?? null, $schema, $formData, $idPrefix,
                $inheritable['data'][$name] ?? null, $inheritable['originLabel'][$name] ?? null,
                $lang, $schemaRepository, $urlHelper, $pluginLang, $openingHoursHelper, $validator, $weekdayLang, $availableFragments,
            );
        }

        // Spiegelt die Bedingung, unter der die Admin-Seite den
        // Übernahme-Vorschlag überhaupt anbietet: globaler Geltungsbereich und
        // Organisations-Identity-Type. Beide Werte liegen hier bereits als
        // Parameter vor, deshalb bleibt der Aufrufer unberührt. Ändert sich die
        // Bedingung dort, gehört sie hier mitgeändert.
        $personSuggestionContext = ($scope === self::SCOPE_GLOBAL and ($schema[self::UI_ID_FRAGMENT] ?? '') === SchemaOrgData_IdReferenceService::IDFRAGMENT_ORGANIZATION);

        $html .= $this->renderExtensionFieldWidget($scope, $type, $extensionJson, $idPrefix, $lang, $pluginSelfUrl, $personSuggestionContext);

        return $html;
    }

}
