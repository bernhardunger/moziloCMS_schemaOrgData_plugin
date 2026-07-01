<?php if(!defined('IS_CMS')) die();

require_once __DIR__.'/lib/SchemaOrgData_UrlHelper.php';
require_once __DIR__.'/lib/SchemaOrgData_LanguageService.php';
require_once __DIR__.'/lib/SchemaOrgData_SchemaRepository.php';
require_once __DIR__.'/lib/SchemaOrgData_ScopeResolver.php';
require_once __DIR__.'/lib/SchemaOrgData_JsonLdBuilder.php';
require_once __DIR__.'/lib/SchemaOrgData_IdReferenceService.php';
require_once __DIR__.'/lib/SchemaOrgData_CollisionDetector.php';
require_once __DIR__.'/lib/SchemaOrgData_OpeningHoursHelper.php';
require_once __DIR__.'/lib/SchemaOrgData_DataSplitHelper.php';
require_once __DIR__.'/lib/SchemaOrgData_Validator.php';
require_once __DIR__.'/lib/SchemaOrgData_FormRenderer.php';
require_once __DIR__.'/lib/SchemaOrgData_ImportService.php';
require_once __DIR__.'/lib/SchemaOrgData_AdminController.php';

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
    private const PLUGIN_VERSION = '0.4.30-beta';

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

    /** Lazy-Instanz von SchemaOrgData_UrlHelper (siehe urlHelper()) */
    private ?SchemaOrgData_UrlHelper $urlHelperInstance = null;

    /** Lazy-Instanz von SchemaOrgData_LanguageService (siehe languageService()) */
    private ?SchemaOrgData_LanguageService $languageServiceInstance = null;

    /** Lazy-Instanz von SchemaOrgData_SchemaRepository (siehe schemaRepository()) */
    private ?SchemaOrgData_SchemaRepository $schemaRepositoryInstance = null;

    /** Lazy-Instanz von SchemaOrgData_ScopeResolver (siehe scopeResolver()) */
    private ?SchemaOrgData_ScopeResolver $scopeResolverInstance = null;

    /** Lazy-Instanz von SchemaOrgData_JsonLdBuilder (siehe jsonLdBuilder()) */
    private ?SchemaOrgData_JsonLdBuilder $jsonLdBuilderInstance = null;

    /** Lazy-Instanz von SchemaOrgData_IdReferenceService (siehe idReferenceService()) */
    private ?SchemaOrgData_IdReferenceService $idReferenceServiceInstance = null;

    /** Lazy-Instanz von SchemaOrgData_CollisionDetector (siehe collisionDetector()) */
    private ?SchemaOrgData_CollisionDetector $collisionDetectorInstance = null;

    /** Lazy-Instanz von SchemaOrgData_OpeningHoursHelper (siehe openingHoursHelper()) */
    private ?SchemaOrgData_OpeningHoursHelper $openingHoursHelperInstance = null;

    /** Lazy-Instanz von SchemaOrgData_DataSplitHelper (siehe dataSplitHelper()) */
    private ?SchemaOrgData_DataSplitHelper $dataSplitHelperInstance = null;

    /** Lazy-Instanz von SchemaOrgData_Validator (siehe validator()) */
    private ?SchemaOrgData_Validator $validatorInstance = null;

    /** Lazy-Instanz von SchemaOrgData_FormRenderer (siehe formRenderer()) */
    private ?SchemaOrgData_FormRenderer $formRendererInstance = null;

    /** Lazy-Instanz von SchemaOrgData_ImportService (siehe importService()) */
    private ?SchemaOrgData_ImportService $importServiceInstance = null;

    /** Lazy-Instanz von SchemaOrgData_AdminController (siehe adminController()) */
    private ?SchemaOrgData_AdminController $adminControllerInstance = null;

    function __construct() {
        parent::__construct();
        // Komponenten werden lazy verdrahtet (siehe urlHelper(), languageService(), schemaRepository()).
    }

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

        $this->cms_lang = $this->languageService()->loadCmsLanguageFile(
            $this->PLUGIN_SELF_DIR,
            $this->resolvePluginLanguage($CMS_CONF->get('cmslanguage'))
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
        return $this->scopeResolver()->loadScopeConfig($this->settings, $scope, $cat, $page);
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
        return $this->scopeResolver()->mergeConfigs(...$configs);
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
        return $this->scopeResolver()->resolveTypeInheritance($scopeConfigs);
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
        return $this->schemaRepository()->loadSchema($this->PLUGIN_SELF_DIR, $type);
    }

    /** Lazy-Accessor für SchemaOrgData_UrlHelper. */
    private function urlHelper(): SchemaOrgData_UrlHelper {
        return $this->urlHelperInstance ??= new SchemaOrgData_UrlHelper();
    }

    /** Lazy-Accessor für SchemaOrgData_SchemaRepository. */
    private function schemaRepository(): SchemaOrgData_SchemaRepository {
        return $this->schemaRepositoryInstance ??= new SchemaOrgData_SchemaRepository();
    }

    /** Lazy-Accessor für SchemaOrgData_ScopeResolver. */
    private function scopeResolver(): SchemaOrgData_ScopeResolver {
        return $this->scopeResolverInstance ??= new SchemaOrgData_ScopeResolver();
    }

    /** Lazy-Accessor für SchemaOrgData_JsonLdBuilder. */
    private function jsonLdBuilder(): SchemaOrgData_JsonLdBuilder {
        return $this->jsonLdBuilderInstance ??= new SchemaOrgData_JsonLdBuilder();
    }

    /** Lazy-Accessor für SchemaOrgData_IdReferenceService. */
    private function idReferenceService(): SchemaOrgData_IdReferenceService {
        return $this->idReferenceServiceInstance ??= new SchemaOrgData_IdReferenceService();
    }

    /** Lazy-Accessor für SchemaOrgData_CollisionDetector. */
    private function collisionDetector(): SchemaOrgData_CollisionDetector {
        return $this->collisionDetectorInstance ??= new SchemaOrgData_CollisionDetector();
    }

    /** Lazy-Accessor für SchemaOrgData_OpeningHoursHelper. */
    private function openingHoursHelper(): SchemaOrgData_OpeningHoursHelper {
        return $this->openingHoursHelperInstance ??= new SchemaOrgData_OpeningHoursHelper();
    }

    /** Lazy-Accessor für SchemaOrgData_DataSplitHelper. */
    private function dataSplitHelper(): SchemaOrgData_DataSplitHelper {
        return $this->dataSplitHelperInstance ??= new SchemaOrgData_DataSplitHelper();
    }

    /** Lazy-Accessor für SchemaOrgData_Validator. */
    private function validator(): SchemaOrgData_Validator {
        return $this->validatorInstance ??= new SchemaOrgData_Validator();
    }

    /** Lazy-Accessor für SchemaOrgData_FormRenderer. */
    private function formRenderer(): SchemaOrgData_FormRenderer {
        return $this->formRendererInstance ??= new SchemaOrgData_FormRenderer();
    }

    /** Lazy-Accessor für SchemaOrgData_ImportService. */
    private function importService(): SchemaOrgData_ImportService {
        return $this->importServiceInstance ??= new SchemaOrgData_ImportService();
    }

    /** Lazy-Accessor für SchemaOrgData_AdminController. */
    private function adminController(): SchemaOrgData_AdminController {
        return $this->adminControllerInstance ??= new SchemaOrgData_AdminController();
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
        return $this->urlHelper()->resolveBaseUrl();
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
        return $this->jsonLdBuilder()->resolveNodeId(
            $this->schemaRepository(), $this->urlHelper(), $this->PLUGIN_SELF_DIR, $type, $assignedFragments
        );
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
        return $this->idReferenceService()->resolveAvailableGlobalFragments(
            $this->scopeResolver(), $this->schemaRepository(), $this->settings,
            $this->PLUGIN_SELF_DIR, $this->loadAdminLanguage()
        );
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
        return $this->idReferenceService()->applyDanglingReferenceGuard(
            $this->scopeResolver(), $this->schemaRepository(), $this->settings,
            $this->PLUGIN_SELF_DIR, $scopeConfigs, $globalSuppressedByKeep
        );
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
        return $this->schemaRepository()->getAvailableSchemaTypes($this->PLUGIN_SELF_DIR);
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
        return $this->jsonLdBuilder()->buildJsonLdScript(
            $this->schemaRepository(), $this->urlHelper(), $this->PLUGIN_SELF_DIR,
            $type, $data, $nodeId, $suppressedIdTargets
        );
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
        return $this->jsonLdBuilder()->decodeJsonLdValues($data);
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
        return $this->jsonLdBuilder()->removeEmptyJsonLdProperties($data);
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
    /** Lazy-Accessor für SchemaOrgData_LanguageService. */
    private function languageService(): SchemaOrgData_LanguageService {
        return $this->languageServiceInstance ??= new SchemaOrgData_LanguageService(
            self::LANGUAGE_PREFIX_MAP, self::DEFAULT_LANGUAGE
        );
    }

    private function resolvePluginLanguage(?string $code): string {
        return $this->languageService()->resolvePluginLanguage($code);
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
        return $this->collisionDetector()->extractExistingJsonLdBlocks($html);
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
        global $TEMPLATE_FILE;
        return $this->collisionDetector()->detectExistingJsonLd(
            $html, (string) ($TEMPLATE_FILE ?? '')
        );
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
        return $this->collisionDetector()->extractExistingJsonLdBlocksFromTemplate(
            (string) ($TEMPLATE_FILE ?? '')
        );
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
        global $TEMPLATE_FILE;
        return $this->collisionDetector()->detectExistingJsonLdInTemplate(
            (string) ($TEMPLATE_FILE ?? '')
        );
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
        return $this->collisionDetector()->extractExistingJsonLdBlocksFromTemplateAdmin($CMS_CONF);
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
        global $CMS_CONF;
        return $this->collisionDetector()->detectExistingJsonLdInTemplateAdmin($CMS_CONF);
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
        return $this->scopeResolver()->getScopeSettingsKey($scope, $cat, $page);
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
        return $this->scopeResolver()->sanitizeScopeIdentifier($value);
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
        return $this->scopeResolver()->resolveScopeIdentifiers($scope);
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
        return $this->scopeResolver()->loadScopeMeta($this->settings, $scope, $cat, $page);
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
        $this->scopeResolver()->saveScopeMeta($this->settings, $scope, $meta, $cat, $page);
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
        return $this->importService()->importJsonLd($jsonLdText, $schema, $this->dataSplitHelper());
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
        return $this->validator()->validateAgainstSchema($data, $schema, $this->schemaRepository());
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
            $this->admin_lang = $this->languageService()->loadAdminLanguageFile($this->PLUGIN_SELF_DIR, $this->pluginLang);
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
            $this->weekday_lang = $this->languageService()->loadCmsLanguageFile($this->PLUGIN_SELF_DIR, $this->pluginLang);
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
        return $this->adminController()->renderExistingJsonLdNotice(
            $scope, $cat, $page, $this->loadAdminLanguage(), $this->scopeResolver(), $this->settings
        );
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
        return $this->schemaRepository()->resolveSchemaRef($fieldSchema, $rootSchema);
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
        return $this->dataSplitHelper()->splitDataForRendering($data, $schema);
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
        return $this->openingHoursHelper()->isPerDayOpeningHoursValue($value);
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
        return $this->openingHoursHelper()->parseOpeningHours($openingHours, $days);
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
        return $this->openingHoursHelper()->buildOpeningHoursArray($perDay, $days, $fromKey, $toKey);
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
        return $this->validator()->validatePostalCode($value, $countryCode, $this->loadAdminLanguage());
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
        return $this->validator()->validateTelephone($value, $countryCode, $this->loadAdminLanguage());
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
        return $this->validator()->validateUrl($value, $this->loadAdminLanguage());
    }

    /***************************************************************
    *
    * Validiert eine E-Mail-Adresse via FILTER_VALIDATE_EMAIL.
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    private function validateEmail(string $value): array {
        return $this->validator()->validateEmail($value, $this->loadAdminLanguage());
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
        return $this->validator()->validateOpeningHoursTime($from, $to, $this->loadAdminLanguage());
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
        return $this->validator()->validateGeoCoordinate($value, $min, $max, $errorKey, $this->loadAdminLanguage());
    }

    /** Validiert geo.latitude (-90 .. 90), siehe validateGeoCoordinate(). */
    private function validateGeoLatitude(string $value): array {
        return $this->validator()->validateGeoLatitude($value, $this->loadAdminLanguage());
    }

    /** Validiert geo.longitude (-180 .. 180), siehe validateGeoCoordinate(). */
    private function validateGeoLongitude(string $value): array {
        return $this->validator()->validateGeoLongitude($value, $this->loadAdminLanguage());
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
        return $this->validator()->validateExtensionGeo($extensionData, $this->loadAdminLanguage());
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
        return $this->formRenderer()->renderValidationFeedback($result, $feedbackId);
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
        return $this->formRenderer()->renderRequiredBadge($required, $this->loadAdminLanguage());
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
        return $this->formRenderer()->renderInheritedBadge($originLabel, $this->loadAdminLanguage());
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
        return $this->formRenderer()->renderTextWidget($id, $name, $fieldSchema, $value, $extraAttrs);
    }

    /***************************************************************
    *
    * Rendert ein mehrzeiliges Textfeld (<textarea>).
    *
    ***************************************************************/
    private function renderTextareaWidget(string $id, string $name, array $fieldSchema, mixed $value): string {
        return $this->formRenderer()->renderTextareaWidget($id, $name, $fieldSchema, $value);
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
        return $this->formRenderer()->renderSelectWidget($id, $name, $fieldSchema, $value, $lang, $this->pluginLang);
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
        return $this->formRenderer()->renderIdReferenceOrLiteralWidget(
            $scope, $name, $fieldSchema, $value, $idPrefix,
            $this->loadAdminLanguage(), $this->resolveAvailableGlobalFragments(),
        );
    }

    private function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix = null, ?array $inheritedValue = null, ?string $inheritedLabel = null): string {
        return $this->formRenderer()->renderPostalAddressWidget(
            $scope, $name, $fieldSchema, $value, $idPrefix, $inheritedValue, $inheritedLabel,
            $this->loadAdminLanguage(), $this->validator(), $this->pluginLang,
        );
    }

    /***************************************************************
    *
    * Rendert ein einzelnes Sub-Feld des PostalAddress-Widgets
    * (siehe renderAddressSubField()) als eigenständige
    * schemaOrgData-field-row (Label links, Eingabefeld rechts).
    *
    ***************************************************************/
    private function renderAddressFullRow(array $field): string {
        return $this->formRenderer()->renderAddressFullRow($field);
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
        return $this->formRenderer()->renderAddressSubField(
            $scope, $name, $subName, $subSchema, $value, $countryFieldId, $idPrefix, $inheritedValue, $inheritedLabel,
            $this->loadAdminLanguage(), $this->validator(), $this->pluginLang,
        );
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
        return $this->formRenderer()->renderAddressFieldGroup(
            $scope, $name, $properties, $value, $countryFieldId, $idPrefix, $subNames, $inheritedValue, $inheritedLabel,
            $this->loadAdminLanguage(), $this->validator(), $this->pluginLang,
        );
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
        return $this->formRenderer()->renderOpeningHoursWidget(
            $scope, $name, $fieldSchema, $value, $idPrefix,
            $this->loadAdminLanguage(), $this->loadWeekdayLanguage(), $this->openingHoursHelper(), $this->validator(),
        );
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
        return $this->formRenderer()->renderFaqListWidget($scope, $name, $fieldSchema, $value, $idPrefix, $this->loadAdminLanguage());
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
        return $this->formRenderer()->renderExtensionFieldWidget(
            $scope, $type, $extensionJson, $idPrefix, $this->loadAdminLanguage(), $this->PLUGIN_SELF_URL,
        );
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
        return $this->formRenderer()->buildValidationAttrs($scope, $name, $fieldSchema, $idPrefix, $this->loadAdminLanguage());
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
        return $this->formRenderer()->renderFieldFeedback(
            $name, $fieldSchema, $value, $allData, $feedbackId, $this->validator(), $this->loadAdminLanguage(),
        );
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
        return $this->formRenderer()->renderField(
            $scope, $name, $fieldSchema, $value, $rootSchema, $allData, $idPrefix, $inheritedValue, $inheritedLabel,
            $this->loadAdminLanguage(), $this->schemaRepository(), $this->urlHelper(), $this->pluginLang,
            $this->openingHoursHelper(), $this->validator(), $this->loadWeekdayLanguage(),
            $this->resolveAvailableGlobalFragments(),
        );
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
        return $this->formRenderer()->renderTypeFields(
            $scope, $type, $schema, $data, $idPrefix, $extensionJsonOverride, $inheritable,
            $this->dataSplitHelper(), $this->loadAdminLanguage(), $this->schemaRepository(), $this->urlHelper(),
            $this->pluginLang, $this->PLUGIN_SELF_URL, $this->openingHoursHelper(), $this->validator(),
            $this->loadWeekdayLanguage(), $this->resolveAvailableGlobalFragments(),
        );
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
        return $this->formRenderer()->renderTypeSelector($scope, $availableTypes, $selectedType, $idPrefix, $this->loadAdminLanguage());
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
        return $this->adminController()->renderInfoBlock($scope, $this->loadAdminLanguage());
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
        return $this->scopeResolver()->detectTypeCollision($this->settings, $scope, $cat, $page, $selectedType);
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
        return $this->adminController()->resolveInheritableFields(
            $scope, $cat, $page, $type, $this->loadAdminLanguage(), $this->scopeResolver(), $this->settings
        );
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
        return $this->adminController()->renderCollisionNotice(
            $scope, $cat, $page, $selectedType, $this->loadAdminLanguage(), $this->scopeResolver(), $this->settings
        );
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
        return $this->adminController()->renderExcludedCatsField($excludedCats, $debugOutput, $this->loadAdminLanguage());
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
        return $this->adminController()->renderScopeSelector($selectedCat, $selectedPage, $this->loadAdminLanguage());
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
        return $this->adminController()->buildScopeLabel($scope, $cat, $page, $this->loadAdminLanguage());
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
        return $this->adminController()->buildSaveButtonLabel($selectedCat, $selectedPage, $this->loadAdminLanguage());
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
        return $this->adminController()->renderScopeSection(
            $scope, $cat, $page, $active, $idPrefix, $saveFailed,
            $this->loadAdminLanguage(), $this->scopeResolver(), $this->settings, $this->schemaRepository(),
            $this->PLUGIN_SELF_DIR, $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            $this->pluginLang, $this->PLUGIN_SELF_URL, $this->loadWeekdayLanguage(), $this->idReferenceService(),
            $this->openingHoursHelper(), $this->validator()
        );
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
        return $this->validator()->validateFormData($formData, $schema, $inheritable, $this->loadAdminLanguage(), $this->schemaRepository());
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
        return $this->validator()->isAddressProvided($address, $subProperties);
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
        return $this->validator()->validatePostalAddressData($address, $fieldSchema, $inheritableAddress, $this->loadAdminLanguage());
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
        return $this->validator()->hasFaqEntry($entries);
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
        return $this->adminController()->sanitizePostData(
            $formData, $schema, $this->schemaRepository(), $this->openingHoursHelper(), $this->validator()
        );
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
        return $this->adminController()->sanitizeAddressData($address, $fieldSchema, $this->validator());
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
        return $this->adminController()->saveConfig(
            $scope, $postData, $this->settings, $this->loadAdminLanguage(), $this->scopeResolver(),
            $this->schemaRepository(), $this->PLUGIN_SELF_DIR, $this->validator(), $this->openingHoursHelper()
        );
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
        return $this->scopeResolver()->deleteConfig($this->settings, $scope);
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
        return $this->adminController()->handlePostRequest(
            $this->settings, $this->loadAdminLanguage(), $this->scopeResolver(), $this->schemaRepository(),
            $this->PLUGIN_SELF_DIR, $this->validator(), $this->openingHoursHelper()
        );
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
        return $this->adminController()->renderSaveResultNotice($result, $this->loadAdminLanguage());
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
        return $this->adminController()->getAdminCss();
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
        return $this->adminController()->renderAdminPage(
            $this->settings, $this->loadAdminLanguage(), $this->scopeResolver(), $this->schemaRepository(),
            $this->PLUGIN_SELF_DIR, $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
            $this->pluginLang, $this->PLUGIN_SELF_URL, $this->loadWeekdayLanguage(), $this->idReferenceService(),
            $this->validator(), $this->openingHoursHelper(), $this->collisionDetector()
        );
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
        $this->admin_lang = $this->languageService()->loadAdminLanguageFile($this->PLUGIN_SELF_DIR, $lang);

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
