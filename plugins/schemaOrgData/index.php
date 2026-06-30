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
* Geltungsbereiche und Vererbung (allgemein -> spezifisch), gespeichert
* über $this->settings (moziloCMS-Properties-API, siehe getScopeSettingsKey()):
*   config_global              -> jede Seite
*   config_cat_{kategorie}     -> alle Seiten der Kategorie
*   config_page_{kategorie}_{seite} -> nur diese Seite
*
* Jeder settings-Key enthält ein Array der Form
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
    private const PLUGIN_VERSION = '0.4.17-beta';

    /** Standard-Sprache, falls die CMS-/Admin-Sprache nicht unterstützt wird */
    private const DEFAULT_LANGUAGE = 'deDE';

    /** Explizite Zuordnung: 2-Zeichen-Prefix → Locale-Code. */
    private const LANGUAGE_PREFIX_MAP = [
        'de' => 'deDE',
        'en' => 'enEN',
    ];

    /** Sprachobjekt für Admin-UI (sprachen/admin_language_{lang}.txt) */
    private ?Language $admin_lang = null;

    /** Sprachobjekt für Frontend/CMS-Kontext (sprachen/cms_language_{lang}.txt) */
    private ?Language $cms_lang = null;

    /** Sprachobjekt für Wochentag-Labels (sprachen/cms_language_{lang}.txt, Admin-Sprache) */
    private ?Language $weekday_lang = null;

    /** Aktuell aufgelöste Admin-Sprache (deDE|enEN), siehe loadAdminLanguage() */
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
        // Admin-UI im PLUGINADMIN-Kontext (Iframe-Dialog, moziloCMS speichert
        // $this->settings nach Rückgabe dieser Methode explizit)
        if (defined('PLUGINADMIN')) {
            return $this->renderAdminPage();
        }

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

        // Debug-Modus: Flag aus config_global lesen, bevor die Verwaltungsdaten
        // entfernt werden (debug_output ist kein Schema-Type, sondern ein
        // Meta-Schlüssel analog zu excluded_cats).
        $debugOutput = !empty($scopeConfigs['global']['debug_output'] ?? false);

        // Verwaltungsdaten (_meta, excluded_cats, debug_output) entfernen -
        // übrig bleiben je Ebene nur noch die Schema-Type-Konfigurationen.
        foreach($scopeConfigs as $scope => $config) {
            unset($scopeConfigs[$scope]['_meta'], $scopeConfigs[$scope]['excluded_cats'], $scopeConfigs[$scope]['debug_output']);
        }

        // jsonld_mode prüfen: wurde bereits vorhandenes JSON-LD erkannt
        // und für diese Ebene "Vorhandenes beibehalten" gewählt (Standard,
        // solange der Admin keine Wahl getroffen hat), wird die eigene
        // Ausgabe komplett unterdrückt (siehe loadScopeMeta/
        // renderExistingJsonLdNotice sowie README.md, Abschnitt
        // "Verhalten bei vorhandenem JSON-LD").
        // $globalSuppressedByKeep wird für den Dangling-Reference-Guard
        // unten benötigt: keep hat Vorrang vor einem erzwungenen Stub.
        $globalSuppressedByKeep = false;
        foreach($scopeConfigs as $scope => $config) {
            $scopeArgs = match($scope) {
                'category' => [CAT_REQUEST],
                'page'     => [CAT_REQUEST, PAGE_REQUEST],
                default    => [],
            };

            $meta = $this->loadScopeMeta($scope, ...$scopeArgs);
            if($meta['existing_jsonld'] and ($meta['jsonld_mode'] ?? 'override') === 'keep') {
                if($scope === 'global') {
                    $globalSuppressedByKeep = true;
                }
                unset($scopeConfigs[$scope]);
            }
        }

        // Feldweise Vererbung: ist derselbe Schema-Type auf mehreren
        // Ebenen konfiguriert, werden die Felder zusammengeführt
        // (Global -> Kategorie -> Seite, siehe resolveTypeInheritance()).
        $scopeConfigs = $this->resolveTypeInheritance($scopeConfigs);

        // Dangling-Reference-Guard: prüft, ob eine id_reference auf einen
        // @id-Knoten verweist, der auf dieser Seite nicht ausgegeben wird,
        // und erzwingt ggf. einen Minimal-Stub (nur bei excluded_cats-
        // Unterdrückung). Bei keep-Modus wird die id_reference stattdessen
        // unterdrückt (siehe applyDanglingReferenceGuard(), README.md,
        // "@id-Anker").
        [$scopeConfigs, $suppressedIdTargets] = $this->applyDanglingReferenceGuard(
            $scopeConfigs, $globalSuppressedByKeep
        );

        // JSON-LD-Blöcke der verbleibenden Types ausgeben; bei aktivem
        // Debug-Modus Metadaten je Block für buildDebugWidget() sammeln.
        // Pro Seite vergebene @id-Fragmente, für den De-Dup-Guard in
        // resolveNodeId() (siehe README.md, "@id-Anker").
        $assignedFragments = [];

        $debugBlocks = [];
        foreach($scopeConfigs as $scope => $config) {
            foreach($config as $type => $data) {
                $nodeId = $this->resolveNodeId($type, $assignedFragments);
                $output .= $this->buildJsonLdScript($type, $data, $nodeId, $suppressedIdTargets);
                if($debugOutput) {
                    $scopeKey = match($scope) {
                        'category' => 'cat_'.(CAT_REQUEST ? (string) CAT_REQUEST : ''),
                        'page'     => 'page_'.(CAT_REQUEST ? (string) CAT_REQUEST : '').'_'.(PAGE_REQUEST ? (string) PAGE_REQUEST : ''),
                        default    => 'global',
                    };
                    $debugBlocks[] = ['scope' => $scopeKey, 'type' => $type, 'data' => $data, 'id' => $nodeId];
                }
            }
        }

        if($debugOutput and $debugBlocks !== []) {
            $output .= $this->buildDebugWidget($debugBlocks);
        }

        // Kollisionserkennung: vorhandenes JSON-LD scope-genau persistieren
        // (Flag + Inhalt für den Autofill-Button, siehe ADR autofill).
        // Ein im Layout-Template gefundener Block ist layoutweit — er wird
        // ausschließlich dem Global-Scope zugeordnet (analog Admin-Pfad,
        // siehe extractExistingJsonLdBlocksFromTemplateAdmin() / renderAdminPage()).
        // Ein im Seiteninhalt ($value) gefundener Block ist seitenspezifisch —
        // er wird ausschließlich dem Seiten-Scope der aktuell gerenderten
        // Seite zugeordnet, sofern CAT_REQUEST und PAGE_REQUEST gesetzt sind.
        // Kategorie-Scope erhält über diesen Mechanismus keinen Eintrag.
        $templateBlocks = $this->extractExistingJsonLdBlocksFromTemplate();
        $hasJsonLdInTemplate = !empty($templateBlocks);
        $templateContent = implode("\n\n", array_map('trim', $templateBlocks));
        $metaGlobal = $this->loadScopeMeta('global');
        if($metaGlobal['existing_jsonld'] !== $hasJsonLdInTemplate
            || $metaGlobal['existing_jsonld_content'] !== $templateContent) {
            $this->saveScopeMeta('global', [
                'existing_jsonld' => $hasJsonLdInTemplate,
                'existing_jsonld_content' => $templateContent,
            ]);
        }

        $contentBlocks = $this->extractExistingJsonLdBlocks((string) $value);
        $hasJsonLdInContent = !empty($contentBlocks);
        $pageContent = implode("\n\n", array_map('trim', $contentBlocks));
        if(defined('CAT_REQUEST') and defined('PAGE_REQUEST') and CAT_REQUEST and PAGE_REQUEST) {
            $metaPage = $this->loadScopeMeta('page', CAT_REQUEST, PAGE_REQUEST);
            if($metaPage['existing_jsonld'] !== $hasJsonLdInContent
                || $metaPage['existing_jsonld_content'] !== $pageContent) {
                $this->saveScopeMeta('page', [
                    'existing_jsonld' => $hasJsonLdInContent,
                    'existing_jsonld_content' => $pageContent,
                ], CAT_REQUEST, PAGE_REQUEST);
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
    private function loadScopeConfig(
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): array {
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null || !$this->settings->keyExists($key)) {
            return [];
        }
        $data = $this->settings->get($key);
        return is_array($data) ? $data : [];
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
    * Löst Type-Kollisionen zwischen den Geltungsebenen feldweise auf
    * (Vererbung Global -> Kategorie -> Seite, siehe README.md
    * Abschnitt "Type-Kollision"): ist derselbe Schema-Type auf
    * mehreren Ebenen konfiguriert, werden die Felder über
    * mergeConfigs() zusammengeführt - leere/fehlende Felder der
    * spezifischeren Ebene erben den Wert der übergeordneten Ebene,
    * gefüllte Felder (inkl. verschachtelter Felder wie "address" oder
    * "openingHours") überschreiben vollständig (kein Merge innerhalb
    * verschachtelter Felder). Die zusammengeführten Daten werden
    * einmalig auf der spezifischsten Ebene ausgegeben, auf der der
    * Type konfiguriert ist. Verschiedene Types bleiben unabhängig.
    *
    * @param array $scopeConfigs array('global' => [...], 'category' => [...], 'page' => [...]),
    *                             jeweils array('TypeName' => array('property' => 'wert', ...), ...)
    * @return array dieselbe Struktur, mit feldweise zusammengeführten Daten
    *
    ***************************************************************/
    private function resolveTypeInheritance(array $scopeConfigs): array {
        $typeScopes = [];
        foreach(['global', 'category', 'page'] as $scope) {
            if(!isset($scopeConfigs[$scope])) {
                continue;
            }
            foreach($scopeConfigs[$scope] as $type => $data) {
                $typeScopes[$type] = $scope;
            }
        }

        $merged = $this->mergeConfigs(
            $scopeConfigs['global'] ?? [],
            $scopeConfigs['category'] ?? [],
            $scopeConfigs['page'] ?? []
        );

        foreach($scopeConfigs as $scope => $config) {
            $scopeConfigs[$scope] = [];
        }
        foreach($merged as $type => $data) {
            $scopeConfigs[$typeScopes[$type]][$type] = $data;
        }

        return $scopeConfigs;
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
    * Ermittelt die absolute Basis-URL der Installation als Quelle
    * für stabile @id-Anker (siehe README.md, Abschnitt "@id-Anker").
    *
    * Gespiegelt wird das Core-Muster der kanonischen URL ({CANONICAL_LINK}):
    * Protokoll (aus $_SERVER['HTTPS']) + Host (aus $_SERVER['HTTP_HOST'])
    * + Pfad (Verzeichnis von $_SERVER['SCRIPT_NAME']). Es gibt bewusst
    * kein eigenes Domain-Setting; die Host-Kanonisierung (z. B. 301 auf
    * den www-/HTTPS-Host) erfolgt projektseitig per .htaccess.
    *
    * @return string absolute Basis-URL mit abschließendem "/" oder ''
    *                (leer, wenn kein Host ermittelbar ist - dann wird
    *                kein @id gebildet, siehe resolveNodeId())
    *
    ***************************************************************/
    private function resolveBaseUrl(): string {
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        if($host === '') {
            // Ohne Host keine global eindeutige URI - kein @id (siehe README.md).
            return '';
        }

        $protocol = (!empty($_SERVER['HTTPS']) and strtolower((string) $_SERVER['HTTPS']) !== 'off')
            ? 'https://'
            : 'http://';

        // Pfad-Anteil aus dem Verzeichnis von SCRIPT_NAME ableiten. dirname()
        // nutzt unter Windows den Backslash als Trenner - daher das Ergebnis
        // auf "/" normalisieren, damit die @id plattformunabhängig bleibt.
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $dir = $scriptName !== '' ? str_replace('\\', '/', dirname($scriptName)) : '';
        $path = $dir !== '' ? rtrim($dir, '/').'/' : '/';

        return $protocol.$host.$path;
    }

    /***************************************************************
    *
    * Bestimmt den @id-Anker für einen auszugebenden Knoten anhand der
    * Schema-Property "ui:idFragment" (siehe README.md, Abschnitt
    * "@id-Anker"). Der Mechanismus ist vollständig schema-getrieben -
    * im PHP stehen keine Type-Namen.
    *
    * De-Dup-Guard: Ein Fragment darf pro Seite nur EINEM Knoten
    * zugewiesen werden. Tragen mehrere Knoten dasselbe Fragment
    * (z. B. "organization"), erhält nur der erste in Ausgabereihenfolge
    * den Anker; die übrigen bleiben ohne @id. $assignedFragments wird
    * dafür je vergebenem Fragment fortgeschrieben.
    *
    * Lässt sich die Basis-URL nicht auflösen, wird KEIN (leeres) @id
    * gebildet - das Fragment bleibt dann unbelegt.
    *
    * @param string $type Schema.org-Type des Knotens
    * @param array  $assignedFragments bereits vergebene Fragmente (per Referenz)
    * @return string vollständige @id-URI oder '' (kein Anker)
    *
    ***************************************************************/
    private function resolveNodeId(string $type, array &$assignedFragments): string {
        $schema = $this->loadSchema($type);
        $fragment = is_array($schema) ? trim((string) ($schema['ui:idFragment'] ?? '')) : '';
        if($fragment === '') {
            // Schema ohne ui:idFragment -> kein @id (unverändertes Verhalten).
            return '';
        }

        if(in_array($fragment, $assignedFragments, true)) {
            // Fragment bereits an einen anderen Knoten dieser Seite vergeben.
            return '';
        }

        $baseUrl = $this->resolveBaseUrl();
        if($baseUrl === '') {
            // Basis-URL nicht auflösbar -> kein leeres @id schlucken.
            return '';
        }

        $assignedFragments[] = $fragment;
        return $baseUrl.'#'.$fragment;
    }

    /***************************************************************
    *
    * Liefert alle global konfigurierten Knoten mit ui:idFragment als
    * Fragment → Label-Map für das id_reference_or_literal-Widget.
    *
    * Label = Schema-Typbezeichnung + gespeicherter name-Wert (falls vorhanden).
    * Typen ohne ui:idFragment werden übersprungen.
    *
    * @return array<string, string> [fragment => label]
    *
    ***************************************************************/
    private function resolveAvailableGlobalFragments(): array {
        $globalConfig = $this->loadScopeConfig('global');
        $lang = $this->loadAdminLanguage();
        $result = [];

        foreach($globalConfig as $type => $typeData) {
            if(!is_array($typeData)) {
                continue;
            }
            $schema = $this->loadSchema($type);
            if(!is_array($schema)) {
                continue;
            }
            $fragment = trim((string) ($schema['ui:idFragment'] ?? ''));
            if($fragment === '') {
                continue;
            }
            $typeLabelKey = $schema['ui:typeLabel'] ?? $type;
            $typeLabel = $lang->getLanguageValue($typeLabelKey);
            $name = trim((string) ($typeData['name'] ?? ''));
            $result[$fragment] = $name !== '' ? $typeLabel.' — '.$name : $typeLabel;
        }

        return $result;
    }

    /***************************************************************
    *
    * Dangling-Reference-Guard für id_reference-Properties.
    *
    * Wird in getContent() nach resolveTypeInheritance() und vor der
    * Ausgabeschleife aufgerufen. Prüft, ob eine aktive id_reference
    * auf einen @id-Knoten verweist, der im finalen Graph fehlt, und
    * reagiert entsprechend:
    *
    * - Zielknoten bereits im Graph → No-op.
    * - Zielknoten fehlt, weil Global durch keep-Modus unterdrückt
    *   wurde → id_reference unterdrücken ($suppressedIdTargets), damit
    *   kein Dangling-@id gegen den ausdrücklichen Nutzerwunsch erzeugt
    *   wird (keep hat explizit Vorrang).
    * - Zielknoten fehlt aus anderem Grund (z. B. excluded_cats) →
    *   Minimal-Stub des Zielknotens synthetisch in $scopeConfigs['global']
    *   einfügen (nur @type, @id und name). Der Stub durchläuft denselben
    *   resolveNodeId()-Mechanismus wie reguläre Knoten.
    *
    * @param array $scopeConfigs finale Scope-Konfiguration (nach resolveTypeInheritance)
    * @param bool $globalSuppressedByKeep true, wenn Global durch keep unterdrückt wurde
    * @return array{0: array, 1: array<string>} [$scopeConfigs, $suppressedIdTargets]
    *
    ***************************************************************/
    private function applyDanglingReferenceGuard(array $scopeConfigs, bool $globalSuppressedByKeep): array {
        $suppressedIdTargets = [];

        // Alle id_reference- und id_reference_or_literal-Targets sammeln.
        $activeTargets = [];
        foreach($scopeConfigs as $config) {
            foreach($config as $type => $typeData) {
                $schema = $this->loadSchema($type);
                if(!is_array($schema)) {
                    continue;
                }
                foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $this->resolveSchemaRef($propSchema, $schema);
                    $widget = $propSchema['ui:widget'] ?? '';
                    if($widget === 'id_reference') {
                        $target = trim((string) ($propSchema['ui:idTarget'] ?? ''));
                        if($target !== '') {
                            $activeTargets[] = $target;
                        }
                    } elseif($widget === 'id_reference_or_literal') {
                        // Nur Referenz-Modus erzeugt eine @id-Abhängigkeit.
                        $stored = is_array($typeData[$propName] ?? null) ? $typeData[$propName] : null;
                        if($stored !== null and ($stored['_mode'] ?? '') === 'reference') {
                            $fragment = trim((string) ($stored['_fragment'] ?? ''));
                            if($fragment !== '') {
                                $activeTargets[] = $fragment;
                            }
                        }
                    }
                }
            }
        }

        if($activeTargets === []) {
            return [$scopeConfigs, $suppressedIdTargets];
        }

        // Bereits im Graph vorhandene @id-Fragmente bestimmen.
        $presentFragments = [];
        foreach($scopeConfigs as $config) {
            foreach(array_keys($config) as $type) {
                $schema = $this->loadSchema($type);
                if(!is_array($schema)) {
                    continue;
                }
                $fragment = trim((string) ($schema['ui:idFragment'] ?? ''));
                if($fragment !== '') {
                    $presentFragments[] = $fragment;
                }
            }
        }

        foreach(array_unique($activeTargets) as $target) {
            if(in_array($target, $presentFragments, true)) {
                // Zielknoten vorhanden - kein Eingriff nötig.
                continue;
            }

            if($globalSuppressedByKeep) {
                // keep-Modus hat Vorrang: id_reference nicht emittieren,
                // damit kein Dangling-@id gegen die Nutzerwahl erzeugt wird.
                $suppressedIdTargets[] = $target;
                continue;
            }

            // Zielknoten fehlt (z. B. excluded_cats): Minimal-Stub erzwingen.
            // Aus der globalen Konfiguration den Type mit dem passenden
            // ui:idFragment laden und als Stub mit @type, @id und name einfügen.
            $globalConfig = $this->loadScopeConfig('global');
            unset($globalConfig['_meta'], $globalConfig['excluded_cats'], $globalConfig['debug_output']);

            foreach($globalConfig as $globalType => $globalData) {
                $schema = $this->loadSchema($globalType);
                if(!is_array($schema)) {
                    continue;
                }
                if(trim((string) ($schema['ui:idFragment'] ?? '')) !== $target) {
                    continue;
                }

                // Stub-Inhalt: nur name als Pflicht-Identifikator;
                // @type und @id werden durch die reguläre Ausgabeschleife
                // (buildJsonLdScript + resolveNodeId) ergänzt.
                $stub = [];
                $nameValue = is_array($globalData) ? ($globalData['name'] ?? '') : '';
                if(is_string($nameValue) and $nameValue !== '') {
                    $stub['name'] = $nameValue;
                }

                if(!isset($scopeConfigs['global'])) {
                    $scopeConfigs['global'] = [];
                }
                $scopeConfigs['global'][$globalType] = $stub;
                break;
            }
        }

        return [$scopeConfigs, $suppressedIdTargets];
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
    * @param string $nodeId optionaler @id-Anker (siehe README.md, "@id-Anker");
    *               wird - sofern nicht-leer - direkt hinter @type eingefügt
    * @return string fertiger <script>-Block inkl. Zeilenumbruch
    *
    ***************************************************************/
    private function buildJsonLdScript(string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): string {
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

        // id_reference-Properties aus dem Schema einsetzen (Build-Zeit-Emitter).
        // Diese Properties haben keinen gespeicherten Wert - ihr Wert wird hier
        // zur Ausgabezeit als {"@id": "<Basis-URL>#<Fragment>"} aufgelöst.
        // Einfügung NACH removeEmptyJsonLdProperties(), damit ein gesetzter
        // @id-Verweis nie still getilgt wird (analoges Muster zum @id-Anker,
        // siehe README.md, "@id-Anker"). Bei keep-Modus auf der Zielebene wird
        // das Target in $suppressedIdTargets übergeben - dann unterbleibt die
        // Emission, um kein Dangling-@id gegen den Nutzerwunsch zu erzeugen.
        $schema = $this->loadSchema($type);
        if(is_array($schema)) {
            $baseUrl = $this->resolveBaseUrl();
            foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                $propSchema = $this->resolveSchemaRef($propSchema, $schema);
                $widget = $propSchema['ui:widget'] ?? '';
                if($widget === 'id_reference') {
                    $target = trim((string) ($propSchema['ui:idTarget'] ?? ''));
                    if($target !== '' and $baseUrl !== '' and !in_array($target, $suppressedIdTargets, true)) {
                        $data[$propName] = ['@id' => $baseUrl.'#'.$target];
                    }
                } elseif($widget === 'id_reference_or_literal') {
                    // Gespeicherten Wert (Array mit _mode + _fragment oder Literal-Felder)
                    // in das fertige JSON-LD-Objekt umwandeln.
                    $stored = is_array($data[$propName] ?? null) ? $data[$propName] : null;
                    unset($data[$propName]);
                    if($stored !== null) {
                        $mode = (string) ($stored['_mode'] ?? '');
                        if($mode === 'reference') {
                            $fragment = trim((string) ($stored['_fragment'] ?? ''));
                            if($fragment !== '' and $baseUrl !== '' and !in_array($fragment, $suppressedIdTargets, true)) {
                                $data[$propName] = ['@id' => $baseUrl.'#'.$fragment];
                            }
                        } elseif($mode === 'literal') {
                            $literal = $stored;
                            unset($literal['_mode']);
                            // Leere Felder entfernen, bevor @type ergänzt wird,
                            // damit ein leeres Objekt nicht allein durch @type
                            // als nicht-leer gilt.
                            $literal = $this->removeEmptyJsonLdProperties($literal);
                            if($literal !== []) {
                                $literalType = trim((string) ($propSchema['ui:literalType'] ?? ''));
                                if($literalType !== '') {
                                    $literal = array_merge(['@type' => $literalType], $literal);
                                }
                                $data[$propName] = $literal;
                            }
                        }
                    }
                }
            }
        }

        // @id-Anker erst NACH dem Leerfilter setzen, damit ein gesetzter
        // Anker nie still getilgt wird (siehe README.md, "@id-Anker").
        $head = ['@context' => 'https://schema.org', '@type' => $type];
        if($nodeId !== '') {
            $head['@id'] = $nodeId;
        }

        $jsonLd = array_merge($head, $data);

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
    * Ermittelt den Sprachcode für die Plugin-Sprachdateien.
    *
    * Empfängt den rohen Sprachcode aus $ADMIN_CONF bzw. $CMS_CONF
    * (z. B. 'deDE', 'enUS', 'de'). Normalisiert auf Kleinschreibung und
    * prüft via str_starts_with() den 2-Zeichen-Prefix gegen
    * LANGUAGE_PREFIX_MAP. Liefert beim ersten Treffer den
    * moziloCMS-Locale-Code (z. B. 'deDE'). Fällt bei unbekannten
    * Locales auf DEFAULT_LANGUAGE zurück.
    *
    * @param string|null $code Sprachcode aus $ADMIN_CONF->get('language')
    *                          bzw. $CMS_CONF->get('cmslanguage')
    * @return string           Locale-Code ('deDE' oder 'enEN')
    *
    ***************************************************************/
    private function resolvePluginLanguage(?string $code): string {
        $lower = strtolower((string) $code);
        foreach(self::LANGUAGE_PREFIX_MAP as $prefix => $locale) {
            if(str_starts_with($lower, $prefix)) {
                return $locale;
            }
        }
        return self::DEFAULT_LANGUAGE;
    }

    /***************************************************************
    *
    * Extrahiert alle JSON-LD-Blöcke aus einem HTML-String und gibt
    * deren innere JSON-Texte zurück (ohne <script>-Tags).
    *
    * Gemeinsame Hilfsfunktion für detectExistingJsonLd*()-Methoden
    * sowie für die Inhaltsspeicherung (existing_jsonld_content,
    * siehe loadScopeMeta()/saveScopeMeta()).
    * Kein Absturz bei malformed HTML — preg_match_all gibt im
    * Fehlerfall false zurück, was als leeres Array behandelt wird.
    *
    * @param string $html zu prüfendes HTML
    * @return array innere JSON-Texte aller gefundenen Blöcke
    *
    ***************************************************************/
    private function extractExistingJsonLdBlocks(string $html): array {
        $result = preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        );
        return ($result !== false and !empty($matches[1])) ? $matches[1] : [];
    }

    /***************************************************************
    *
    * Prüft, ob im gerenderten HTML der Seite bereits ein
    * <script type="application/ld+json">-Block vorhanden ist
    * (kombinierte Prüfung: Inhalt + Template).
    *
    * Hinweis: Für die produktive Scope-Zuordnung in getContent()
    * wird diese kombinierte Methode nicht mehr verwendet — stattdessen
    * prüfen dort extractExistingJsonLdBlocksFromTemplate() (→ Global-Scope)
    * und extractExistingJsonLdBlocks($value) (→ Seiten-Scope) getrennt.
    * Diese Methode bleibt erhalten, da CollisionDetectorTest.php das
    * kombinierte Verhalten gezielt testet.
    *
    * @param string $html zu prüfendes HTML
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    private function detectExistingJsonLd(string $html): bool {
        if(!empty($this->extractExistingJsonLdBlocks($html))) {
            return true;
        }
        return $this->detectExistingJsonLdInTemplate();
    }

    /***************************************************************
    *
    * Liest das aktiv geladene Website-Template vom Dateisystem und
    * gibt alle darin enthaltenen JSON-LD-Blöcke zurück.
    *
    * $TEMPLATE_FILE zeigt zu getContent()-Zeit bereits auf die
    * korrekte Datei (template.html oder gallerytemplate.html).
    *
    * @return array innere JSON-Texte aller gefundenen Blöcke (leer = keiner)
    *
    ***************************************************************/
    private function extractExistingJsonLdBlocksFromTemplate(): array {
        global $TEMPLATE_FILE;
        if(empty($TEMPLATE_FILE) or !file_exists($TEMPLATE_FILE)) {
            return [];
        }
        $content = file_get_contents($TEMPLATE_FILE);
        return $content !== false ? $this->extractExistingJsonLdBlocks($content) : [];
    }

    /***************************************************************
    *
    * Prüft, ob das aktiv geladene Website-Template einen
    * <script type="application/ld+json">-Block enthält.
    *
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    private function detectExistingJsonLdInTemplate(): bool {
        return !empty($this->extractExistingJsonLdBlocksFromTemplate());
    }

    /***************************************************************
    *
    * Liest im Admin-Kontext die aktiv ausgelieferten Layout-Templates
    * vom Dateisystem und gibt alle darin enthaltenen JSON-LD-Blöcke
    * zurück.
    *
    * Der Template-Pfad wird aus $CMS_CONF->get('cmslayout') abgeleitet.
    * Bei aktivem Draftmode wird zusätzlich das Draftlayout geprüft
    * (mirrors moziloCMS-Frontend-index.php:87–91). Inaktive Layouts
    * werden bewusst NICHT geprüft (False-Positive-Schutz).
    *
    * Je ermitteltem Layout werden template.html und
    * gallerytemplate.html geprüft (Pfadbildung analog
    * admin/template.php:46 / admin/editsite.php:346). Defensiv:
    * $CMS_CONF / LAYOUT_DIR_NAME / BASE_DIR auf Verfügbarkeit geprüft,
    * file_exists/Lesbarkeit abgesichert.
    *
    * @return array innere JSON-Texte aller gefundenen Blöcke (leer = keiner)
    *
    ***************************************************************/
    private function extractExistingJsonLdBlocksFromTemplateAdmin(): array {
        global $CMS_CONF;

        if (!defined('BASE_DIR') || !defined('LAYOUT_DIR_NAME')
            || !isset($CMS_CONF) || !is_object($CMS_CONF)) {
            return [];
        }

        $activeLayout = (string) ($CMS_CONF->get('cmslayout') ?? '');
        if ($activeLayout === '' || $activeLayout === 'false') {
            return [];
        }

        // Immer das aktive Layout prüfen; Draftlayout zusätzlich nur
        // wenn Draftmode aktiviert und Draftlayout gültig gesetzt ist.
        $layoutsToCheck = [$activeLayout];

        if ($CMS_CONF->get('draftmode') === 'true') {
            $draftLayout = (string) ($CMS_CONF->get('draftlayout') ?? '');
            if ($draftLayout !== '' && $draftLayout !== 'false') {
                $layoutsToCheck[] = $draftLayout;
            }
        }

        $allBlocks = [];
        foreach ($layoutsToCheck as $layout) {
            foreach (['template.html', 'gallerytemplate.html'] as $tplFile) {
                $path = BASE_DIR . LAYOUT_DIR_NAME . '/' . $layout . '/' . $tplFile;
                if (!file_exists($path) || !is_readable($path)) {
                    continue;
                }
                $content = file_get_contents($path);
                if ($content !== false) {
                    $allBlocks = array_merge($allBlocks, $this->extractExistingJsonLdBlocks($content));
                }
            }
        }

        return $allBlocks;
    }

    /***************************************************************
    *
    * Prüft im Admin-Kontext, ob das aktiv ausgelieferte Layout-Template
    * einen <script type="application/ld+json">-Block enthält.
    *
    * @return bool true wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    private function detectExistingJsonLdInTemplateAdmin(): bool {
        return !empty($this->extractExistingJsonLdBlocksFromTemplateAdmin());
    }

    /***************************************************************
    *
    * Liefert den settings-Schlüssel für eine Geltungsebene.
    * Ersetzt getScopeConfFile() — die Konfiguration wird über
    * $this->settings statt über conf/-Dateien gespeichert.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return string|null Settings-Key oder null bei ungültigem $scope
    *
    ***************************************************************/
    private function getScopeSettingsKey(
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): ?string {
        return match($scope) {
            'global'   => 'config_global',
            'category' => $cat !== null
                ? 'config_cat_' . $cat
                : null,
            'page'     => ($cat !== null && $page !== null)
                ? 'config_page_' . $cat . '_' . $page
                : null,
            default    => null,
        };
    }

    /***************************************************************
    *
    * Entfernt aus einem Bezeichner (CAT_REQUEST/PAGE_REQUEST) alle
    * Zeichen, die in settings-Keys nicht erlaubt bzw. unerwünscht
    * sind, bevor er in getScopeSettingsKey() verwendet wird (Schutz
    * vor Path-Traversal, siehe README.md, Abschnitt "Sicherheit").
    *
    * @return string bereinigter Bezeichner
    *
    ***************************************************************/
    private function sanitizeScopeIdentifier(string $value): string {
        // Buchstaben, Ziffern, Bindestriche, Unterstriche und Prozentzeichen
        // (URL-Encoding moziloCMS) erhalten. Path-Traversal-Zeichen (.,/,\,NUL)
        // werden entfernt — % allein stellt kein Traversal-Risiko dar.
        return preg_replace('/[^a-zA-Z0-9_\-%]/', '', $value);
    }

    /***************************************************************
    *
    * Liefert die (sanitierten) CAT_REQUEST/PAGE_REQUEST-Werte einer
    * Geltungsebene, passend für getScopeSettingsKey().
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{0: string|null, 1: string|null} [cat, page]
    *
    ***************************************************************/
    private function resolveScopeIdentifiers(string $scope): array {
        $cat = (defined('CAT_REQUEST') and CAT_REQUEST) ? $this->sanitizeScopeIdentifier((string) CAT_REQUEST) : null;
        $page = (defined('PAGE_REQUEST') and PAGE_REQUEST) ? $this->sanitizeScopeIdentifier((string) PAGE_REQUEST) : null;

        // Fallback auf Plugin-eigene Parameter im Admin-Kontext
        // (CAT_REQUEST/PAGE_REQUEST sind im moziloCMS-Admin nicht gesetzt).
        // Fallback-Reihenfolge: CMS-Konstanten → POST → GET. POST hat
        // Vorrang vor GET, da das Speichern-Formular ohne Query-String
        // an die admin/index.php sendet (siehe getConfig()).
        if ($cat === null && isset($_POST['schemaOrgData_cat'])) {
            $cat = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_cat']);
            if ($cat === '') $cat = null;
        }
        if ($cat === null && isset($_GET['schemaOrgData_cat'])) {
            $cat = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']);
            if ($cat === '') $cat = null;
        }
        if ($page === null && isset($_POST['schemaOrgData_page'])) {
            $page = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_page']);
            if ($page === '') $page = null;
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
    private function loadScopeMeta(
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): array {
        $defaults = ['existing_jsonld' => false, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''];
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null || !$this->settings->keyExists($key)) {
            return $defaults;
        }
        $data = $this->settings->get($key);
        return array_merge($defaults, is_array($data) ? ($data['_meta'] ?? []) : []);
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
    private function saveScopeMeta(
        string $scope,
        array  $meta,
        ?string $cat  = null,
        ?string $page = null
    ): void {
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null) {
            return;
        }
        $existing = $this->settings->keyExists($key)
            ? $this->settings->get($key) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing['_meta'] = array_merge(
            $existing['_meta'] ?? ['existing_jsonld' => false, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''],
            $meta
        );
        // Schreibfehler protokollieren — saveScopeMeta hat kein Rückgabe-Array,
        // daher error_log als stilles Fallback
        try {
            $this->settings->set($key, $existing);
        } catch (\Throwable $e) {
            error_log('schemaOrgData: saveScopeMeta fehlgeschlagen: ' . $e->getMessage());
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
            // id_reference wird zur Build-Zeit automatisch emittiert,
            // id_reference_or_literal verwaltet eigene Pflichtprüfung in validateFormData().
            $propSchema = $this->resolveSchemaRef($schema['properties'][$requiredProperty] ?? [], $schema);
            $widget = $propSchema['ui:widget'] ?? '';
            if($widget === 'id_reference' or $widget === 'id_reference_or_literal') {
                continue;
            }

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
    * Erkennt, ob ein openingHours-Wert bereits als rohe Pro-Tag-Werte
    * vorliegt (["Mo" => ["from" => ..., "to" => ...], ...], aus dem
    * POST nach fehlgeschlagenem Save - siehe renderScopeSection) statt
    * als openingHours-Array in schema.org-Notation (["Mo-Fr 09:00-18:00"]).
    *
    ***************************************************************/
    private function isPerDayOpeningHoursValue(array $value): bool {
        foreach($value as $entry) {
            return is_array($entry);
        }

        return false;
    }

    /***************************************************************
    *
    * Zerlegt ein openingHours-Array (schema.org-Notation, z. B.
    * "Mo-Fr 09:00-18:00") in Von/Bis-Zeiten je Wochentag.
    * Mehrere Einträge für denselben Tag werden gesammelt und nach
    * from-Zeit sortiert: frühester Eintrag → Hauptzeitraum
    * (from/to), zweiter Eintrag (falls vorhanden) → zweiter
    * Zeitraum (from2/to2). Ein dritter Eintrag für denselben Tag
    * wird ignoriert (außerhalb des Widget-Scopes).
    *
    * @param array $openingHours z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @return array<string,array{from:string,to:string,from2:string,to2:string}> je Tag (leer = geschlossen)
    *
    ***************************************************************/
    private function parseOpeningHours(array $openingHours, array $days): array {
        $collected = [];
        foreach($days as $day) {
            $collected[$day] = [];
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
                $collected[$days[$i]][] = ['from' => $from, 'to' => $to];
            }
        }

        $result = [];
        foreach($days as $day) {
            $entries = $collected[$day];
            usort($entries, fn($a, $b) => strcmp($a['from'], $b['from']));
            $result[$day] = [
                'from'  => $entries[0]['from'] ?? '',
                'to'    => $entries[0]['to'] ?? '',
                'from2' => $entries[1]['from'] ?? '',
                'to2'   => $entries[1]['to'] ?? '',
            ];
        }

        return $result;
    }

    /***************************************************************
    *
    * Baut aus den Von/Bis-Zeiten je Wochentag ein openingHours-Array
    * in schema.org-Notation. Aufeinanderfolgende Tage mit identischen
    * Zeiten werden zu einem Bereich (z. B. "Mo-Fr 09:00-18:00")
    * zusammengefasst. Tage ohne Zeiten ("geschlossen") werden
    * ausgelassen. $fromKey/$toKey wählen das Felderpaar (Hauptzeitraum
    * "from"/"to" oder zweiter Zeitraum "from2"/"to2").
    *
    * @param array<string,array> $perDay je Tag Zeitpaare
    * @param string[] $days Wochentags-Kürzel in Reihenfolge, z. B. ["Mo",...,"Su"]
    * @param string $fromKey Schlüssel für Von-Zeit im $perDay-Eintrag
    * @param string $toKey   Schlüssel für Bis-Zeit im $perDay-Eintrag
    * @return string[] z. B. ["Mo-Fr 09:00-18:00", "Sa 10:00-14:00"]
    *
    ***************************************************************/
    private function buildOpeningHoursArray(array $perDay, array $days, string $fromKey = 'from', string $toKey = 'to'): array {
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
            $from = trim((string) ($perDay[$day][$fromKey] ?? ''));
            $to = trim((string) ($perDay[$day][$toKey] ?? ''));

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

        if(($from === '') !== ($to === '')) {
            return ['status' => 'error', 'message' => $this->loadAdminLanguage()->getLanguageValue('error_opening_hours_incomplete')];
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
    * @param string|null $feedbackId Element-ID für das <span> (z. B.
    *        "<fieldId>_feedback"). validator.js (showFieldFeedback())
    *        sucht/aktualisiert das Element anhand dieser ID, statt ein
    *        zweites (falsch positioniertes) Feedback-Element anzulegen.
    * @return string HTML-Snippet oder '' wenn $result['status'] === null
    *
    ***************************************************************/
    private function renderValidationFeedback(array $result, ?string $feedbackId = null): string {
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
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderRequiredBadge(bool $required): string {
        if(!$required) {
            return '';
        }

        $lang = $this->loadAdminLanguage();

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
    * @return string HTML-Snippet oder '' wenn $originLabel null ist
    *
    ***************************************************************/
    private function renderInheritedBadge(?string $originLabel): string {
        if($originLabel === null) {
            return '';
        }

        $lang = $this->loadAdminLanguage();

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
    * "ui:options" (flache Liste) oder aus "enum" +
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
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param array|null $inheritedValue Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge,
    *        wird nicht übernommen
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    *
    ***************************************************************/
    /***************************************************************
    *
    * Rendert das Widget id_reference_or_literal:
    * Radio-Auswahl zwischen „Verknüpfen" (Dropdown globaler @id-Knoten)
    * und „Manuell" (Literal-Felder gemäß ui:literalFields).
    *
    * @param string $scope  Geltungsbereich (global/category/page)
    * @param string $name   Property-Name im Schema
    * @param array $fieldSchema Schema-Definition der Property
    * @param array $value   Gespeicherter Wert ['_mode' => ..., ...]
    * @param string $idPrefix Präfix für HTML-IDs
    * @return string HTML des Widgets
    *
    ***************************************************************/
    private function renderIdReferenceOrLiteralWidget(string $scope, string $name, array $fieldSchema, array $value, string $idPrefix): string {
        $lang = $this->loadAdminLanguage();
        $availableFragments = $this->resolveAvailableGlobalFragments();

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

        $html  = '<div class="schemaOrgData-idrl-container" id="'.htmlspecialchars($containerId, ENT_QUOTES, CHARSET).'">'."\n";

        // Radio: Referenz-Modus
        $html .= '<label class="schemaOrgData-idrl-radio-label">'
            .'<input type="radio" class="schemaOrgData-idrl-radio"'
            .' name="'.htmlspecialchars($modeField, ENT_QUOTES, CHARSET).'" value="reference"'
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
            .' name="'.htmlspecialchars($modeField, ENT_QUOTES, CHARSET).'" value="literal"'
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
        $html .= '<script>if(!window.schemaOrgDataIdRlToggle){'
            .'window.schemaOrgDataIdRlToggle=function(r){'
            .'var c=r.closest(".schemaOrgData-idrl-container");'
            .'c.querySelectorAll(".schemaOrgData-idrl-section").forEach(function(s){s.style.display="none";});'
            .'c.querySelector(".schemaOrgData-idrl-"+r.value).style.display="";'
            .'};}</script>'."\n";

        return $html;
    }

    private function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix = null, ?array $inheritedValue = null, ?string $inheritedLabel = null): string {
        $idPrefix = $idPrefix ?? $scope;
        $countryFieldId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_addressCountry';
        $properties = $fieldSchema['properties'] ?? [];
        $html = '';

        // Straße und Hausnummer: schema.org kennt kein eigenes
        // Hausnummer-Feld - "Straße und Hausnummer" ist ein
        // kombiniertes streetAddress-Feld und erhält eine eigene,
        // volle Zeile.
        if(isset($properties['streetAddress'])) {
            $field = $this->renderAddressSubField($scope, $name, 'streetAddress', $properties['streetAddress'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel);
            $html .= $this->renderAddressFullRow($field);
        }

        // PLZ + Ort kompakt in einer Zeile (PLZ schmal, Ort flexibel)
        $html .= $this->renderAddressFieldGroup($scope, $name, $properties, $value, $countryFieldId, $idPrefix, [
            'postalCode'      => true,
            'addressLocality' => false,
        ], $inheritedValue, $inheritedLabel);

        // Land: eigene Zeile, Select ~200px breit (siehe getAdminCss)
        if(isset($properties['addressCountry'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressCountry', $properties['addressCountry'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel);
            $html .= $this->renderAddressFullRow($field);
        }

        // Region/Bundesland: eigene Zeile, ~300px breit (siehe getAdminCss)
        if(isset($properties['addressRegion'])) {
            $field = $this->renderAddressSubField($scope, $name, 'addressRegion', $properties['addressRegion'], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel);
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
    private function renderAddressFullRow(array $field): string {
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
    * @return array{fieldId:string,label:string,badge:string,widget:string,feedback:string}
    *
    ***************************************************************/
    private function renderAddressSubField(string $scope, string $name, string $subName, array $subSchema, array $value, string $countryFieldId, string $idPrefix, ?array $inheritedValue = null, ?string $inheritedLabel = null): array {
        $lang = $this->loadAdminLanguage();
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name.'_'.$subName;
        $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']['.$subName.']';
        $subValue = $value[$subName] ?? ($subSchema['default'] ?? null);
        $required = (bool) ($subSchema['ui:required'] ?? false);
        $label = $lang->getLanguageHtml($subSchema['ui:label'] ?? $subName);
        $badge = $this->renderRequiredBadge($required);

        // Placeholder + "ü"-Badge für ein leeres Sub-Feld, dessen Wert von
        // einer übergeordneten Ebene geerbt würde (siehe Task 1,
        // resolveInheritableFields()) - das Feld selbst bleibt leer.
        $isEmpty = !isset($value[$subName]) or $value[$subName] === '';
        $inheritedSubValue = $inheritedValue[$subName] ?? null;
        if($isEmpty and is_scalar($inheritedSubValue) and (string) $inheritedSubValue !== '') {
            if(($subSchema['ui:widget'] ?? 'text') !== 'select') {
                $subSchema['ui:placeholder'] = (string) $inheritedSubValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel);
        }

        if(($subSchema['ui:widget'] ?? 'text') === 'select') {
            $widgetHtml = $this->renderSelectWidget($fieldId, $fieldName, $subSchema, $subValue);
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
            $feedback = $this->renderValidationFeedback($this->validatePostalCode((string) $subValue, $countryCode), $fieldId.'_feedback');
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
    *
    ***************************************************************/
    private function renderAddressFieldGroup(string $scope, string $name, array $properties, array $value, string $countryFieldId, string $idPrefix, array $subNames, ?array $inheritedValue = null, ?string $inheritedLabel = null): string {
        $html = '<div class="c-content schemaOrgData-field-row">'
            .'<div class="mo-in-li-l"></div>'
            .'<div class="mo-in-li-r"><div class="schemaOrgData-address-row">'."\n";

        foreach($subNames as $subName => $narrow) {
            if(!isset($properties[$subName])) {
                continue;
            }
            $field = $this->renderAddressSubField($scope, $name, $subName, $properties[$subName], $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel);
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
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    *
    ***************************************************************/
    private function renderOpeningHoursWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix = null): string {
        $lang = $this->loadAdminLanguage();
        $idPrefix = $idPrefix ?? $scope;
        $weekdayLang = $this->loadWeekdayLanguage();
        $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $dayLabelKeys = $fieldSchema['ui:dayLabelKeys'] ?? [];

        // $value liegt entweder als openingHours-Array in schema.org-Notation
        // vor (gespeicherte Konfiguration / sanitizePostData) oder als rohe
        // Pro-Tag-Werte aus dem POST (Re-Display nach fehlgeschlagenem Save,
        // siehe renderScopeSection) - im zweiten Fall die Werte unverändert
        // übernehmen, um auch ungültige Zeitformate anzuzeigen.
        $perDay = $this->isPerDayOpeningHoursValue($value)
            ? $value
            : $this->parseOpeningHours($value, $days);

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

            $feedback = $this->renderValidationFeedback($this->validateOpeningHoursTime($from, $to), $fromId.'_feedback');

            $feedback2Result = $this->validateOpeningHoursTime($from2, $to2);
            if($feedback2Result['status'] === null && $from2 !== '' && $to2 !== '' && $to !== '' && $from2 < $to) {
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
    * @param array $fieldSchema Feld-Schema (items.properties)
    * @param array $value gespeichertes mainEntity-Array
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    *
    ***************************************************************/
    private function renderFaqListWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix = null): string {
        $lang = $this->loadAdminLanguage();
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
            $badge = $this->renderRequiredBadge((bool) ($questionSchema['ui:required'] ?? false));

            $html .= '<div class="schemaOrgData-faq-entry">'."\n";
            $html .= '<div class="c-content schemaOrgData-field-row">'
                .'<div class="mo-in-li-l"><label for="'.$questionId.'">'.$questionLabel.'</label>'.$badge.'</div>'
                .'<div class="mo-in-li-r">'.$this->renderTextWidget($questionId, $questionName, $questionSchema, $question).'</div>'
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
    *
    ***************************************************************/
    private function renderExtensionFieldWidget(string $scope, string $type, string $extensionJson, ?string $idPrefix = null): string {
        $lang = $this->loadAdminLanguage();
        $idPrefix = $idPrefix ?? $scope;
        $fieldId = 'schemaOrgData_'.$idPrefix.'_extension';
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
    * data-country-field für telephone). Pflichtfelder ("ui:required")
    * erhalten zusätzlich data-required-message, damit der Blur-Handler
    * (validator.js, runFieldValidation()) leere Pflichtfelder sofort
    * meldet.
    *
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @return array<string,string>
    *
    ***************************************************************/
    private function buildValidationAttrs(string $scope, string $name, array $fieldSchema, ?string $idPrefix = null): array {
        $idPrefix = $idPrefix ?? $scope;
        $format = $fieldSchema['format'] ?? null;
        $required = (bool) ($fieldSchema['ui:required'] ?? false);

        if($format === 'uri') {
            $attrs = ['data-validate' => 'url'];
        } elseif($format === 'email') {
            $attrs = ['data-validate' => 'email'];
        } elseif($name === 'telephone') {
            $attrs = [
                'data-validate' => 'telephone',
                'data-country-field' => 'schemaOrgData_'.$idPrefix.'_address_addressCountry',
            ];
        } elseif($required) {
            $attrs = ['data-validate' => 'required'];
        } else {
            $attrs = [];
        }

        if($required) {
            $lang = $this->loadAdminLanguage();
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
    * @param array $allData alle Formular-Properties des Schema-Types
    *                        (für telephone -> address.addressCountry)
    * @param string $feedbackId Element-ID für das Feedback-<span>
    *        (siehe renderValidationFeedback())
    *
    ***************************************************************/
    private function renderFieldFeedback(string $name, array $fieldSchema, string $value, array $allData, string $feedbackId): string {
        $format = $fieldSchema['format'] ?? null;

        if($format === 'uri') {
            return $this->renderValidationFeedback($this->validateUrl($value), $feedbackId);
        }

        if($format === 'email') {
            return $this->renderValidationFeedback($this->validateEmail($value), $feedbackId);
        }

        if($name === 'telephone') {
            $countryCode = (string) ($allData['address']['addressCountry'] ?? 'DE');
            return $this->renderValidationFeedback($this->validateTelephone($value, $countryCode), $feedbackId);
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
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param mixed $inheritedValue Wert, der von einer übergeordneten Ebene
    *        geerbt würde (siehe resolveInheritableFields()) - nur für
    *        Placeholder + "ü"-Badge, wird nicht übernommen
    * @param string|null $inheritedLabel lesbares Label der Ursprungsebene
    *        (siehe buildScopeLabel()), für den Badge-Tooltip
    *
    ***************************************************************/
    private function renderField(string $scope, string $name, array $fieldSchema, mixed $value, array $rootSchema, array $allData, ?string $idPrefix = null, mixed $inheritedValue = null, ?string $inheritedLabel = null): string {
        $idPrefix = $idPrefix ?? $scope;
        $fieldSchema = $this->resolveSchemaRef($fieldSchema, $rootSchema);
        $widget = $fieldSchema['ui:widget'] ?? 'text';
        $lang = $this->loadAdminLanguage();
        $label = $lang->getLanguageHtml($fieldSchema['ui:label'] ?? $name);
        $required = (bool) ($fieldSchema['ui:required'] ?? false);
        $badge = $this->renderRequiredBadge($required);
        $fieldId = 'schemaOrgData_'.$idPrefix.'_'.$name;
        $isEmpty = ($value === null or $value === '' or $value === []);

        // id_reference: rein deklaratives Widget ohne Eingabefeld.
        // Der Wert wird zur Build-Zeit in buildJsonLdScript() emittiert;
        // im Formular genügt eine schreibgeschützte Info-Anzeige mit der
        // aufgelösten Ziel-URI.
        if($widget === 'id_reference') {
            $target = trim((string) ($fieldSchema['ui:idTarget'] ?? ''));
            $baseUrl = $this->resolveBaseUrl();
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
                $scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix
            );
            return '<fieldset class="schemaOrgData-fieldset">'."\n"
                .'<legend>'.$label.$badge.'</legend>'."\n"
                .$inner
                .'</fieldset>'."\n";
        }

        if(in_array($widget, ['postal_address', 'opening_hours', 'faq_list'], true)) {
            $inner = match($widget) {
                'postal_address' => $this->renderPostalAddressWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix, is_array($inheritedValue) ? $inheritedValue : null, $inheritedLabel),
                'opening_hours'  => $this->renderOpeningHoursWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix),
                'faq_list'       => $this->renderFaqListWidget($scope, $name, $fieldSchema, is_array($value) ? $value : [], $idPrefix),
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
                $fieldSchema['ui:placeholder'] = (string) $inheritedValue;
            }
            $badge .= $this->renderInheritedBadge($inheritedLabel);
        }

        $fieldName = 'schemaOrgData['.$scope.'][data]['.$name.']';

        $widgetHtml = match($widget) {
            'select'   => $this->renderSelectWidget($fieldId, $fieldName, $fieldSchema, $value),
            'textarea' => $this->renderTextareaWidget($fieldId, $fieldName, $fieldSchema, $value),
            default    => $this->renderTextWidget($fieldId, $fieldName, $fieldSchema, $value, $this->buildValidationAttrs($scope, $name, $fieldSchema, $idPrefix)),
        };

        $feedback = ($value !== null and $value !== '' and is_scalar($value))
            ? $this->renderFieldFeedback($name, $fieldSchema, (string) $value, $allData, $fieldId.'_feedback')
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
    * @param array $schema vollständiges Schema (schemas/{Type}.json)
    * @param array $data   gespeicherte Properties dieses Types (Formular + Erweiterung gemischt)
    * @param string|null $idPrefix Präfix für HTML-IDs (Fallback: $scope)
    * @param string|null $extensionJsonOverride wenn gesetzt, wird dieser Wert
    *        statt der aus $data abgeleiteten Erweiterungs-Properties als
    *        Inhalt des Erweiterungsfelds verwendet (siehe renderScopeSection(),
    *        POST-Daten nach fehlgeschlagenem Speichern)
    * @param array{data: array<string,mixed>, originLabel: array<string,string>} $inheritable
    *        Werte (und deren Herkunfts-Label), die von einer übergeordneten
    *        Ebene für dieses Type geerbt würden (siehe
    *        resolveInheritableFields()) - nur für Placeholder + "ü"-Badge
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderTypeFields(string $scope, string $type, array $schema, array $data, ?string $idPrefix = null, ?string $extensionJsonOverride = null, array $inheritable = ['data' => [], 'originLabel' => []]): string {
        $idPrefix = $idPrefix ?? $scope;
        $split = $this->splitDataForRendering($data, $schema);
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
            );
        }

        $html .= $this->renderExtensionFieldWidget($scope, $type, $extensionJson, $idPrefix);

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
    * @param string|null $idPrefix Präfix für die HTML-ID des <select> (Fallback: $scope)
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderTypeSelector(string $scope, array $availableTypes, ?string $selectedType, ?string $idPrefix = null): string {
        $lang = $this->loadAdminLanguage();
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
    * Prüft, ob für $selectedType bereits auf einer allgemeineren
    * Ebene (Global bzw. Global+Kategorie) eine Konfiguration
    * existiert. Ist dies der Fall, erbt diese Ebene leere Felder von
    * der allgemeineren Ebene; gefüllte Felder überschreiben deren
    * Ausgabe für diesen Type (siehe README.md, Abschnitt
    * "Type-Kollision" und resolveTypeInheritance()).
    *
    * @param string $scope 'category' | 'page' (für 'global' immer [])
    * @return string[] Liste der allgemeineren Ebenen, von denen geerbt wird
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
    * Ermittelt für eine Kategorie-/Seiten-Sektion, welche Feldwerte
    * eines Schema-Types von einer übergeordneten Ebene (Global bzw.
    * Global+Kategorie) geerbt würden (siehe resolveTypeInheritance()),
    * sowie die jeweilige Ursprungsebene als lesbares Label (siehe
    * buildScopeLabel()). Dient ausschließlich der Anzeige im Formular
    * (Placeholder + "ü"-Badge, siehe renderInheritedBadge()) - die
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
    * @return array{data: array<string,mixed>, originLabel: array<string,string>}
    *
    ***************************************************************/
    private function resolveInheritableFields(string $scope, ?string $cat, ?string $page, string $type): array {
        $higherScopes = match($scope) {
            'category' => [
                ['global', $this->loadScopeConfig('global'), null, null],
            ],
            'page' => [
                ['global', $this->loadScopeConfig('global'), null, null],
                ['category', $this->loadScopeConfig('category', $cat), $cat, null],
            ],
            default => [],
        };

        $data = [];
        $originLabel = [];
        foreach($higherScopes as [$higherScope, $higherConfig, $higherCat, $higherPage]) {
            if(!is_array($higherConfig[$type] ?? null)) {
                continue;
            }
            $label = $this->buildScopeLabel($higherScope, $higherCat, $higherPage);
            foreach($higherConfig[$type] as $field => $value) {
                $data[$field] = $value;
                $originLabel[$field] = $label;
            }
        }

        return ['data' => $data, 'originLabel' => $originLabel];
    }

    /***************************************************************
    *
    * Rendert den Hinweis auf eine Vererbung von einer allgemeineren
    * Ebene für denselben Type (siehe detectTypeCollision()).
    *
    * @return string HTML-Snippet oder '' wenn keine Vererbung vorliegt
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
    * Zusätzlich wird die Debug-Modus-Checkbox gerendert.
    *
    * @param string[] $excludedCats aktuell ausgeschlossene Kategorien
    * @param bool $debugOutput      aktueller Zustand des Debug-Flags
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderExcludedCatsField(array $excludedCats, bool $debugOutput = false): string {
        global $CatPage;
        $lang = $this->loadAdminLanguage();
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
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderScopeSelector(?string $selectedCat, ?string $selectedPage): string {
        global $CatPage;
        $lang = $this->loadAdminLanguage();

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
    * Liefert die für den Nutzer lesbare Bezeichnung eines
    * Geltungsbereichs, z. B. "Global", "Kategorie Über-uns" oder
    * "Seite kontakt". Wird als data-scope-label in
    * renderScopeSection() ausgegeben und von initScopeSelector()
    * (validator.js) für den Hinweis auf ungespeicherte Eingaben
    * beim Scope-Wechsel verwendet (Sprachschlüssel
    * notice_unsaved_changes, Platzhalter {PARAM1}).
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return string
    *
    ***************************************************************/
    private function buildScopeLabel(string $scope, ?string $cat, ?string $page): string {
        $lang = $this->loadAdminLanguage();

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
    * @return string HTML (bereits escaped via getLanguageHtml())
    *
    ***************************************************************/
    private function buildSaveButtonLabel(?string $selectedCat, ?string $selectedPage): string {
        $lang = $this->loadAdminLanguage();

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
    * @return string HTML-Snippet
    *
    ***************************************************************/
    private function renderScopeSection(string $scope, ?string $cat, ?string $page, bool $active = true, ?string $idPrefix = null, bool $saveFailed = false): string {
        $idPrefix = $idPrefix ?? $scope;
        $lang = $this->loadAdminLanguage();
        $config = $this->loadScopeConfig($scope, $cat, $page);

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
        foreach($this->getAvailableSchemaTypes() as $type) {
            $schema = $this->loadSchema($type);
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
        $labelAttr     = htmlspecialchars($this->buildScopeLabel($scope, $cat, $page), ENT_QUOTES, CHARSET);
        $saveLabelAttr = htmlspecialchars($this->buildSaveButtonLabel(
            $scope === 'global' ? null : $cat,
            $scope === 'page'   ? $page : null
        ), ENT_QUOTES, CHARSET);
        $displayStyle = $active ? '' : ' style="display:none"';
        $html = '<div class="schemaOrgData-scope card mb" data-scope="'.$scope.'"'
              . ' data-scope-cat="'.$catAttr.'" data-scope-page="'.$pageAttr.'"'
              . ' data-scope-label="'.$labelAttr.'" data-save-label="'.$saveLabelAttr.'"'.$displayStyle.'>'."\n";
        $html .= '<h3>'.$lang->getLanguageHtml('scope_'.$scope).'</h3>'."\n";
        $html .= $this->renderInfoBlock($scope);
        $html .= $this->renderExistingJsonLdNotice($scope, $cat, $page);

        if($selectedType !== null) {
            $html .= $this->renderCollisionNotice($scope, $cat, $page, $selectedType);
        }

        $html .= '<div class="c-content schemaOrgData-field-row schemaOrgData-type-selector-row">'
            .'<div class="mo-in-li-l"><label for="schemaOrgData_'.$idPrefix.'_type">'.$lang->getLanguageHtml('label_schema_type').'</label></div>'
            .'<div class="mo-in-li-r">'.$this->renderTypeSelector($scope, $availableTypes, $selectedType, $idPrefix).'</div>'
            .'</div>'."\n";

        foreach($availableTypes as $type => $schema) {
            $display = ($type === $selectedType) ? '' : ' style="display:none"';
            $extensionOverride = null;

            if($postScope !== null and $type === $selectedType) {
                $postData = is_array($postScope['data'] ?? null) ? $postScope['data'] : [];
                $data = $this->sanitizePostData($postData, $schema);
                $extensionOverride = (string) ($postScope['extension'][$type] ?? '');

                // Öffnungszeiten: die rohen Pro-Tag-Werte aus dem POST statt
                // des verlustbehafteten Roundtrips über buildOpeningHoursArray()/
                // parseOpeningHours() verwenden, damit Felder mit ungültigem
                // Zeitformat beim Re-Display nicht geleert werden (siehe
                // renderOpeningHoursWidget).
                foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $this->resolveSchemaRef($propSchema, $schema);
                    if(($propSchema['ui:widget'] ?? '') === 'opening_hours' and is_array($postData[$propName] ?? null)) {
                        $data[$propName] = $postData[$propName];
                    }
                }
            } else {
                $data = is_array($config[$type] ?? null) ? $config[$type] : [];
            }

            $typeIdPrefix = $idPrefix.'_'.$type;
            $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type);

            $html .= '<div class="schemaOrgData-type-fields" data-schema-type="'.htmlspecialchars($type, ENT_QUOTES, CHARSET).'"'.$display.'>'."\n";
            $html .= $this->renderTypeFields($scope, $type, $schema, $data, $typeIdPrefix, $extensionOverride, $inheritable);
            $html .= '</div>'."\n";
        }

        if($scope === 'global') {
            if($postScope !== null) {
                $excludedCats = [];
                foreach((array) ($postScope['excluded_cats'] ?? []) as $excludedCat) {
                    $excludedCat = $this->sanitizeScopeIdentifier(trim((string) $excludedCat));
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
            $html .= $this->renderExcludedCatsField($excludedCats, $debugOutput);
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
    * Validiert die Formularfelder eines Schema-Types serverseitig
    * (Ergänzung zur clientseitigen Live-Validierung): prüft
    * Pflichtfelder ("ui:required", inkl. PostalAddress und
    * mainEntity) sowie die Feldvalidatoren validatePostalCode,
    * validateTelephone, validateUrl, validateEmail und
    * validateOpeningHoursTime.
    *
    * Ist ein Pflichtfeld leer, aber ein geerbter Wert von einer
    * übergeordneten Ebene vorhanden ($inheritable['data']), wird
    * kein Pflichtfeld-Fehler erzeugt - der geerbte Wert deckt das
    * Pflichtfeld ab (analog clientseitigem Placeholder-Check).
    *
    * @param array $formData   Formularfeld-Werte (schemaOrgData[scope][data])
    * @param array $schema     aktives JSON-Schema (schemas/{Type}.json)
    * @param array $inheritable Rückgabe von resolveInheritableFields():
    *                           ['data' => [...], 'originLabel' => [...]]
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    private function validateFormData(array $formData, array $schema, array $inheritable = []): array {
        $lang = $this->loadAdminLanguage();
        $inheritableData = $inheritable['data'] ?? [];
        $errors = [];

        foreach($schema['properties'] ?? [] as $name => $fieldSchema) {
            $fieldSchema = $this->resolveSchemaRef($fieldSchema, $schema);
            $widget = $fieldSchema['ui:widget'] ?? 'text';
            $required = (bool) ($fieldSchema['ui:required'] ?? false);
            $label = $lang->getLanguageValue($fieldSchema['ui:label'] ?? $name);
            $value = $formData[$name] ?? null;

            if($widget === 'postal_address') {
                $inheritableAddress = is_array($inheritableData[$name] ?? null) ? $inheritableData[$name] : [];
                $errors = array_merge($errors, $this->validatePostalAddressData(
                    is_array($value) ? $value : [], $fieldSchema, $inheritableAddress
                ));
                continue;
            }

            if($widget === 'opening_hours') {
                $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
                $perDay = is_array($value) ? $value : [];
                foreach($days as $day) {
                    $from  = (string) ($perDay[$day]['from']  ?? '');
                    $to    = (string) ($perDay[$day]['to']    ?? '');
                    $from2 = (string) ($perDay[$day]['from2'] ?? '');
                    $to2   = (string) ($perDay[$day]['to2']   ?? '');

                    $result = $this->validateOpeningHoursTime($from, $to);
                    if($result['status'] === 'error') {
                        $errors[] = $result['message'];
                    }

                    $result2 = $this->validateOpeningHoursTime($from2, $to2);
                    if($result2['status'] === 'error') {
                        $errors[] = $result2['message'];
                    }

                    // Zweiter Zeitraum darf nicht vor Ende des ersten beginnen
                    if($from2 !== '' && $to2 !== '' && $to !== '' && $from2 < $to) {
                        $errors[] = $lang->getLanguageValue('error_opening_hours_overlap');
                    }
                }
                continue;
            }

            if($widget === 'faq_list') {
                if($required and !$this->hasFaqEntry(is_array($value) ? $value : [])) {
                    // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                    // Ebene ein nicht-leeres FAQ-Array geerbt wird.
                    $inheritedList = $inheritableData[$name] ?? null;
                    if(!is_array($inheritedList) or !$this->hasFaqEntry($inheritedList)) {
                        $errors[] = $lang->getLanguageValue('error_required_field', $label);
                    }
                }
                continue;
            }

            if($widget === 'id_reference_or_literal') {
                if($required) {
                    $stored = is_array($value) ? $value : [];
                    $mode = (string) ($stored['_mode'] ?? 'reference');
                    if($mode === 'reference') {
                        $fragment = trim((string) ($stored['_fragment'] ?? ''));
                        if($fragment === '') {
                            $errors[] = $lang->getLanguageValue('error_required_field', $label);
                        }
                    } elseif($mode === 'literal') {
                        $hasValue = false;
                        foreach($fieldSchema['ui:literalFields'] ?? [] as $lf) {
                            if(trim((string) ($stored[(string) $lf] ?? '')) !== '') {
                                $hasValue = true;
                                break;
                            }
                        }
                        if(!$hasValue) {
                            $errors[] = $lang->getLanguageValue('error_required_field', $label);
                        }
                    }
                }
                continue;
            }

            $stringValue = trim((string) ($value ?? ''));

            if($stringValue === '') {
                if($required) {
                    // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                    // Ebene ein nicht-leerer Wert geerbt wird.
                    $inheritedValue = $inheritableData[$name] ?? null;
                    if(!is_scalar($inheritedValue) or (string) $inheritedValue === '') {
                        $errors[] = $lang->getLanguageValue('error_required_field', $label);
                    }
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
    * das standardmäßig "DE" enthält) ist nicht leer. Wird beim
    * Bereinigen (sanitizeAddressData) verwendet, um keine Adresse zu
    * speichern, die nur den Default-Wert von addressCountry enthält.
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
    *
    * Die als "ui:required" markierten Unter-Properties (z. B.
    * addressLocality, addressCountry) werden grundsätzlich geprüft -
    * auch dann, wenn der Nutzer kein einziges Adressfeld ausgefüllt
    * hat. Andernfalls würde die voreingestellte Länder-Select-Box
    * (default "DE") eine leere Adresse als "nicht angegeben"
    * erscheinen lassen und der Pflichtfeld-Check für "Ort" beim
    * Speichern (z. B. auf Globalebene) nicht greifen.
    *
    * Ist ein Pflichtfeld leer, aber ein geerbter Wert aus einer
    * übergeordneten Ebene vorhanden ($inheritableAddress), entfällt
    * der Fehler - der geerbte Wert deckt das Pflichtfeld ab.
    *
    * @param array $inheritableAddress Adress-Properties, die von einer
    *        übergeordneten Ebene geerbt würden (Rückgabe von
    *        resolveInheritableFields()['data']['address'])
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    private function validatePostalAddressData(array $address, array $fieldSchema, array $inheritableAddress = []): array {
        $lang = $this->loadAdminLanguage();
        $errors = [];
        $subProperties = $fieldSchema['properties'] ?? [];

        // Wurde kein Adressfeld ausgefüllt (nur Default-Werte wie addressCountry=DE),
        // entfallen alle Pflichtfeld-Prüfungen — die Adresse als Ganzes ist nicht required.
        if(!$this->isAddressProvided($address, $subProperties)) {
            return [];
        }

        foreach($subProperties as $subName => $subSchema) {
            $subRequired = (bool) ($subSchema['ui:required'] ?? false);
            $subValue = trim((string) ($address[$subName] ?? ''));

            if($subRequired and $subValue === '') {
                // Pflichtfeld-Fehler entfällt, wenn von einer übergeordneten
                // Ebene ein nicht-leerer Wert für dieses Sub-Feld geerbt wird.
                $inheritedSubValue = trim((string) ($inheritableAddress[$subName] ?? ''));
                if($inheritedSubValue === '') {
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
                $perDay = is_array($value) ? $value : [];
                $primary = $this->buildOpeningHoursArray($perDay, $days);
                $secondary = $this->buildOpeningHoursArray($perDay, $days, 'from2', 'to2');
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
    * übernommen und über $this->settings gespeichert. Wurde kein Type
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
        $key = $this->getScopeSettingsKey($scope, $cat, $page);

        if ($key === null) {
            return ['success' => false, 'errors' => []];
        }

        $existing = $this->settings->keyExists($key)
            ? $this->settings->get($key) : [];
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
            $schema = $this->loadSchema($type);

            if($schema === null or !in_array($scope, $schema['ui:scopes'] ?? [], true)) {
                $errors[] = $lang->getLanguageValue('error_invalid_schema_type', $type);
            } else {
                $formData = is_array($postData['data'] ?? null) ? $postData['data'] : [];
                $extensionRaw = trim((string) ($postData['extension'][$type] ?? ''));
                $extensionData = [];

                $inheritable = $this->resolveInheritableFields($scope, $cat, $page, $type);
                $errors = $this->validateFormData($formData, $schema, $inheritable);

                if($extensionRaw !== '') {
                    $decoded = json_decode($extensionRaw, true);

                    if(json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
                        $errors[] = $lang->getLanguageValue('error_json_invalid');
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
            $config['debug_output'] = !empty($postData['debug_output']);
        }

        $jsonldMode = $_POST['schemaOrgData_jsonld_mode_'.$scope] ?? null;
        if(in_array($jsonldMode, ['keep', 'override'], true)) {
            $config['_meta']['jsonld_mode'] = $jsonldMode;
        }

        // Konfiguration über moziloCMS-settings-API speichern
        try {
            $this->settings->set($key, $config);
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
    * Löscht den settings-Key einer Geltungsebene vollständig - damit
    * entfallen sowohl die Schema-Type-Konfiguration als auch die
    * Meta-Daten (_meta, existing_jsonld/jsonld_mode) dieser Ebene.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function deleteConfig(string $scope): array {
        [$cat, $page] = $this->resolveScopeIdentifiers($scope);
        $key = $this->getScopeSettingsKey($scope, $cat, $page);

        if ($key === null) {
            return ['success' => false, 'errors' => []];
        }

        if ($this->settings->keyExists($key)) {
            $this->settings->delete($key);
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
    * @return ?array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function handlePostRequest(): ?array {
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
        $catParam  = $this->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_cat']  ?? ''));
        $pageParam = $this->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_page'] ?? ''));
        $isGlobalScope = ($catParam === '' && $pageParam === '');

        if($isGlobalScope) {
            $globalData = (isset($scopes['global']) and is_array($scopes['global']))
                ? $scopes['global'] : [];

            $result = !empty($_POST['schemaOrgData_delete_global'])
                ? $this->deleteConfig('global')
                : $this->saveConfig('global', $globalData);

            $success = $success && $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        foreach(['category', 'page'] as $scope) {
            $hasData = isset($scopes[$scope]) and is_array($scopes[$scope]);

            if(!$hasData) {
                continue;
            }

            $result = !empty($_POST['schemaOrgData_delete_'.$scope])
                ? $this->deleteConfig($scope)
                : $this->saveConfig($scope, $scopes[$scope]);

            $success = $success && $result['success'];
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
    * Baut das Frontend-Debug-Widget: einen fixierten Trigger-Button
    * und einen <dialog> mit je einem Abschnitt pro ausgegebenem
    * JSON-LD-Block (Scope-Herkunft, formatiertes JSON, Kopier-Button)
    * sowie einem Link auf validator.schema.org.
    *
    * Wird von getContent() angehängt, wenn debug_output aktiv ist
    * und mindestens ein Block ausgegeben wurde. Alle Styles sind
    * inline, alle IDs mit "schemaOrgData-debug-" präfixiert — keine
    * globalen CSS-Klassen, da dies auf der echten Frontend-Seite
    * landet.
    *
    * @param array $blocks [['scope' => 'global'|'cat_x'|'page_x_y', 'type' => '...', 'data' => [...]], ...]
    * @return string HTML-Snippet inkl. <script>
    *
    ***************************************************************/
    private function buildDebugWidget(array $blocks): string {
        $count = count($blocks);
        $plural = $count !== 1 ? 'Blöcke' : 'Block';

        $html  = '<button id="schemaOrgData-debug-trigger" type="button" '
            .'style="position:fixed;bottom:1em;right:1em;z-index:9999;background:#1a73e8;color:#fff;'
            .'border:none;border-radius:4px;padding:.5em 1em;font-size:14px;cursor:pointer;'
            .'box-shadow:0 2px 8px rgba(0,0,0,.3);">'
            .'🔧 Debug: '.$count.' JSON-LD-'.$plural.'</button>'."\n";

        $html .= '<dialog id="schemaOrgData-debug-dialog" '
            .'style="max-width:800px;width:90vw;max-height:85vh;overflow:auto;border-radius:6px;'
            .'border:1px solid #ccc;box-shadow:0 4px 24px rgba(0,0,0,.2);padding:1.5em;">'."\n";

        // Kopfzeile mit Validator-Link und Schließen-Button
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;'
            .'margin-bottom:1em;border-bottom:1px solid #eee;padding-bottom:.75em;">'."\n";
        $html .= '<strong style="font-size:1.1em;">🔧 Schema.org JSON-LD Debug</strong>'."\n";
        $html .= '<div style="display:flex;gap:.5em;align-items:center;">'."\n";
        $html .= '<a href="https://validator.schema.org" target="_blank" rel="noopener" '
            .'style="font-size:.85em;color:#1a73e8;text-decoration:none;border:1px solid #1a73e8;'
            .'border-radius:3px;padding:.2em .6em;">validator.schema.org öffnen ↗</a>'."\n";
        $html .= '<button id="schemaOrgData-debug-close" type="button" '
            .'style="background:none;border:none;font-size:1.3em;cursor:pointer;color:#666;'
            .'padding:.1em .4em;" aria-label="Schlie&szlig;en">&#x2715;</button>'."\n";
        $html .= '</div></div>'."\n";

        foreach($blocks as $i => $block) {
            $type   = $block['type'];
            $scope  = $block['scope'];
            $data   = $block['data'];
            $nodeId = (string) ($block['id'] ?? '');
            $preId  = 'schemaOrgData-debug-pre-'.$i;
            $copyId = 'schemaOrgData-debug-copy-'.$i;

            // Dieselben Transformationen wie buildJsonLdScript(), damit die
            // Vorschau byte-identisch mit dem echten <script>-Block ist.
            $data = $this->decodeJsonLdValues($data);
            $data = $this->removeEmptyJsonLdProperties($data);
            foreach(['address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'employee' => 'Person'] as $property => $nestedType) {
                if(isset($data[$property]) and is_array($data[$property])) {
                    $data[$property] = array_merge(['@type' => $nestedType], $data[$property]);
                }
            }
            $head = ['@context' => 'https://schema.org', '@type' => $type];
            if($nodeId !== '') {
                $head['@id'] = $nodeId;
            }
            $jsonLd = array_merge($head, $data);
            $prettyJson = json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $html .= '<div style="margin-bottom:1.5em;">'."\n";
            $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4em;">'."\n";
            $html .= '<h3 style="margin:0;font-size:.95em;color:#333;">'
                .htmlspecialchars($scope, ENT_QUOTES, CHARSET).' &mdash; '
                .htmlspecialchars($type, ENT_QUOTES, CHARSET).'</h3>'."\n";
            $html .= '<button id="'.$copyId.'" type="button" data-pre="'.$preId.'" '
                .'style="font-size:.8em;background:#f5f5f5;border:1px solid #ccc;border-radius:3px;'
                .'padding:.2em .6em;cursor:pointer;">JSON kopieren</button>'."\n";
            $html .= '</div>'."\n";
            $html .= '<pre id="'.$preId.'" '
                .'style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:.75em;'
                .'overflow:auto;font-size:.8em;white-space:pre-wrap;margin:0;">'
                .htmlspecialchars($prettyJson !== false ? $prettyJson : '', ENT_QUOTES, CHARSET)
                .'</pre>'."\n";
            $html .= '</div>'."\n";
        }

        $html .= '</dialog>'."\n";

        $html .= '<script>(function(){'."\n";
        $html .= 'var trigger=document.getElementById("schemaOrgData-debug-trigger");'."\n";
        $html .= 'var dialog=document.getElementById("schemaOrgData-debug-dialog");'."\n";
        $html .= 'var closeBtn=document.getElementById("schemaOrgData-debug-close");'."\n";
        $html .= 'if(trigger&&dialog&&dialog.showModal){'."\n";
        $html .= '  trigger.addEventListener("click",function(){dialog.showModal();});'."\n";
        $html .= '}'."\n";
        $html .= 'if(closeBtn&&dialog){'."\n";
        $html .= '  closeBtn.addEventListener("click",function(){dialog.close();});'."\n";
        $html .= '}'."\n";
        $html .= 'function fallbackCopy(text){'."\n";
        $html .= '  var ta=document.createElement("textarea");'."\n";
        $html .= '  ta.value=text;ta.style.position="fixed";ta.style.opacity="0";'."\n";
        $html .= '  document.body.appendChild(ta);ta.focus();ta.select();'."\n";
        $html .= '  try{document.execCommand("copy");}catch(e){}'."\n";
        $html .= '  document.body.removeChild(ta);'."\n";
        $html .= '}'."\n";
        $html .= 'var copyBtns=document.querySelectorAll("[id^=\'schemaOrgData-debug-copy-\']");'."\n";
        $html .= 'copyBtns.forEach(function(btn){'."\n";
        $html .= '  btn.addEventListener("click",function(){'."\n";
        $html .= '    var pre=document.getElementById(btn.getAttribute("data-pre"));'."\n";
        $html .= '    if(!pre)return;'."\n";
        $html .= '    var text=pre.textContent||pre.innerText;'."\n";
        $html .= '    var orig=btn.textContent;'."\n";
        $html .= '    function ok(){btn.textContent="Kopiert!";setTimeout(function(){btn.textContent=orig;},1500);}'."\n";
        $html .= '    if(navigator.clipboard&&window.isSecureContext){'."\n";
        $html .= '      navigator.clipboard.writeText(text).then(ok).catch(function(){fallbackCopy(text);ok();});'."\n";
        $html .= '    }else{fallbackCopy(text);ok();}'."\n";
        $html .= '  });'."\n";
        $html .= '});'."\n";
        $html .= '})();</script>'."\n";

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
    ***************************************************************/
    private function renderAdminPage(): string {
        global $CatPage;
        $lang = $this->loadAdminLanguage();

        $saveResult = ($_POST !== []) ? $this->handlePostRequest() : null;

        // Bei fehlgeschlagenem Speichern wird die aktive Sektion in
        // renderScopeSection() mit den POST-Daten statt mit dem
        // gespeicherten Konfigurations-Stand befüllt (siehe dort).
        $saveFailed = ($saveResult !== null and $saveResult['success'] === false);

        // Aktiven Scope ermitteln: $_POST (Formular wurde abgeschickt) hat
        // Vorrang vor $_GET (initialer Aufruf der Admin-Seite)
        $selectedCat = null;
        $selectedPage = null;
        if (isset($_POST['schemaOrgData_cat'])) {
            $selectedCat = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_cat']) ?: null;
        } elseif (isset($_GET['schemaOrgData_cat'])) {
            $selectedCat = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']) ?: null;
        }
        if (isset($_POST['schemaOrgData_page'])) {
            $selectedPage = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_page']) ?: null;
        } elseif (isset($_GET['schemaOrgData_page'])) {
            $selectedPage = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_page']) ?: null;
        }

        $formAction = URL_BASE . ADMIN_DIR_NAME . '/index.php';
        $saveButtonLabel = $this->buildSaveButtonLabel($selectedCat, $selectedPage);

        $html = '<style>'.$this->getAdminCss().'</style>'."\n";
        $html .= '<form method="POST" action="'.htmlspecialchars($formAction, ENT_QUOTES, CHARSET).'">'."\n";
        $html .= '<input type="hidden" name="pluginadmin" value="'.PLUGINADMIN.'" />'."\n";
        $html .= '<input type="hidden" name="action" value="'.ACTION.'" />'."\n";
        $html .= '<div class="schemaOrgData-admin">'."\n";

        if($saveResult !== null) {
            $html .= $this->renderSaveResultNotice($saveResult);
        }

        // Zusätzlicher Speichern-Button am Formularanfang (oben rechts) -
        // derselbe Submit wie der Button am Formularende, damit lange
        // Formulare nicht erst bis zum Ende gescrollt werden müssen
        $html .= '<div class="schemaOrgData-save-bar schemaOrgData-save-bar--top">'."\n";
        $html .= '<button type="submit" class="mo-btn mo-btn--primary">'
               . $saveButtonLabel.'</button>'."\n";
        $html .= '</div>'."\n";

        // Scope-Selektor rendern
        $html .= $this->renderScopeSelector($selectedCat, $selectedPage);

        // Template-Kollisionserkennung: im Admin-Kontext (IS_ADMIN) live prüfen.
        // Ein im Layout-Template eingebundener JSON-LD-Block ist layoutweit
        // und damit kein seiten-/kategoriespezifisches Signal - das Ergebnis
        // wird deshalb unabhängig vom aktiven Scope ausschließlich dem
        // Global-Scope zugeordnet (siehe README.md). Properties::set()
        // schreibt im IS_ADMIN-Kontext auf die Platte (im Frontend war
        // set() ein No-Op). Reihenfolge: erst saveScopeMeta(), dann
        // renderScopeSection(), damit renderExistingJsonLdNotice() das
        // frisch gesetzte Flag und den Inhalt (Autofill-Button) sieht.
        $templateBlocks = $this->extractExistingJsonLdBlocksFromTemplateAdmin();
        $templateHasJsonLd = !empty($templateBlocks);
        $templateContent = implode("\n\n", array_map('trim', $templateBlocks));

        // Schreib-Guard: nur bei tatsächlicher Änderung persistieren, um
        // nicht bei jedem Admin-Load einen file_put_contents auszulösen.
        $metaGlobal = $this->loadScopeMeta('global');
        if ($metaGlobal['existing_jsonld'] !== $templateHasJsonLd
            || $metaGlobal['existing_jsonld_content'] !== $templateContent) {
            $this->saveScopeMeta('global', [
                'existing_jsonld' => $templateHasJsonLd,
                'existing_jsonld_content' => $templateContent,
            ]);
        }

        // Global immer rendern (aktiv wenn keine Kategorie gewählt)
        $html .= $this->renderScopeSection(
            'global', null, null,
            active: $selectedCat === null,
            idPrefix: 'global',
            saveFailed: $saveFailed
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
            $safeCat   = $this->sanitizeScopeIdentifier($cat);
            $catActive = ($safeCat === $selectedCat && $selectedPage === null);
            $html .= $this->renderScopeSection(
                'category', $cat, null,
                active: $catActive,
                idPrefix: 'cat_' . $safeCat,
                saveFailed: $saveFailed
            );

            // Seiten aller Kategorien vorrendern - inaktive erhalten display:none
            if (isset($CatPage) && is_object($CatPage)
                && method_exists($CatPage, 'get_PageArray')) {
                $pages = $CatPage->get_PageArray($cat, [EXT_PAGE, EXT_HIDDEN], true);
                foreach ($pages as $page) {
                    $safePage   = $this->sanitizeScopeIdentifier($page);
                    $pageActive = ($safeCat === $selectedCat && $safePage === $selectedPage);
                    $html .= $this->renderScopeSection(
                        'page', $cat, $page,
                        active: $pageActive,
                        idPrefix: 'page_' . $safeCat . '_' . $safePage,
                        saveFailed: $saveFailed
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
        $html .= '<script src="'.$this->PLUGIN_SELF_URL.'js/ajv.min.js"></script>'."\n";
        $html .= '<script src="'.$this->PLUGIN_SELF_URL.'js/validator.js"></script>'."\n";
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            .' if(window.schemaOrgDataValidator) { window.schemaOrgDataValidator.initAdminForm(); }'
            .' });</script>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Gibt die Button-Konfiguration für die moziloCMS-Plugin-
    * Verwaltung zurück ("--admin~~"). Die eigentliche Admin-UI wird
    * über getContent() im PLUGINADMIN-Kontext gerendert (siehe
    * renderAdminPage()).
    *
    ***************************************************************/
    function getConfig(): array {
        $lang = $this->loadAdminLanguage();
        return [
            '--admin~~' => [
                'buttontext'  => $lang->getLanguageValue('admin_button'),
                'description' => $lang->getLanguageValue('plugin_description_short'),
                'datei_admin' => 'index.php',
            ]
        ];
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
