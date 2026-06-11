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
