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
* Die JSON-Schema-Dateien definieren die Struktur der Formularfelder
* (über "ui:"-Properties) sowie die AJV-client-seitige Validierung.
* Die server-seitige Feldvalidierung (E-Mail, Telefon, URL, PLZ usw.)
* ist in dedizierten Validator-Methoden implementiert.
*
***************************************************************/
class schemaOrgData extends Plugin {

    /** Plugin-Version, siehe getInfo() */
    private const PLUGIN_VERSION = '1.0.0-beta';

    /** Standard-Sprache, falls die CMS-/Admin-Sprache nicht unterstützt wird */
    private const DEFAULT_LANGUAGE = 'de';

    /** Vom Plugin unterstützte Sprachen (sprachen/*_{code}.txt) */
    private const SUPPORTED_LANGUAGES = ['de', 'en'];

    /** Sprachobjekt für Admin-UI (sprachen/admin_language_{lang}.txt) */
    private ?Language $admin_lang = null;

    /** Sprachobjekt für Frontend/CMS-Kontext (sprachen/cms_language_{lang}.txt) */
    private ?Language $cms_lang = null;

    /** Sprachobjekt für Wochentag-Labels (sprachen/cms_language_{lang}.txt, Admin-Sprache) */
    private ?Language $weekday_lang = null;

    /** Aktuell aufgelöste Admin-Sprache (de|en), siehe loadAdminLanguage() */
    private string $pluginLang = self::DEFAULT_LANGUAGE;

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

        // Ausschlussliste prüfen (nur global): die globale Ausgabe wird
        // unterdrückt, wenn die aktive Kategorie in excluded_cats steht
        // (siehe README.md, Abschnitt "Ausschlussliste").
        $excludedCats = !empty($scopeConfigs['global']['excluded_cats'])
            ? explode(',', (string) $scopeConfigs['global']['excluded_cats'])
            : [];

        if(defined('CAT_REQUEST') and CAT_REQUEST and in_array(CAT_REQUEST, $excludedCats, true)) {
            unset($scopeConfigs['global']);
        }

        // Verwaltungsdaten (_meta, excluded_cats) entfernen - übrig bleiben
        // je Ebene nur noch die Schema-Type-Konfigurationen.
        foreach($scopeConfigs as $scope => $config) {
            unset($scopeConfigs[$scope]['_meta'], $scopeConfigs[$scope]['excluded_cats']);
        }

        // jsonld_mode prüfen: wurde bereits vorhandenes JSON-LD erkannt
        // und für diese Ebene "Vorhandenes beibehalten" gewählt (Standard,
        // solange der Admin keine Wahl getroffen hat), wird die eigene
        // Ausgabe komplett unterdrückt (siehe loadScopeMeta/
        // renderExistingJsonLdNotice sowie README.md, Abschnitt
        // "Verhalten bei vorhandenem JSON-LD").
        foreach($scopeConfigs as $scope => $config) {
            $scopeArgs = match($scope) {
                'category' => [CAT_REQUEST],
                'page'     => [CAT_REQUEST, PAGE_REQUEST],
                default    => [],
            };

            $meta = $this->loadScopeMeta($scope, ...$scopeArgs);
            if($meta['existing_jsonld'] and ($meta['jsonld_mode'] ?? 'override') === 'keep') {
                unset($scopeConfigs[$scope]);
            }
        }

        // Type-Kollision auflösen: ist derselbe Schema-Type auf mehreren
        // Ebenen konfiguriert, gibt nur die spezifischere Ebene aus
        // (Priorität Seite > Kategorie > Global).
        $usedTypes = [];
        foreach(['page', 'category', 'global'] as $scope) {
            if(!isset($scopeConfigs[$scope])) {
                continue;
            }
            foreach($scopeConfigs[$scope] as $type => $data) {
                if(in_array($type, $usedTypes, true)) {
                    unset($scopeConfigs[$scope][$type]);
                } else {
                    $usedTypes[] = $type;
                }
            }
        }

        // JSON-LD-Blöcke der verbleibenden Types ausgeben
        foreach($scopeConfigs as $scope => $config) {
            foreach($config as $type => $data) {
                $output .= $this->buildJsonLdScript($type, $data);
            }
        }

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
        // Werte wurden beim Speichern mit htmlspecialchars() gesichert -
        // vor dem JSON-Encode wieder in Klartext umwandeln.
        $data = $this->decodeJsonLdValues($data);

        // Leere Properties (null, '', []) entfernen, auch verschachtelt
        // (z. B. unvollständige PostalAddress/GeoCoordinates-Angaben).
        $data = $this->removeEmptyJsonLdProperties($data);

        // Adresse, Geokoordinaten und Mitarbeiter als verschachtelte,
        // typisierte schema.org-Objekte ausgeben.
        foreach(['address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'employee' => 'Person'] as $property => $nestedType) {
            if(isset($data[$property]) and is_array($data[$property])) {
                $data[$property] = array_merge(['@type' => $nestedType], $data[$property]);
            }
        }

        $jsonLd = array_merge(
            ['@context' => 'https://schema.org', '@type' => $type],
            $data
        );

        $json = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if($json === false) {
            return '';
        }

        return '<script type="application/ld+json">'."\n".$json."\n".'</script>'."\n";
    }

    /***************************************************************
    *
    * Wandelt HTML-Entities in allen String-Werten eines (verschachtelten)
    * Arrays zurück in Klartext (Gegenstück zu htmlspecialchars(), siehe
    * sanitizePostData()), bevor das Array als JSON-LD ausgegeben wird.
    *
    * @param array $data Properties eines Schema-Types
    * @return array Properties mit dekodierten String-Werten
    *
    ***************************************************************/
    private function decodeJsonLdValues(array $data): array {
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $data[$key] = $this->decodeJsonLdValues($value);
            } elseif(is_string($value)) {
                $data[$key] = htmlspecialchars_decode($value, ENT_QUOTES);
            }
        }
        return $data;
    }

    /***************************************************************
    *
    * Entfernt rekursiv Properties mit leerem Wert (null, '' oder [])
    * aus einem (verschachtelten) Array, damit sie nicht im JSON-LD
    * ausgegeben werden.
    *
    * @param array $data Properties eines Schema-Types
    * @return array Properties ohne leere Werte
    *
    ***************************************************************/
    private function removeEmptyJsonLdProperties(array $data): array {
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $value = $this->removeEmptyJsonLdProperties($value);
            }
            if($value === null or $value === '' or $value === []) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
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
        // Bestehend: Prüfung im Content-Bereich (unveränderter Regex-Block)
        if((bool) preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>#i', $html)) {
            return true;
        }
        // Neu: Prüfung im aktiven Website-Template
        return $this->detectExistingJsonLdInTemplate();
    }

    /***************************************************************
    *
    * Prüft, ob das aktiv geladene Website-Template einen
    * <script type="application/ld+json">-Block enthält.
    *
    * Liest die Template-Datei direkt vom Dateisystem. Die globale
    * Variable $TEMPLATE_FILE zeigt zu getContent()-Zeit bereits auf
    * die korrekte Datei (template.html oder gallerytemplate.html).
    *
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    private function detectExistingJsonLdInTemplate(): bool {
        global $TEMPLATE_FILE;
        if(empty($TEMPLATE_FILE) or !file_exists($TEMPLATE_FILE)) {
            return false;
        }
        $content = file_get_contents($TEMPLATE_FILE);
        return $content !== false
            && (bool) preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>#i', $content);
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
    * Entfernt aus einem Bezeichner (CAT_REQUEST/PAGE_REQUEST) alle
    * Zeichen, die in conf-Dateinamen nicht erlaubt sind, bevor er
    * in getScopeConfFile() verwendet wird (Schutz vor Path-Traversal,
    * siehe README.md, Abschnitt "Sicherheit").
    *
    * @return string bereinigter Bezeichner
    *
    ***************************************************************/
    private function sanitizeScopeIdentifier(string $value): string {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value);
    }

    /***************************************************************
    *
    * Liefert die (sanitierten) CAT_REQUEST/PAGE_REQUEST-Werte einer
    * Geltungsebene, passend für getScopeConfFile().
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{0: string|null, 1: string|null} [cat, page]
    *
    ***************************************************************/
    private function resolveScopeIdentifiers(string $scope): array {
        $cat = (defined('CAT_REQUEST') and CAT_REQUEST) ? $this->sanitizeScopeIdentifier((string) CAT_REQUEST) : null;
        $page = (defined('PAGE_REQUEST') and PAGE_REQUEST) ? $this->sanitizeScopeIdentifier((string) PAGE_REQUEST) : null;

        // Fallback auf Plugin-eigene GET-Parameter im Admin-Kontext
        // (CAT_REQUEST/PAGE_REQUEST sind im moziloCMS-Admin nicht gesetzt)
        if ($cat === null && isset($_GET['schemaOrgData_cat'])) {
            $cat = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']);
            if ($cat === '') $cat = null;
        }
        if ($page === null && isset($_GET['schemaOrgData_page'])) {
            $page = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_page']);
            if ($page === '') $page = null;
        }

        return match($scope) {
            'category' => [$cat, null],
            'page'     => [$cat, $page],
            default    => [null, null],
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

        // Conf-Verzeichnis anlegen, falls noch nicht vorhanden
        $confDir = dirname($file);
        if (!is_dir($confDir)) {
            mkdir($confDir, 0755, true);
        }

        // Schreibfehler protokollieren — saveScopeMeta hat kein Rückgabe-Array,
        // daher error_log als stilles Fallback
        if (file_put_contents($file, '<?php die(); ?>'."\n".serialize($config)) === false) {
            error_log('schemaOrgData: Konnte Metadaten nicht schreiben: ' . $file);
        }
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
    * Validiert Formulardaten serverseitig gegen ein JSON-Schema.
    *
    * Prüft, ob alle in "required" gelisteten Properties vorhanden
    * und nicht leer sind, sowie ob alle Properties in "properties"
    * bekannt sind. Dient als serverseitige Ergänzung zur
    * clientseitigen AJV-Validierung; unbekannte Properties führen
    * nur zu einer Warnung, kein Speichern wird dadurch verhindert.
    *
    * @param array $data   zu prüfende Properties (Formularfeld-Werte)
    * @param array|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @return array{errors: string[], warnings: string[]}
    *
    ***************************************************************/
    private function validateAgainstSchema(array $data, ?array $schema): array {
        $errors = [];
        $warnings = [];

        if($schema === null) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        foreach($schema['required'] ?? [] as $requiredProperty) {
            $value = $data[$requiredProperty] ?? null;
            if($value === null or $value === '' or $value === []) {
                $errors[] = $requiredProperty;
            }
        }

        $knownProperties = array_keys($schema['properties'] ?? []);
        foreach(array_keys($data) as $property) {
            if(!in_array($property, $knownProperties, true)) {
                $warnings[] = $property;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /***************************************************************
    *
    * Lädt (sofern noch nicht geschehen) das Sprachobjekt für die
    * Admin-UI.
    *
    ***************************************************************/
    private function loadAdminLanguage(): Language {
        global $ADMIN_CONF;
        $this->pluginLang = $this->resolvePluginLanguage($ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE);

        if($this->admin_lang === null) {
            $this->admin_lang = new Language($this->PLUGIN_SELF_DIR.'sprachen/admin_language_'.$this->pluginLang.'.txt');
        }
        return $this->admin_lang;
    }

    /***************************************************************
    *
    * Lädt (sofern noch nicht geschehen) das Sprachobjekt für die
    * Wochentag-Labels (weekday_monday .. weekday_sunday) im
    * Öffnungszeiten-Widget. Verwendet die aufgelöste Admin-Sprache,
    * da diese Labels innerhalb des Admin-Formulars ausgegeben werden.
    *
    ***************************************************************/
    private function loadWeekdayLanguage(): Language {
        $this->loadAdminLanguage();

        if($this->weekday_lang === null) {
            $this->weekday_lang = new Language($this->PLUGIN_SELF_DIR.'sprachen/cms_language_'.$this->pluginLang.'.txt');
        }
        return $this->weekday_lang;
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
    * Löst ein "$ref": "#/definitions/..." innerhalb eines Feld-
    * Schemas auf und führt die referenzierten Properties mit den
    * lokalen (überschreibenden) Properties zusammen.
    *
    * @param array $fieldSchema Schema des Feldes, ggf. mit "$ref"
    * @param array $rootSchema  vollständiges Schema (für "definitions")
    * @return array aufgelöstes Feld-Schema
    *
    ***************************************************************/
    private function resolveSchemaRef(array $fieldSchema, array $rootSchema): array {
        if(!isset($fieldSchema['$ref']) or !is_string($fieldSchema['$ref'])) {
            return $fieldSchema;
        }

        $ref = $fieldSchema['$ref'];
        if(!str_starts_with($ref, '#/')) {
            return $fieldSchema;
        }

        $resolved = $rootSchema;
        foreach(explode('/', substr($ref, 2)) as $segment) {
            if(!is_array($resolved) or !array_key_exists($segment, $resolved)) {
                return $fieldSchema;
            }
            $resolved = $resolved[$segment];
        }

        unset($fieldSchema['$ref']);
        return is_array($resolved) ? array_merge($resolved, $fieldSchema) : $fieldSchema;
    }

    /***************************************************************
    *
    * Trennt gespeicherte Properties eines Schema-Types in
    * Formularfelder (im Schema definiert) und Erweiterungs-
    * Properties (nicht im Schema definiert) auf - Umkehrung des
    * beim Speichern durchgeführten Merge.
    *
    * @param array $data   gespeicherte Properties eines Schema-Types
    * @param array|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @return array{form: array, extension: array}
    *
    ***************************************************************/
    private function splitDataForRendering(array $data, ?array $schema): array {
        $knownProperties = array_keys($schema['properties'] ?? []);
        $form = [];
        $extension = [];

        foreach($data as $property => $value) {
            if(in_array($property, $knownProperties, true)) {
                $form[$property] = $value;
            } else {
                $extension[$property] = $value;
            }
        }

        return ['form' => $form, 'extension' => $extension];
    }

    /***************************************************************
    *
    * Zerlegt ein openingHours-Array (schema.org-Notation, z. B.
    * "Mo-Fr 09:00-18:00") in Von/Bis-Zeiten je Wochentag.
    *
    * @param array $openingHours z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @return array<string,array{from:string,to:string}> je Tag "from"/"to" (leer = geschlossen)
    *
    ***************************************************************/
    private function parseOpeningHours(array $openingHours, array $days): array {
        $result = [];
        foreach($days as $day) {
            $result[$day] = ['from' => '', 'to' => ''];
        }

        foreach($openingHours as $entry) {
            if(!is_string($entry)) {
                continue;
            }

            if(!preg_match('/^([A-Za-z]{2})(?:-([A-Za-z]{2}))? ([0-9]{2}:[0-9]{2})-([0-9]{2}:[0-9]{2})$/', trim($entry), $matches)) {
                continue;
            }

            [, $startDay, $endDay, $from, $to] = $matches;
            $endDay = $endDay !== '' ? $endDay : $startDay;

            $startIndex = array_search($startDay, $days, true);
            $endIndex = array_search($endDay, $days, true);
            if($startIndex === false or $endIndex === false) {
                continue;
            }

            for($i = $startIndex; $i <= $endIndex; $i++) {
                $result[$days[$i]] = ['from' => $from, 'to' => $to];
            }
        }

        return $result;
    }

    /***************************************************************
    *
    * Baut aus den Von/Bis-Zeiten je Wochentag ein openingHours-Array
    * in schema.org-Notation. Aufeinanderfolgende Tage mit identischen
    * Zeiten werden zu einem Bereich (z. B. "Mo-Fr 09:00-18:00")
    * zusammengefasst. Tage ohne Zeiten ("geschlossen") werden
    * ausgelassen.
    *
    * @param array<string,array{from:string,to:string}> $perDay je Tag "from"/"to"
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @return string[] z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    *
    ***************************************************************/
    private function buildOpeningHoursArray(array $perDay, array $days): array {
        $result = [];
        $rangeStart = null;
        $rangeEnd = null;
        $rangeFrom = '';
        $rangeTo = '';

        $flush = function () use (&$result, &$rangeStart, &$rangeEnd, &$rangeFrom, &$rangeTo) {
            if($rangeStart === null) {
                return;
            }
            $dayPart = ($rangeStart === $rangeEnd) ? $rangeStart : $rangeStart.'-'.$rangeEnd;
            $result[] = $dayPart.' '.$rangeFrom.'-'.$rangeTo;
            $rangeStart = null;
            $rangeEnd = null;
        };

        foreach($days as $day) {
            $from = trim((string) ($perDay[$day]['from'] ?? ''));
            $to = trim((string) ($perDay[$day]['to'] ?? ''));

            if($from === '' or $to === '') {
                $flush();
                continue;
            }

            if($rangeStart !== null and $from === $rangeFrom and $to === $rangeTo) {
                $rangeEnd = $day;
                continue;
            }

            $flush();
            $rangeStart = $day;
            $rangeEnd = $day;
            $rangeFrom = $from;
            $rangeTo = $to;
        }
        $flush();

        return $result;
    }

    /***************************************************************
    *
    * Validiert eine Postleitzahl. Nur für addressCountry = "DE"
    * relevant (siehe README.md, Abschnitt "Formularvalidierung").
    *
    * @return array{status: string|null, message: string|null}
    *   status: null (nicht geprüft), 'ok', 'error'
    *
    ***************************************************************/
    private function validatePostalCode(string $value, string $countryCode): array {
        if($countryCode !== 'DE' or trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(preg_match('/^[0-9]{5}$/', $value)) {
            return ['status' => 'ok', 'message' => null];
        }

        return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_postal_code_format')];
    }

    /***************************************************************
    *
    * Validiert eine Telefonnummer (E.164, alle Länder). Die
    * Eingabe wird zunächst normalisiert
    * (preg_replace('/[^0-9+]/', '', $input)) und dann gegen ein
    * vereinfachtes E.164-Format geprüft.
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateTelephone(string $value, string $countryCode): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        $normalized = preg_replace('/[^0-9+]/', '', $value);

        if(preg_match('/^(\+|00)[1-9][0-9]{6,14}$/', $normalized)) {
            return ['status' => 'ok', 'message' => null];
        }

        return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_telephone_format')];
    }

    /***************************************************************
    *
    * Validiert eine URL. "http://" ergibt eine Warnung (HTTPS
    * empfohlen), "https://" ist OK, eine ungültige URL ist ein
    * Fehler.
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateUrl(string $value): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_url_invalid')];
        }

        if(str_starts_with($value, 'http://')) {
            return ['status' => 'warning', 'message' => $this->loadAdminLanguage()->getLanguageValue('warning_url_http')];
        }

        return ['status' => 'ok', 'message' => null];
    }

    /***************************************************************
    *
    * Validiert eine E-Mail-Adresse via FILTER_VALIDATE_EMAIL.
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateEmail(string $value): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return ['status' => 'ok', 'message' => null];
        }

        return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_email_invalid')];
    }

    /***************************************************************
    *
    * Validiert ein Von/Bis-Zeitpaar des Öffnungszeiten-Widgets.
    * Ausschließlich 24-Stunden-Format (HH:MM), Von muss vor Bis
    * liegen. Sind beide Felder leer, gilt der Tag als
    * "geschlossen" und wird nicht geprüft.
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateOpeningHoursTime(string $from, string $to): array {
        $from = trim($from);
        $to = trim($to);

        if($from === '' and $to === '') {
            return ['status' => null, 'message' => null];
        }

        if(!preg_match('/^[0-9]{2}:[0-9]{2}$/', $from) or !preg_match('/^[0-9]{2}:[0-9]{2}$/', $to)) {
            return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_opening_hours_format')];
        }

        if($from >= $to) {
            return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_opening_hours_order')];
        }

        return ['status' => 'ok', 'message' => null];
    }

    /***************************************************************
    *
    * Validiert eine Geo-Koordinate (latitude/longitude) im
    * Erweiterungsfeld: muss numerisch sein und im gültigen
    * Wertebereich liegen.
    *
    * @param string $value zu prüfender Wert
    * @param float $min    unterer Grenzwert (inklusive)
    * @param float $max    oberer Grenzwert (inklusive)
    * @param string $errorKey Sprachschlüssel für die Fehlermeldung
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateGeoCoordinate(string $value, float $min, float $max, string $errorKey): array {
        if(trim($value) === '') {
            return ['status' => null, 'message' => null];
        }

        if(!is_numeric($value) or (float) $value < $min or (float) $value > $max) {
            return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue($errorKey)];
        }

        return ['status' => 'ok', 'message' => null];
    }

    /** Validiert geo.latitude (-90 .. 90), siehe validateGeoCoordinate(). */
    private function validateGeoLatitude(string $value): array {
        return $this->validateGeoCoordinate($value, -90, 90, 'error_geo_latitude');
    }

    /** Validiert geo.longitude (-180 .. 180), siehe validateGeoCoordinate(). */
    private function validateGeoLongitude(string $value): array {
        return $this->validateGeoCoordinate($value, -180, 180, 'error_geo_longitude');
    }

    /***************************************************************
    *
    * Validiert geo.latitude/geo.longitude im Erweiterungsfeld
    * (siehe validateGeoLatitude/validateGeoLongitude). Andere
    * Properties des Erweiterungsfelds werden serverseitig nicht
    * geprüft (siehe README.md, Abschnitt "Erweiterungsfeld").
    *
    * @param array $extensionData dekodierte Erweiterungsfeld-Daten
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    private function validateExtensionGeo(array $extensionData): array {
        $errors = [];
        $geo = $extensionData['geo'] ?? null;

        if(!is_array($geo)) {
            return $errors;
        }

        if(isset($geo['latitude'])) {
            $result = $this->validateGeoLatitude((string) $geo['latitude']);
            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        if(isset($geo['longitude'])) {
            $result = $this->validateGeoLongitude((string) $geo['longitude']);
            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Rendert das Feedback-Symbol (✅/⚠️/❌) zu einem
    * Validierungsergebnis (siehe validate*-Methoden).
    *
    * @param array{status: string|null, message: string|null} $result
    * @return string HTML-Snippet oder '' wenn $result['status'] === null
    *
    ***************************************************************/
    private function renderValidationFeedback(array $result): string {
        $icons = ['ok' => '&#9989;', 'warning' => '&#9888;&#65039;', 'error' => '&#10060;'];

        if($result['status'] === null or !isset($icons[$result['status']])) {
            return '';
        }

        $message = $result['message'] !== null
            ? ' '.htmlspecialchars($result['message'], ENT_QUOTES, CHARSET)
            : '';

        return '<span class="schemaOrgData-feedback schemaOrgData-feedback--'.$result['status'].'">'
            .$icons[$result['status']].$message.'</span>';
    }

    /***************************************************************
    *
    * Rendert die Pflichtfeld-/Optional-Kennzeichnung eines
    * Formularfeldes anhand von "ui:required".
    *
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderRequiredBadge(bool $required): string {
        $lang = $this->loadAdminLanguage();

        if($required) {
            return ' <span class="schemaOrgData-required" title="'
                .$lang->getLanguageHtml('label_required_field').'">*</span>';
        }

        return ' <span class="schemaOrgData-optional">'.$lang->getLanguageHtml('label_optional').'</span>';
    }

    /***************************************************************
    *
    * Rendert ein einfaches Textfeld (<input type="text">).
    *
    * @param string $id   HTML-id des Feldes
    * @param string $name HTML-name des Feldes
    * @param array $fieldSchema Feld-Schema (für ui:placeholder)
    * @param mixed $value aktueller Wert
    * @param array<string,string> $extraAttrs zusätzliche HTML-Attribute (z. B. data-validate)
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderTextWidget(string $id, string $name, array $fieldSchema, mixed $value, array $extraAttrs = []): string {
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
    private function renderTextareaWidget(string $id, string $name, array $fieldSchema, mixed $value): string {
        $valueText = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, CHARSET);
        $placeholder = htmlspecialchars((string) ($fieldSchema['ui:placeholder'] ?? ''), ENT_QUOTES, CHARSET);

        return '<textarea id="'.$id.'" name="'.$name.'" class="mo-input-text" rows="4" placeholder="'.$placeholder.'">'
            .$valueText.'</textarea>';
    }

    /***************************************************************
    *
    * Rendert eine Dropdown-Auswahl (<select>). Optionen entweder aus
    * "ui:options" (flache Liste, z. B. priceRange) oder aus "enum" +
    * "ui:enumLabels" (z. B. addressCountry, sprachabhängig).
    *
    * Felder ohne "default" und ohne "ui:required" erhalten zusätzlich
    * eine leere Option ("– bitte wählen –").
    *
    ***************************************************************/
    private function renderSelectWidget(string $id, string $name, array $fieldSchema, mixed $value): string {
        $lang = $this->loadAdminLanguage();
        $options = [];

        if(isset($fieldSchema['ui:options']) and is_array($fieldSchema['ui:options'])) {
            foreach($fieldSchema['ui:options'] as $option) {
                $options[(string) $option] = (string) $option;
            }
        } elseif(isset($fieldSchema['enum']) and is_array($fieldSchema['enum'])) {
            $enumLabels = $fieldSchema['ui:enumLabels'][$this->pluginLang] ?? [];
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
    * Rendert das PostalAddress-Widget (streetAddress, postalCode,
    * addressLocality, addressRegion, addressCountry). addressCountry
    * wird als Select mit ISO-3166-Codes gerendert (siehe
    * "definitions.PostalAddress" in den Schema-Dateien).
    *
    * @param string $scope Geltungsbereich ('global'|'category'|'page')
    * @param string $name  Property-Name (üblicherweise "address")
    * @param array $fieldSchema bereits via resolveSchemaRef() aufgelöstes Schema
    * @param array $value gespeicherte Adress-Properties
    *
    ***************************************************************/
    private function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value): string {
        $lang = $this->loadAdminLanguage();
        $countryFieldId = 'schemaOrgData_'.$scope.'_'.$name.'_addressCountry';
        $html = '';

        foreach($fieldSchema['properties'] ?? [] as $subName => $subSchema) {
            $fieldId = 'schemaOrgData_'.$scope.'_'.$name.'_'.$subName;
            $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$subName.']';
            $subValue = $value[$subName] ?? ($subSchema['default'] ?? null);
            $required = (bool) ($subSchema['ui:required'] ?? false);
            $label = $lang->getLanguageHtml($subSchema['ui:label'] ?? $subName);
            $badge = $this->renderRequiredBadge($required);

            if(($subSchema['ui:widget'] ?? 'text') === 'select') {
                $widgetHtml = $this->renderSelectWidget($fieldId, $fieldName, $subSchema, $subValue);
            } else {
                $extraAttrs = [];
                if($subName === 'postalCode') {
                    $extraAttrs = ['data-validate' => 'postal_code', 'data-country-field' => $countryFieldId];
                }
                $widgetHtml = $this->renderTextWidget($fieldId, $fieldName, $subSchema, $subValue, $extraAttrs);
            }

            $feedback = '';
            if($subName === 'postalCode' and $subValue !== null and $subValue !== '') {
                $countryCode = (string) ($value['addressCountry'] ?? 'DE');
                $feedback = $this->renderValidationFeedback($this->validatePostalCode((string) $subValue, $countryCode));
            }

            $html .= '<div class="c-content">'
                .'<div class="mo-in-li-l"><label for="'.$fieldId.'">'.$label.'</label>'.$badge.'</div>'
                .'<div class="mo-in-li-r">'.$widgetHtml.$feedback.'</div>'
                .'</div>'."\n";
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
    * @param array $fieldSchema Feld-Schema (ui:days, ui:dayLabelKeys)
    * @param array $value gespeichertes openingHours-Array
    *
    ***************************************************************/
    private function renderOpeningHoursWidget(string $scope, string $name, array $fieldSchema, array $value): string {
        $lang = $this->loadAdminLanguage();
        $weekdayLang = $this->loadWeekdayLanguage();
        $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $dayLabelKeys = $fieldSchema['ui:dayLabelKeys'] ?? [];
        $perDay = $this->parseOpeningHours($value, $days);

        $html = '<table class="schemaOrgData-opening-hours">'."\n";
        $html .= '<thead><tr><th></th><th>'.$lang->getLanguageHtml('label_opening_hours_from').'</th>'
            .'<th>'.$lang->getLanguageHtml('label_opening_hours_to').'</th></tr></thead>'."\n";
        $html .= '<tbody>'."\n";

        foreach($days as $day) {
            $dayLabel = isset($dayLabelKeys[$day]) ? $weekdayLang->getLanguageHtml($dayLabelKeys[$day]) : htmlspecialchars($day, ENT_QUOTES, CHARSET);
            $fromId = 'schemaOrgData_'.$scope.'_'.$name.'_'.$day.'_from';
            $toId = 'schemaOrgData_'.$scope.'_'.$name.'_'.$day.'_to';
            $fromName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][from]';
            $toName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$day.'][to]';
            $from = $perDay[$day]['from'] ?? '';
            $to = $perDay[$day]['to'] ?? '';

            $fromInput = $this->renderTextWidget($fromId, $fromName, ['ui:placeholder' => '09:00'], $from, [
                'data-validate' => 'opening_hours', 'data-pair' => $toId, 'maxlength' => '5',
            ]);
            $toInput = $this->renderTextWidget($toId, $toName, ['ui:placeholder' => '18:00'], $to, [
                'data-validate' => 'opening_hours', 'data-pair' => $fromId, 'maxlength' => '5',
            ]);

            $feedback = $this->renderValidationFeedback($this->validateOpeningHoursTime($from, $to));

            $html .= '<tr><td>'.$dayLabel.'</td><td>'.$fromInput.'</td><td>'.$toInput.$feedback.'</td></tr>'."\n";
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
    * @param array $fieldSchema Feld-Schema (items.properties)
    * @param array $value gespeichertes mainEntity-Array
    *
    ***************************************************************/
    private function renderFaqListWidget(string $scope, string $name, array $fieldSchema, array $value): string {
        $lang = $this->loadAdminLanguage();
        $itemSchema = $fieldSchema['items'] ?? [];
        $questionSchema = $itemSchema['properties']['name'] ?? [];
        $answerSchema = $itemSchema['properties']['acceptedAnswer']['properties']['text'] ?? [];

        // bestehende Einträge plus eine leere Zeile zum Anlegen eines neuen Eintrags
        $entries = array_values($value);
        $entries[] = ['name' => '', 'acceptedAnswer' => ['text' => '']];

        $html = '';
        foreach($entries as $index => $entry) {
            $questionId = 'schemaOrgData_'.$scope.'_'.$name.'_'.$index.'_name';
            $answerId = 'schemaOrgData_'.$scope.'_'.$name.'_'.$index.'_answer';
            $questionName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$index.'][name]';
            $answerName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$index.'][acceptedAnswer][text]';
            $question = $entry['name'] ?? '';
            $answer = $entry['acceptedAnswer']['text'] ?? '';

            $questionLabel = $lang->getLanguageHtml($questionSchema['ui:label'] ?? 'label_faq_question');
            $answerLabel = $lang->getLanguageHtml($answerSchema['ui:label'] ?? 'label_faq_answer');
            $badge = $this->renderRequiredBadge((bool) ($questionSchema['ui:required'] ?? false));

            $html .= '<div class="schemaOrgData-faq-entry">'."\n";
            $html .= '<div class="c-content">'
                .'<div class="mo-in-li-l"><label for="'.$questionId.'">'.$questionLabel.'</label>'.$badge.'</div>'
                .'<div class="mo-in-li-r">'.$this->renderTextWidget($questionId, $questionName, $questionSchema, $question).'</div>'
                .'</div>'."\n";
            $html .= '<div class="c-content">'
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
    *
    ***************************************************************/
    private function renderExtensionFieldWidget(string $scope, string $type, string $extensionJson): string {
        $lang = $this->loadAdminLanguage();
        $fieldId = 'schemaOrgData_'.$scope.'_'.$type.'_extension';
        $fieldName = 'schemaOrgData['.$scope.'][extension]['.$type.']';
        $schemaUrl = $this->PLUGIN_SELF_URL.'schemas/'.$type.'.json';

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
    * data-country-field für telephone).
    *
    * @return array<string,string>
    *
    ***************************************************************/
    private function buildValidationAttrs(string $scope, string $name, array $fieldSchema): array {
        $format = $fieldSchema['format'] ?? null;

        if($format === 'uri') {
            return ['data-validate' => 'url'];
        }

        if($format === 'email') {
            return ['data-validate' => 'email'];
        }

        if($name === 'telephone') {
            return [
                'data-validate' => 'telephone',
                'data-country-field' => 'schemaOrgData_'.$scope.'_address_addressCountry',
            ];
        }

        return [];
    }

    /***************************************************************
    *
    * Rendert das serverseitige Validierungs-Feedback für ein
    * einfaches Feld (Top-Level, außerhalb von postal_address/
    * opening_hours, die ihr Feedback selbst rendern).
    *
    * @param array $allData alle Formular-Properties des Schema-Types
    *                        (für telephone -> address.addressCountry)
    *
    ***************************************************************/
    private function renderFieldFeedback(string $name, array $fieldSchema, string $value, array $allData): string {
        $format = $fieldSchema['format'] ?? null;

        if($format === 'uri') {
            return $this->renderValidationFeedback($this->validateUrl($value));
        }

        if($format === 'email') {
            return $this->renderValidationFeedback($this->validateEmail($value));
        }

        if($name === 'telephone') {
            $countryCode = (string) ($allData['address']['addressCountry'] ?? 'DE');
            return $this->renderValidationFeedback($this->validateTelephone($value, $countryCode));
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
    * @param array $fieldSchema Schema des Feldes (ggf. mit "$ref")
    * @param mixed $value  aktueller Wert
    * @param array $rootSchema vollständiges Schema (für resolveSchemaRef)
    * @param array $allData alle Formular-Properties dieses Schema-Types
    *
    ***************************************************************/
    private function renderField(string $scope, string $name, array $fieldSchema, mixed $value, array $rootSchema, array $allData): string {
        $fieldSchema = $this->resolveSchemaRef($fieldSchema, $rootSchema);
        $widget = $fieldSchema['ui:widget'] ?? 'text';
        $lang = $this->loadAdminLanguage();
        $label = $lang->getLanguageHtml($fieldSchema['ui:label'] ?? $name);
        $required = (bool) ($fieldSchema['ui:required'] ?? false);
        $badge = $this->renderRequiredBadge($required);
        $fieldId = 'schemaOrgData_'.$scope.'_'.$name;

        if(in_array($widget, ['postal_address', 'opening_hours', 'faq_list'], true)) {
            $inner = match($widget) {
                'postal_address' => $this->renderPostalAddressWidget($scope, $name, $fieldSchema, is_array($value) ? $value : []),
                'opening_hours'  => $this->renderOpeningHoursWidget($scope, $name, $fieldSchema, is_array($value) ? $value : []),
                'faq_list'       => $this->renderFaqListWidget($scope, $name, $fieldSchema, is_array($value) ? $value : []),
                default          => '',
            };

            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']';

        $widgetHtml = match($widget) {
            'select'   => $this->renderSelectWidget($fieldId, $fieldName, $fieldSchema, $value),
            'textarea' => $this->renderTextareaWidget($fieldId, $fieldName, $fieldSchema, $value),
            default    => $this->renderTextWidget($fieldId, $fieldName, $fieldSchema, $value, $this->buildValidationAttrs($scope, $name, $fieldSchema)),
        };

        $feedback = ($value !== null and $value !== '' and is_scalar($value))
            ? $this->renderFieldFeedback($name, $fieldSchema, (string) $value, $allData)
            : '';

        return '<div class="c-content">'
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
    * @param array $schema vollständiges Schema (schemas/{Type}.json)
    * @param array $data   gespeicherte Properties dieses Types (Formular + Erweiterung gemischt)
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderTypeFields(string $scope, string $type, array $schema, array $data): string {
        $split = $this->splitDataForRendering($data, $schema);
        $formData = $split['form'];

        $extensionJson = $split['extension'] !== []
            ? json_encode($split['extension'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : '';

        $html = '';
        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $html .= $this->renderField($scope, $name, $fieldSchema, $formData[$name] ?? null, $schema, $formData);
        }

        $html .= $this->renderExtensionFieldWidget($scope, $type, $extensionJson);

        return $html;
    }

    /***************************************************************
    *
    * Rendert die Schema-Type-Auswahl (<select>) einer Geltungsebene.
    * Enthält zusätzlich die Option "– kein Schema –"
    * (schema_type_none).
    *
    * @param string $scope Geltungsbereich
    * @param array<string,array> $availableTypes Type => Schema, für diese Ebene zulässig (ui:scopes)
    * @param string|null $selectedType aktuell konfigurierter Type oder null
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderTypeSelector(string $scope, array $availableTypes, ?string $selectedType): string {
        $lang = $this->loadAdminLanguage();
        $fieldId = 'schemaOrgData_'.$scope.'_type';
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
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderInfoBlock(string $scope): string {
        $lang = $this->loadAdminLanguage();
        $key = match($scope) {
            'global'   => 'info_text_global',
            'category' => 'info_text_category',
            'page'     => 'info_text_page',
            default    => '',
        };

        if($key === '') {
            return '';
        }

        return '<div class="schemaOrgData-info">'
            .'<p>'.$lang->getLanguageHtml($key).'</p>'
            .'<p>'.$lang->getLanguageHtml('info_text_general').'</p>'
            .'</div>'."\n";
    }

    /***************************************************************
    *
    * Prüft, ob für $selectedType bereits auf einer allgemeineren
    * Ebene (Global bzw. Global+Kategorie) eine Konfiguration
    * existiert. Ist dies der Fall, unterdrückt die allgemeinere
    * Ebene ihre Ausgabe für diesen Type (siehe README.md,
    * Abschnitt "Type-Kollision").
    *
    * @param string $scope 'category' | 'page' (für 'global' immer [])
    * @return string[] Liste der kollidierenden, allgemeineren Ebenen
    *
    ***************************************************************/
    private function detectTypeCollision(string $scope, ?string $cat, ?string $page, string $selectedType): array {
        $higherScopes = match($scope) {
            'category' => ['global' => $this->loadScopeConfig('global')],
            'page'     => [
                'global'   => $this->loadScopeConfig('global'),
                'category' => $this->loadScopeConfig('category', $cat),
            ],
            default => [],
        };

        $collisions = [];
        foreach($higherScopes as $higherScope => $higherConfig) {
            if(array_key_exists($selectedType, $higherConfig)) {
                $collisions[] = $higherScope;
            }
        }

        return $collisions;
    }

    /***************************************************************
    *
    * Rendert den Hinweis auf eine Type-Kollision mit einer
    * allgemeineren Ebene (siehe detectTypeCollision()).
    *
    * @return string HTML-Snippet oder '' wenn keine Kollision vorliegt
    *
    ***************************************************************/
    private function renderCollisionNotice(string $scope, ?string $cat, ?string $page, string $selectedType): string {
        $collisions = $this->detectTypeCollision($scope, $cat, $page, $selectedType);

        if($collisions === []) {
            return '';
        }

        $lang = $this->loadAdminLanguage();
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
    * Rendert die Ausschlussliste für die globale Ausgabe (nur
    * Geltungsbereich "global"): eine Checkbox je vorhandener
    * Kategorie. Angehakte Kategorien erhalten keine globale
    * JSON-LD-Ausgabe (siehe README.md, "excluded_cats").
    *
    * @param string[] $excludedCats aktuell ausgeschlossene Kategorien
    * @return string HTML-Snippet oder '' falls $CatPage nicht verfügbar ist
    *
    ***************************************************************/
    private function renderExcludedCatsField(array $excludedCats): string {
        global $CatPage;
        $lang = $this->loadAdminLanguage();

        if(!isset($CatPage) or !is_object($CatPage)) {
            return '';
        }

        $cats = $CatPage->get_CatArray(true);

        $html = '<fieldset class="schemaOrgData-fieldset">'."\n";
        $html .= '<legend>'.$lang->getLanguageHtml('label_excluded_cats').'</legend>'."\n";
        $html .= '<p class="schemaOrgData-hint">'.$lang->getLanguageHtml('description_excluded_cats').'</p>'."\n";

        foreach($cats as $cat) {
            $checked = in_array($cat, $excludedCats, true) ? ' checked="checked"' : '';
            $catLabel = htmlspecialchars($cat, ENT_QUOTES, CHARSET);
            $fieldId = 'schemaOrgData_global_excluded_cats_'.md5($cat);
            $html .= '<label class="schemaOrgData-checkbox" for="'.$fieldId.'">'
                .'<input type="checkbox" id="'.$fieldId.'" name="schemaOrgData[global][excluded_cats][]" value="'.$catLabel.'"'.$checked.' /> '
                .$catLabel.'</label>'."\n";
        }

        $html .= '</fieldset>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Rendert den Scope-Selektor als Link-Navigation.
    *
    * Baut Navigations-Links mit den moziloCMS-Admin-Konstanten
    * URL_BASE, ADMIN_DIR_NAME, PLUGINADMIN und ACTION — analog
    * zur form-action in MetaKeywordsDescription. Klick auf eine
    * Kategorie oder Seite lädt dieselbe Plugin-Admin-Seite mit
    * den Plugin-eigenen GET-Parametern schemaOrgData_cat/_page
    * neu. Ist $CatPage nicht verfügbar, wird ein leerer String
    * zurückgegeben.
    *
    * @param string|null $selectedCat  aktuell gewählte Kategorie
    * @param string|null $selectedPage aktuell gewählte Seite
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderScopeSelector(?string $selectedCat, ?string $selectedPage): string {
        global $CatPage;
        $lang = $this->loadAdminLanguage();

        if (!isset($CatPage) || !is_object($CatPage)) {
            return '';
        }

        // Basis-URL: aktuelle GET-Parameter übernehmen, Plugin-eigene entfernen
        $baseParams = $_GET;
        unset($baseParams['schemaOrgData_cat'], $baseParams['schemaOrgData_page']);
        $adminBase = URL_BASE . ADMIN_DIR_NAME . '/index.php'
            . (empty($baseParams) ? '' : '?' . http_build_query($baseParams));
        $sep = empty($baseParams) ? '?' : '&';

        $cats = $CatPage->get_CatArray(false, false, [EXT_PAGE, EXT_HIDDEN]);

        $html  = '<div class="schemaOrgData-scope-selector">'."\n";
        $html .= '<span class="schemaOrgData-scope-selector__label">'
               . $lang->getLanguageHtml('label_scope_selector') . '</span>'."\n";
        $html .= '<nav class="schemaOrgData-scope-selector__nav">'."\n";

        // Link "Global" (kein cat/page-Parameter)
        $activeGlobal = ($selectedCat === null) ? ' schemaOrgData-scope-selector__link--active' : '';
        $html .= '<a class="schemaOrgData-scope-selector__link'.$activeGlobal.'"'
               . ' href="'.htmlspecialchars($adminBase, ENT_QUOTES, CHARSET).'">'
               . $lang->getLanguageHtml('scope_global') . '</a>'."\n";

        // Link je Kategorie
        foreach ($cats as $cat) {
            $catUrl    = $adminBase . $sep . 'schemaOrgData_cat=' . urlencode($cat);
            $activeCat = ($cat === $selectedCat && $selectedPage === null)
                ? ' schemaOrgData-scope-selector__link--active' : '';
            $catLabel  = htmlspecialchars(
                $CatPage->get_HrefText($cat, false), ENT_QUOTES, CHARSET
            );
            $html .= '<a class="schemaOrgData-scope-selector__link'.$activeCat.'"'
                   . ' href="'.htmlspecialchars($catUrl, ENT_QUOTES, CHARSET).'">'
                   . $catLabel . '</a>'."\n";

            // Seiten der aktuell gewählten Kategorie einrückt darunter
            if ($cat === $selectedCat) {
                $pages = $CatPage->get_PageArray($cat, [EXT_PAGE, EXT_HIDDEN], true);
                foreach ($pages as $page) {
                    $pageUrl    = $catUrl . '&schemaOrgData_page=' . urlencode($page);
                    $activePage = ($page === $selectedPage)
                        ? ' schemaOrgData-scope-selector__link--active' : '';
                    $pageLabel  = htmlspecialchars(
                        $CatPage->get_HrefText($cat, $page), ENT_QUOTES, CHARSET
                    );
                    $html .= '<a class="schemaOrgData-scope-selector__link'
                           . ' schemaOrgData-scope-selector__link--page'.$activePage.'"'
                           . ' href="'.htmlspecialchars($pageUrl, ENT_QUOTES, CHARSET).'">'
                           . '↳ ' . $pageLabel . '</a>'."\n";
                }
            }
        }

        $html .= '</nav>'."\n";
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
    * @param string $scope 'global' | 'category' | 'page'
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderScopeSection(string $scope, ?string $cat, ?string $page): string {
        $lang = $this->loadAdminLanguage();
        $config = $this->loadScopeConfig($scope, $cat, $page);

        // verfügbare Schema-Types für diesen Geltungsbereich ermitteln
        $availableTypes = [];
        foreach($this->getAvailableSchemaTypes() as $type) {
            $schema = $this->loadSchema($type);
            if($schema !== null and in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $availableTypes[$type] = $schema;
            }
        }

        // aktuell konfigurierten Type ermitteln (erster bekannter Type in $config)
        $selectedType = null;
        foreach(array_keys($config) as $type) {
            if(isset($availableTypes[$type])) {
                $selectedType = $type;
                break;
            }
        }

        $html = '<div class="schemaOrgData-scope card mb" data-scope="'.$scope.'">'."\n";
        $html .= '<h3>'.$lang->getLanguageHtml('scope_'.$scope).'</h3>'."\n";
        $html .= $this->renderInfoBlock($scope);
        $html .= $this->renderExistingJsonLdNotice($scope, $cat, $page);

        if($selectedType !== null) {
            $html .= $this->renderCollisionNotice($scope, $cat, $page, $selectedType);
        }

        $html .= '<div class="c-content">'
            .'<div class="mo-in-li-l"><label for="schemaOrgData_'.$scope.'_type">'.$lang->getLanguageHtml('label_schema_type').'</label></div>'
            .'<div class="mo-in-li-r">'.$this->renderTypeSelector($scope, $availableTypes, $selectedType).'</div>'
            .'</div>'."\n";

        foreach($availableTypes as $type => $schema) {
            $display = ($type === $selectedType) ? '' : ' style="display:none"';
            $data = is_array($config[$type] ?? null) ? $config[$type] : [];

            $html .= '<div class="schemaOrgData-type-fields" data-schema-type="'.htmlspecialchars($type, ENT_QUOTES, CHARSET).'"'.$display.'>'."\n";
            $html .= $this->renderTypeFields($scope, $type, $schema, $data);
            $html .= '</div>'."\n";
        }

        if($scope === 'global') {
            $excludedCats = !empty($config['excluded_cats'])
                ? array_map('trim', explode(',', (string) $config['excluded_cats']))
                : [];
            $html .= $this->renderExcludedCatsField($excludedCats);
        }

        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Validiert die Formularfelder eines Schema-Types serverseitig
    * (Ergänzung zur clientseitigen Live-Validierung): prüft
    * Pflichtfelder ("ui:required", inkl. PostalAddress und
    * mainEntity) sowie die Feldvalidatoren validatePostalCode,
    * validateTelephone, validateUrl, validateEmail und
    * validateOpeningHoursTime.
    *
    * @param array $formData Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array $schema   aktives JSON-Schema (schemas/{Type}.json)
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    private function validateFormData(array $formData, array $schema): array {
        $lang = $this->loadAdminLanguage();
        $errors = [];

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $fieldSchema = $this->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema['ui:widget'] ?? 'text';
            $required = (bool) ($fieldSchema['ui:required'] ?? false);
            $label = $lang->getLanguageValue($fieldSchema['ui:label'] ?? $name);
            $value = $formData[$name] ?? null;

            if($widget === 'postal_address') {
                $errors = array_merge($errors, $this->validatePostalAddressData(
                    is_array($value) ? $value : [], $fieldSchema, $required
                ));
                continue;
            }

            if($widget === 'opening_hours') {
                $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
                $perDay = is_array($value) ? $value : [];
                foreach($days as $day) {
                    $result = $this->validateOpeningHoursTime(
                        (string) ($perDay[$day]['from'] ?? ''),
                        (string) ($perDay[$day]['to'] ?? '')
                    );
                    if($result['status'] === 'error') {
                        $errors[] = $result['message'];
                    }
                }
                continue;
            }

            if($widget === 'faq_list') {
                if($required and !$this->hasFaqEntry(is_array($value) ? $value : [])) {
                    $errors[] = $lang->getLanguageValue('error_required_field', $label);
                }
                continue;
            }

            $stringValue = trim((string) ($value ?? ''));

            if($stringValue === '') {
                if($required) {
                    $errors[] = $lang->getLanguageValue('error_required_field', $label);
                }
                continue;
            }

            $format = $fieldSchema['format'] ?? null;
            $result = match(true) {
                $format === 'uri'     => $this->validateUrl($stringValue),
                $format === 'email'   => $this->validateEmail($stringValue),
                $name === 'telephone' => $this->validateTelephone($stringValue, (string) ($formData['address']['addressCountry'] ?? 'DE')),
                default               => ['status' => null, 'message' => null],
            };

            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Prüft, ob für eine PostalAddress Werte übermittelt wurden:
    * mindestens ein Feld ohne "default" (also nicht addressCountry,
    * das standardmäßig "DE" enthält) ist nicht leer.
    *
    ***************************************************************/
    private function isAddressProvided(array $address, array $subProperties): bool {
        foreach($subProperties as $subName => $subSchema) {
            $subValue = trim((string) ($address[$subName] ?? ''));
            if($subValue !== '' and !array_key_exists('default', $subSchema)) {
                return true;
            }
        }

        return false;
    }

    /***************************************************************
    *
    * Validiert die Pflichtfelder und das Format einer PostalAddress
    * (siehe resolveSchemaRef/renderPostalAddressWidget).
    * addressLocality/addressCountry sind nur dann Pflicht, wenn die
    * Adresse insgesamt Pflicht ist oder mindestens ein anderes
    * Adressfeld ausgefüllt wurde (siehe isAddressProvided).
    *
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    private function validatePostalAddressData(array $address, array $fieldSchema, bool $addressRequired): array {
        $lang = $this->loadAdminLanguage();
        $errors = [];
        $subProperties = $fieldSchema['properties'] ?? [];

        if($addressRequired or $this->isAddressProvided($address, $subProperties)) {
            foreach($subProperties as $subName => $subSchema) {
                $subRequired = (bool) ($subSchema['ui:required'] ?? false);
                $subValue = trim((string) ($address[$subName] ?? ''));

                if($subRequired and $subValue === '') {
                    $subLabel = $lang->getLanguageValue($subSchema['ui:label'] ?? $subName);
                    $errors[] = $lang->getLanguageValue('error_required_field', $subLabel);
                }
            }
        }

        $postalCode = trim((string) ($address['postalCode'] ?? ''));
        if($postalCode !== '') {
            $countryCode = (string) ($address['addressCountry'] ?? 'DE');
            $result = $this->validatePostalCode($postalCode, $countryCode);
            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        return $errors;
    }

    /***************************************************************
    *
    * Prüft, ob mindestens ein FAQ-Eintrag mit Frage UND Antwort
    * vorhanden ist. Einträge ohne beides werden von
    * sanitizePostData() verworfen (siehe renderFaqListWidget, das
    * stets eine zusätzliche leere Zeile zum Anlegen anzeigt).
    *
    ***************************************************************/
    private function hasFaqEntry(array $entries): bool {
        foreach($entries as $entry) {
            $question = trim((string) ($entry['name'] ?? ''));
            $answer = trim((string) ($entry['acceptedAnswer']['text'] ?? ''));

            if($question !== '' and $answer !== '') {
                return true;
            }
        }

        return false;
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
    * @param array $formData Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array $schema   aktives JSON-Schema (schemas/{Type}.json)
    * @return array bereinigte Properties, bereit für serialize()
    *
    ***************************************************************/
    private function sanitizePostData(array $formData, array $schema): array {
        $result = [];

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            if(!array_key_exists($name, $formData)) {
                continue;
            }

            $fieldSchema = $this->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema['ui:widget'] ?? 'text';
            $value = $formData[$name];

            if($widget === 'postal_address') {
                $address = $this->sanitizeAddressData(is_array($value) ? $value : [], $fieldSchema);
                if($address !== []) {
                    $result[$name] = $address;
                }
                continue;
            }

            if($widget === 'opening_hours') {
                $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
                $openingHours = $this->buildOpeningHoursArray(is_array($value) ? $value : [], $days);
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
    * @return array bereinigte Adress-Properties, ggf. leer
    *
    ***************************************************************/
    private function sanitizeAddressData(array $address, array $fieldSchema): array {
        $subProperties = $fieldSchema['properties'] ?? [];

        if(!$this->isAddressProvided($address, $subProperties)) {
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
    * übernommen und die conf-Datei geschrieben. Wurde kein Type
    * gewählt ("- kein Schema -"), wird die bisherige
    * Type-Konfiguration dieser Ebene entfernt, _meta und
    * excluded_cats bleiben erhalten.
    *
    * @param string $scope    'global' | 'category' | 'page'
    * @param array $postData  schemaOrgData[scope] aus $_POST
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function saveConfig(string $scope, array $postData): array {
        $lang = $this->loadAdminLanguage();
        [$cat, $page] = $this->resolveScopeIdentifiers($scope);
        $file = $this->getScopeConfFile($scope, $cat, $page);

        if($file === null) {
            return ['success' => false, 'errors' => []];
        }

        $existing = file_exists($file) ? (new Properties($file))->toArray() : [];
        $config = ['_meta' => $existing['_meta'] ?? ['existing_jsonld' => false, 'jsonld_mode' => 'keep']];

        if($scope === 'global') {
            $config['excluded_cats'] = $existing['excluded_cats'] ?? '';
        }

        $type = (string) ($postData['type'] ?? '');
        $errors = [];

        if($type !== '') {
            $schema = $this->loadSchema($type);

            if($schema === null or !in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $errors[] = $lang->getLanguageValue('error_invalid_schema_type', $type);
            } else {
                $formData = is_array($postData['data'] ?? null) ? $postData['data'] : [];
                $extensionRaw = trim((string) ($postData['extension'][$type] ?? ''));
                $extensionData = [];

                $errors = $this->validateFormData($formData, $schema);

                if($extensionRaw !== '') {
                    $decoded = json_decode($extensionRaw, true);

                    if(json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
                        $errors[] = $lang->getLanguageValue('error_json_invalid', json_last_error_msg());
                    } else {
                        $extensionData = $decoded;
                        $errors = array_merge($errors, $this->validateExtensionGeo($extensionData));
                    }
                }

                if($errors === []) {
                    $config[$type] = array_merge($extensionData, $this->sanitizePostData($formData, $schema));
                }
            }
        }

        if($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if($scope === 'global') {
            $excludedCats = [];
            foreach((array) ($postData['excluded_cats'] ?? []) as $excludedCat) {
                $excludedCat = $this->sanitizeScopeIdentifier(trim((string) $excludedCat));
                if($excludedCat !== '') {
                    $excludedCats[] = $excludedCat;
                }
            }
            $config['excluded_cats'] = implode(',', $excludedCats);
        }

        $jsonldMode = $_POST['schemaOrgData_jsonld_mode_'.$scope] ?? null;
        if(in_array($jsonldMode, ['keep', 'override'], true)) {
            $config['_meta']['jsonld_mode'] = $jsonldMode;
        }

        // Conf-Verzeichnis anlegen, falls noch nicht vorhanden
        $confDir = dirname($file);
        if (!is_dir($confDir)) {
            mkdir($confDir, 0755, true);
        }

        $written = file_put_contents($file, '<?php die(); ?>'."\n".serialize($config));

        if ($written === false) {
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ]];
        }

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Löscht die conf-Datei einer Geltungsebene vollständig - damit
    * entfallen sowohl die Schema-Type-Konfiguration als auch die
    * Meta-Daten (_meta, existing_jsonld/jsonld_mode) dieser Ebene.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function deleteConfig(string $scope): array {
        [$cat, $page] = $this->resolveScopeIdentifiers($scope);
        $file = $this->getScopeConfFile($scope, $cat, $page);

        if($file === null) {
            return ['success' => false, 'errors' => []];
        }

        if(file_exists($file)) {
            unlink($file);
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
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function handlePostRequest(): array {
        $scopes = $_POST['schemaOrgData'] ?? null;

        if(!is_array($scopes)) {
            return ['success' => true, 'errors' => []];
        }

        $success = true;
        $errors = [];

        foreach(['global', 'category', 'page'] as $scope) {
            if(!isset($scopes[$scope]) or !is_array($scopes[$scope])) {
                continue;
            }

            $result = !empty($_POST['schemaOrgData_delete_'.$scope])
                ? $this->deleteConfig($scope)
                : $this->saveConfig($scope, $scopes[$scope]);

            $success = $success and $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /***************************************************************
    *
    * Rendert das Ergebnis von handlePostRequest() als Hinweisblock
    * (Erfolg oder Fehlerliste) oberhalb der Geltungsebenen.
    *
    * @param array{success: bool, errors: string[]} $result
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderSaveResultNotice(array $result): string {
        $lang = $this->loadAdminLanguage();

        if($result['success']) {
            return '<div class="schemaOrgData-notice schemaOrgData-notice--success">'
                .$lang->getLanguageHtml('notice_config_saved')
                .'</div>'."\n";
        }

        $html = '<div class="schemaOrgData-notice schemaOrgData-notice--error">'."\n";
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
    * Liefert das CSS für das Admin-Formular (Feedback-Farben,
    * Pflichtfeld-Kennzeichnung, Öffnungszeiten-Tabelle, FAQ-Liste
    * usw.). Wird in getConfig() in einen <style>-Block eingebettet,
    * da das Plugin keine eigene CSS-Datei in das Admin-Layout
    * einbinden kann.
    *
    ***************************************************************/
    private function getAdminCss(): string {
        return '
.schemaOrgData-admin .schemaOrgData-info { background: #eef6ff; border: 1px solid #b6d4f5; padding: .75em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--info { background: #fff8e1; border: 1px solid #ffe082; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--success { background: #e8f5e9; border: 1px solid #a5d6a7; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error { background: #fdecea; border: 1px solid #f5c6c2; padding: .5em 1em; margin-bottom: 1em; border-radius: 4px; }
.schemaOrgData-admin .schemaOrgData-notice--error ul { margin: .25em 0 0; padding-left: 1.5em; }
.schemaOrgData-admin .schemaOrgData-required { color: #c0392b; font-weight: bold; }
.schemaOrgData-admin .schemaOrgData-optional { color: #888; font-size: .85em; }
.schemaOrgData-admin .schemaOrgData-fieldset { border: 1px solid #ddd; border-radius: 4px; padding: 1em; margin-bottom: 1em; }
.schemaOrgData-admin .schemaOrgData-fieldset legend { font-weight: bold; padding: 0 .5em; }
.schemaOrgData-admin .schemaOrgData-hint { color: #666; font-size: .85em; margin: 0 0 .5em; }
.schemaOrgData-admin .schemaOrgData-feedback { margin-left: .5em; font-size: .9em; }
.schemaOrgData-admin .schemaOrgData-feedback--ok { color: #2e7d32; }
.schemaOrgData-admin .schemaOrgData-feedback--warning { color: #b8860b; }
.schemaOrgData-admin .schemaOrgData-feedback--error { color: #c0392b; }
.schemaOrgData-admin .schemaOrgData-extension-feedback span { display: block; margin-top: .25em; }
.schemaOrgData-admin .schemaOrgData-opening-hours { width: 100%; border-collapse: collapse; }
.schemaOrgData-admin .schemaOrgData-opening-hours th, .schemaOrgData-admin .schemaOrgData-opening-hours td { padding: .25em .5em; text-align: left; }
.schemaOrgData-admin .schemaOrgData-faq-entry { border-top: 1px solid #eee; padding-top: .5em; margin-top: .5em; }
.schemaOrgData-admin .schemaOrgData-faq-entry:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.schemaOrgData-admin .schemaOrgData-checkbox { display: inline-block; margin: 0 1em .25em 0; }
.schemaOrgData-admin .schemaOrgData-scope-selector { display: flex; align-items: flex-start; gap: .75em; flex-wrap: wrap; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: .6em 1em; margin-bottom: 1.25em; }
.schemaOrgData-admin .schemaOrgData-scope-selector__label { font-weight: bold; padding-top: .15em; white-space: nowrap; }
.schemaOrgData-admin .schemaOrgData-scope-selector__nav { display: flex; flex-wrap: wrap; gap: .35em; }
.schemaOrgData-admin .schemaOrgData-scope-selector__link { padding: .2em .6em; border-radius: 3px; background: #fff; border: 1px solid #bbb; text-decoration: none; color: #333; font-size: .9em; white-space: nowrap; }
.schemaOrgData-admin .schemaOrgData-scope-selector__link--active { background: #1a73e8; border-color: #1a73e8; color: #fff; font-weight: bold; }
.schemaOrgData-admin .schemaOrgData-scope-selector__link--page { margin-left: .5em; font-size: .85em; }
';
    }

    /***************************************************************
    *
    * Gibt die Konfigurationsoptionen für die moziloCMS-Plugin-
    * Verwaltung zurück.
    *
    * Rendert das vollständige, schema-getriebene Konfigurations-
    * formular (Geltungsbereiche Global / Kategorie / Seite) als
    * eigenständiges HTML über "--template~~". Enthält $_POST-Daten
    * (Formular wurde abgeschickt), werden diese zuerst über
    * handlePostRequest() validiert und gespeichert; das Ergebnis
    * wird als Hinweisblock (renderSaveResultNotice()) oberhalb der
    * Geltungsbereiche ausgegeben.
    *
    ***************************************************************/
    function getConfig(): array {
        $lang = $this->loadAdminLanguage();

        $saveResult = ($_POST !== []) ? $this->handlePostRequest() : null;

        $html = '<style>'.$this->getAdminCss().'</style>'."\n";
        $html .= '<div class="schemaOrgData-admin">'."\n";

        if($saveResult !== null) {
            $html .= $this->renderSaveResultNotice($saveResult);
        }

        // Aktiven Scope ermitteln: CMS-Konstanten haben Vorrang vor GET-Params
        $selectedCat = null;
        $selectedPage = null;
        if (defined('CAT_REQUEST') && CAT_REQUEST) {
            $selectedCat = $this->sanitizeScopeIdentifier((string) CAT_REQUEST);
        } elseif (isset($_GET['schemaOrgData_cat'])) {
            $selectedCat = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']) ?: null;
        }
        if (defined('PAGE_REQUEST') && PAGE_REQUEST) {
            $selectedPage = $this->sanitizeScopeIdentifier((string) PAGE_REQUEST);
        } elseif (isset($_GET['schemaOrgData_page'])) {
            $selectedPage = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_page']) ?: null;
        }

        // Scope-Selektor rendern
        $html .= $this->renderScopeSelector($selectedCat, $selectedPage);

        // Sektionen rendern
        $html .= $this->renderScopeSection('global', null, null);
        if ($selectedCat !== null) {
            $html .= $this->renderScopeSection('category', $selectedCat, null);
            if ($selectedPage !== null) {
                $html .= $this->renderScopeSection('page', $selectedCat, $selectedPage);
            }
        }

        // GET-Parameter als versteckte Felder für POST mitführen
        if ($selectedCat !== null) {
            $html .= '<input type="hidden" name="schemaOrgData_cat"'
                   . ' value="'.htmlspecialchars($selectedCat, ENT_QUOTES, CHARSET).'" />'."\n";
        }
        if ($selectedPage !== null) {
            $html .= '<input type="hidden" name="schemaOrgData_page"'
                   . ' value="'.htmlspecialchars($selectedPage, ENT_QUOTES, CHARSET).'" />'."\n";
        }

        $html .= '</div>'."\n";

        // Lokalisierte Texte für die clientseitige Validierung (validator.js)
        $messages = [
            'postalCode'         => $lang->getLanguageValue('error_postal_code_format'),
            'telephone'          => $lang->getLanguageValue('error_telephone_format'),
            'urlInvalid'         => $lang->getLanguageValue('error_url_invalid'),
            'urlHttpWarning'     => $lang->getLanguageValue('warning_url_http'),
            'emailInvalid'       => $lang->getLanguageValue('error_email_invalid'),
            'openingHoursFormat' => $lang->getLanguageValue('error_opening_hours_format'),
            'openingHoursOrder'  => $lang->getLanguageValue('error_opening_hours_order'),
            'unknownProperty'    => $lang->getLanguageValue('warning_unknown_property'),
        ];

        $html .= '<script>window.schemaOrgDataMessages = '
            .json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).';</script>'."\n";
        $html .= '<script src="'.$this->PLUGIN_SELF_URL.'js/ajv.min.js"></script>'."\n";
        $html .= '<script src="'.$this->PLUGIN_SELF_URL.'js/validator.js"></script>'."\n";
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            .' if(window.schemaOrgDataValidator) { window.schemaOrgDataValidator.initAdminForm(); }'
            .' });</script>'."\n";

        return ['--template~~' => $html];
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
            // Plugin-Name + Version — Konvention der Core-Plugins: "Version X.Y.Z"
            'Version ' . self::PLUGIN_VERSION,
            // moziloCMS-Version — konsistent mit Core-Plugins (Breadcrumb, Contact)
            // TODO: auf '3.0' aktualisieren sobald der CMS-Kern den Versionsstring korrigiert
            '2.0 / 3.0',
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
