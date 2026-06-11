<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* schemaOrgData
*
* Schreibt Schema.org-konformes JSON-LD in den <head>-Bereich
* der Seite. Ergänzt die im moziloCMS-Core bereits vorhandenen
* Microdata-Implementierungen (Article, ImageObject,
* BreadcrumbList, Contact) um maschinenlesbare JSON-LD-Blöcke.
*
* Geltungsbereiche und Vererbung (allgemein -> spezifisch):
*   conf/_global.conf.php              -> jede Seite
*   conf/cat_{kategorie}.conf.php      -> alle Seiten der Kategorie
*   conf/page_{kategorie}_{seite}.conf.php -> nur diese Seite
*
* Jede conf-Datei enthält ein serialisiertes Array der Form
*   array('LocalBusiness' => array('name' => '...', ...), ...)
*
* Neue Schema-Types werden unterstützt, indem einfach eine
* weitere .json-Datei in schemas/ abgelegt wird (kein PHP nötig).
* Die JSON-Schema-Dateien definieren sowohl die Validierungsregeln
* als auch die Formularfelder (über "ui:"-Properties).
*
***************************************************************/
class schemaOrgData extends Plugin {

    /** Standard-Sprache, falls die CMS-/Admin-Sprache nicht unterstützt wird */
    private const DEFAULT_LANGUAGE = 'de';

    /** Vom Plugin unterstützte Sprachen (sprachen/*_{code}.txt) */
    private const SUPPORTED_LANGUAGES = ['de', 'en'];

    /** Sprachobjekt für Admin-UI (sprachen/admin_language_{lang}.txt) */
    private ?Language $admin_lang = null;

    /** Sprachobjekt für Frontend/CMS-Kontext (sprachen/cms_language_{lang}.txt) */
    private ?Language $cms_lang = null;

    /***************************************************************
    *
    * Gibt die JSON-LD <script>-Blöcke für die aktuelle Seite zurück.
    *
    * Wird über einen Platzhalter (z. B. {schemaOrgData}) im
    * <head>-Bereich des Layout-Templates ausgegeben.
    *
    ***************************************************************/
    function getContent($value): string {
        global $CMS_CONF;

        $this->cms_lang = new Language(
            $this->PLUGIN_SELF_DIR.'sprachen/cms_language_'
                .$this->resolvePluginLanguage($CMS_CONF->get('cmslanguage')).'.txt'
        );

        $output = '';

        // Konfiguration je Geltungsebene laden (sofern vorhanden)
        $scopeConfigs = ['global' => $this->loadScopeConfig('global')];

        if(defined('CAT_REQUEST') and CAT_REQUEST) {
            $scopeConfigs['category'] = $this->loadScopeConfig('category', CAT_REQUEST);
        }
        if(defined('CAT_REQUEST') and defined('PAGE_REQUEST') and CAT_REQUEST and PAGE_REQUEST) {
            $scopeConfigs['page'] = $this->loadScopeConfig('page', CAT_REQUEST, PAGE_REQUEST);
        }

        // TODO: für jeden in $scopeConfigs vorkommenden Schema-Type:
        //  - zugehöriges Schema aus schemas/{Type}.json laden (loadSchema)
        //  - anhand "ui:scopes" prüfen, in welchen Ebenen der Type erlaubt ist
        //  - Properties der zutreffenden Ebenen zusammenführen (mergeConfigs),
        //    spezifischere Ebene überschreibt allgemeinere
        //  - Erweiterungsfeld (zusätzliche Properties) einmischen,
        //    Formular-Properties haben Vorrang
        //  - mit buildJsonLdScript() einen <script>-Block erzeugen
        //    und an $output anhängen
        //  - Types die nur global sinnvoll sind (ui:scopes = ["global"])
        //    nur einmal ausgeben, auch wenn mehrfach konfiguriert

        // Kollisionserkennung: vorhandenes JSON-LD im gerenderten HTML
        // erkennen und das Ergebnis je Geltungsebene in der jeweiligen
        // conf-Datei persistieren (siehe loadScopeMeta/saveScopeMeta).
        // TODO: $value enthält im aktuellen Aufrufkontext nur den
        //       Platzhalter-Inhalt. Für eine zuverlässige Erkennung sollte
        //       zusätzlich der Rohinhalt von Template und Seite (vor der
        //       Ausgabe dieses Plugins) geprüft werden.
        $hasExistingJsonLd = $this->detectExistingJsonLd((string) $value);

        foreach($scopeConfigs as $scope => $config) {
            $scopeArgs = match($scope) {
                'category' => [CAT_REQUEST],
                'page'     => [CAT_REQUEST, PAGE_REQUEST],
                default    => [],
            };

            $meta = $this->loadScopeMeta($scope, ...$scopeArgs);
            if($meta['existing_jsonld'] !== $hasExistingJsonLd) {
                $this->saveScopeMeta($scope, ['existing_jsonld' => $hasExistingJsonLd], ...$scopeArgs);
            }
        }

        return $output;
    }

    /***************************************************************
    *
    * Lädt die Konfiguration einer Geltungsebene.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param string|null $cat  Kategorie (CAT_REQUEST), für 'category' und 'page'
    * @param string|null $page Seite (PAGE_REQUEST), nur für 'page'
    * @return array array('TypeName' => array('property' => 'wert', ...), ...)
    *
    ***************************************************************/
    private function loadScopeConfig(string $scope, ?string $cat = null, ?string $page = null): array {
        $file = match($scope) {
            'global'   => $this->PLUGIN_SELF_DIR.'conf/_global.conf.php',
            'category' => $this->PLUGIN_SELF_DIR.'conf/cat_'.$cat.'.conf.php',
            'page'     => $this->PLUGIN_SELF_DIR.'conf/page_'.$cat.'_'.$page.'.conf.php',
            default    => null,
        };

        if($file === null or !file_exists($file)) {
            return [];
        }

        $config = new Properties($file);
        return $config->toArray();
    }

    /***************************************************************
    *
    * Führt die Properties mehrerer Geltungsebenen pro Schema-Type
    * zusammen. Spätere Arrays überschreiben gleichnamige Properties
    * früherer Arrays (Global -> Kategorie -> Seite).
    *
    * @param array ...$configs jeweils array('TypeName' => array(...), ...)
    * @return array array('TypeName' => array(...), ...)
    *
    ***************************************************************/
    private function mergeConfigs(array ...$configs): array {
        $result = [];
        foreach($configs as $config) {
            foreach($config as $type => $properties) {
                $result[$type] = array_merge($result[$type] ?? [], $properties);
            }
        }
        return $result;
    }

    /***************************************************************
    *
    * Lädt ein JSON-Schema aus schemas/{type}.json.
    *
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @return array|null dekodiertes Schema oder null bei Fehler
    *
    ***************************************************************/
    private function loadSchema(string $type): ?array {
        $file = $this->PLUGIN_SELF_DIR.'schemas/'.basename($type).'.json';
        if(!file_exists($file)) {
            return null;
        }
        $schema = json_decode(file_get_contents($file), true);
        return is_array($schema) ? $schema : null;
    }

    /***************************************************************
    *
    * Liefert alle verfügbaren Schema-Types anhand der .json-Dateien
    * im Verzeichnis schemas/.
    *
    * @return array Liste der Type-Namen (ohne .json), alphabetisch
    *
    ***************************************************************/
    private function getAvailableSchemaTypes(): array {
        $types = [];
        foreach(glob($this->PLUGIN_SELF_DIR.'schemas/*.json') as $file) {
            $types[] = basename($file, '.json');
        }
        sort($types);
        return $types;
    }

    /***************************************************************
    *
    * Erzeugt aus den zusammengeführten Properties einen
    * <script type="application/ld+json">-Block.
    *
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @param array $data  Properties (Formular + Erweiterungsfeld zusammengeführt)
    * @return string fertiger <script>-Block inkl. Zeilenumbruch
    *
    ***************************************************************/
    private function buildJsonLdScript(string $type, array $data): string {
        $jsonLd = array_merge(
            ['@context' => 'https://schema.org', '@type' => $type],
            $data
        );
        $json = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return '<script type="application/ld+json">'."\n".$json."\n".'</script>'."\n";
    }

    /***************************************************************
    *
    * Bildet einen CMS-/Admin-Sprachcode (z. B. "deDE", "enEN")
    * auf eine der vom Plugin unterstützten Sprachen ab.
    * Fallback ist DEFAULT_LANGUAGE.
    *
    ***************************************************************/
    private function resolvePluginLanguage(?string $code): string {
        $code = strtolower((string) $code);
        foreach(self::SUPPORTED_LANGUAGES as $supported) {
            if(str_starts_with($code, $supported)) {
                return $supported;
            }
        }
        return self::DEFAULT_LANGUAGE;
    }

    /***************************************************************
    *
    * Prüft, ob im gerenderten HTML der Seite bereits ein
    * <script type="application/ld+json">-Block vorhanden ist.
    *
    * Hinweis: Wendet man diese Methode auf die vom Plugin selbst
    * erzeugte Ausgabe an, erkennt sie auch dessen eigene
    * <script>-Blöcke. Für eine zuverlässige Kollisionserkennung sollte
    * die Prüfung daher auf den Rohinhalt von Template/Seiteninhalt vor
    * der Ausgabe dieses Plugins erfolgen.
    *
    * @param string $html zu prüfendes HTML
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    private function detectExistingJsonLd(string $html): bool {
        return (bool) preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>#i', $html);
    }

    /***************************************************************
    *
    * Ermittelt den Dateipfad der conf-Datei einer Geltungsebene
    * (siehe auch loadScopeConfig).
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return string|null Dateipfad oder null bei unbekanntem $scope
    *
    ***************************************************************/
    private function getScopeConfFile(string $scope, ?string $cat = null, ?string $page = null): ?string {
        return match($scope) {
            'global'   => $this->PLUGIN_SELF_DIR.'conf/_global.conf.php',
            'category' => $this->PLUGIN_SELF_DIR.'conf/cat_'.$cat.'.conf.php',
            'page'     => $this->PLUGIN_SELF_DIR.'conf/page_'.$cat.'_'.$page.'.conf.php',
            default    => null,
        };
    }

    /***************************************************************
    *
    * Lädt die Kollisions-Metadaten einer Geltungsebene
    * (existing_jsonld-Flag und gewählter jsonld_mode).
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{existing_jsonld: bool, jsonld_mode: string}
    *
    ***************************************************************/
    private function loadScopeMeta(string $scope, ?string $cat = null, ?string $page = null): array {
        $defaults = ['existing_jsonld' => false, 'jsonld_mode' => 'keep'];

        $file = $this->getScopeConfFile($scope, $cat, $page);
        if($file === null or !file_exists($file)) {
            return $defaults;
        }

        $config = new Properties($file);
        $data = $config->toArray();

        return array_merge($defaults, $data['_meta'] ?? []);
    }

    /***************************************************************
    *
    * Speichert die Kollisions-Metadaten einer Geltungsebene
    * (existing_jsonld-Flag, jsonld_mode), ohne die bereits
    * konfigurierten Schema-Type-Properties zu verändern.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @param array $meta z. B. ['existing_jsonld' => true, 'jsonld_mode' => 'override']
    *
    ***************************************************************/
    private function saveScopeMeta(string $scope, array $meta, ?string $cat = null, ?string $page = null): void {
        $file = $this->getScopeConfFile($scope, $cat, $page);
        if($file === null) {
            return;
        }

        $config = file_exists($file) ? (new Properties($file))->toArray() : [];
        $config['_meta'] = array_merge(
            $config['_meta'] ?? ['existing_jsonld' => false, 'jsonld_mode' => 'keep'],
            $meta
        );

        file_put_contents($file, '<?php die(); ?>'."\n".serialize($config));
    }

    /***************************************************************
    *
    * Importiert einen vorhandenen JSON-LD-Block.
    *
    * Zerlegt die Properties anhand des aktiven Schemas in bekannte
    * Formularfelder und unbekannte Properties (Erweiterungsfeld).
    * Es erfolgt kein Merge mit der aktuellen Konfiguration - der
    * Aufrufer (Admin-Formular) ersetzt die Konfiguration vollständig
    * mit dem Ergebnis dieser Methode.
    *
    * @param string $jsonLdText Inhalt des Import-Textarea-Felds
    * @param array|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @return array{
    *   success: bool,
    *   error: string|null,
    *   type: string|null,
    *   formData: array,
    *   extensionData: array
    * }
    *
    ***************************************************************/
    private function importJsonLd(string $jsonLdText, ?array $schema): array {
        $data = json_decode($jsonLdText, true);

        if(json_last_error() !== JSON_ERROR_NONE or !is_array($data)) {
            return [
                'success' => false,
                'error' => json_last_error_msg(),
                'type' => null,
                'formData' => [],
                'extensionData' => [],
            ];
        }

        $type = $data['@type'] ?? null;
        unset($data['@context'], $data['@type']);

        $knownProperties = $schema['properties'] ?? [];
        $formData = [];
        $extensionData = [];

        foreach($data as $property => $value) {
            if(array_key_exists($property, $knownProperties)) {
                $formData[$property] = $value;
            } else {
                $extensionData[$property] = $value;
            }
        }

        return [
            'success' => true,
            'error' => null,
            'type' => $type,
            'formData' => $formData,
            'extensionData' => $extensionData,
        ];
    }

    /***************************************************************
    *
    * Lädt (sofern noch nicht geschehen) das Sprachobjekt für die
    * Admin-UI.
    *
    ***************************************************************/
    private function loadAdminLanguage(): Language {
        if($this->admin_lang === null) {
            global $ADMIN_CONF;
            $lang = $this->resolvePluginLanguage($ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE);
            $this->admin_lang = new Language($this->PLUGIN_SELF_DIR.'sprachen/admin_language_'.$lang.'.txt');
        }
        return $this->admin_lang;
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
    * @return string HTML-Snippet (Hinweis, Radio-Buttons, Import-Textarea)
    *                 oder '' wenn kein vorhandenes JSON-LD erkannt wurde
    *
    ***************************************************************/
    private function renderExistingJsonLdNotice(string $scope, ?string $cat = null, ?string $page = null): string {
        $meta = $this->loadScopeMeta($scope, $cat, $page);

        if(!$meta['existing_jsonld']) {
            return '';
        }

        $lang = $this->loadAdminLanguage();
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
        $html .= '<textarea id="schemaOrgData_import_'.$scope.'" name="schemaOrgData_import_'.$scope.'" rows="6"></textarea></p>'."\n";
        $html .= '<p class="schemaOrgData-jsonld-notice__hint">'.$lang->getLanguageHtml('description_import_jsonld').'</p>'."\n";
        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Gibt die Konfigurationsoptionen für die moziloCMS-Plugin-
    * Verwaltung zurück.
    *
    * Das eigentliche, schema-getriebene Konfigurationsformular
    * (Geltungsbereich Global / Kategorie / Seite, dynamisch aus
    * schemas/*.json gerendert) wird gesondert implementiert.
    * An dieser Stelle daher zunächst ein leeres Array.
    *
    ***************************************************************/
    function getConfig(): array {
        return [];
    }

    /***************************************************************
    *
    * Gibt die Plugin-Infos zurück:
    *   - Name und Version des Plugins
    *   - kompatible moziloCMS-Version
    *   - Kurzbeschreibung
    *   - Name des Autors
    *   - Download-URL
    *   - Platzhalter für die Selectbox im Editor
    *
    ***************************************************************/
    function getInfo(): array {
        global $ADMIN_CONF;

        $lang = $this->resolvePluginLanguage($ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE);
        $this->admin_lang = new Language($this->PLUGIN_SELF_DIR.'sprachen/admin_language_'.$lang.'.txt');

        return [
            // Plugin-Name + Version
            'schemaOrgData 1.0.0',
            // kompatible moziloCMS-Version
            '3.0.4',
            // Kurzbeschreibung, nur <span> und <br /> sind erlaubt
            $this->admin_lang->getLanguageValue('plugin_description'),
            // Name des Autors
            'Bernhard Unger',
            // Download-URL
            '',
            // Platzhalter für die Selectbox in der Editieransicht
            ['{schemaOrgData}' => $this->admin_lang->getLanguageValue('plugin_placeholder')],
        ];
    }
}
