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
require_once __DIR__.'/lib/SchemaOrgData_FrontendRenderer.php';

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
    private const PLUGIN_VERSION = '0.4.33-beta';

    /** Standard-Sprache, falls die CMS-/Admin-Sprache nicht unterstützt wird */
    private const DEFAULT_LANGUAGE = 'deDE';

    /** Explizite Zuordnung: 2-Zeichen-Prefix → Locale-Code. */
    private const LANGUAGE_PREFIX_MAP = [
        'de' => 'deDE',
        'en' => 'enEN',
    ];

    /** Sprachobjekt für Admin-UI (sprachen/admin_language_{lang}.txt) */
    private ?Language $admin_lang = null;

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

    /** Lazy-Instanz von SchemaOrgData_FrontendRenderer (siehe frontendRenderer()) */
    private ?SchemaOrgData_FrontendRenderer $frontendRendererInstance = null;

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
            return $this->adminController()->renderAdminPage(
                $this->settings, $this->loadAdminLanguage(), $this->scopeResolver(), $this->schemaRepository(),
                $this->PLUGIN_SELF_DIR, $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
                $this->pluginLang, $this->PLUGIN_SELF_URL, $this->loadWeekdayLanguage(), $this->idReferenceService(),
                $this->validator(), $this->openingHoursHelper(), $this->collisionDetector()
            );
        }

        return $this->frontendRenderer()->renderFrontend(
            $value, $this->settings, $this->PLUGIN_SELF_DIR,
            $this->scopeResolver(), $this->schemaRepository(), $this->jsonLdBuilder(),
            $this->idReferenceService(), $this->collisionDetector(), $this->urlHelper()
        );
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

    /** Lazy-Accessor für SchemaOrgData_FrontendRenderer. */
    private function frontendRenderer(): SchemaOrgData_FrontendRenderer {
        return $this->frontendRendererInstance ??= new SchemaOrgData_FrontendRenderer();
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

    /** Lazy-Accessor für SchemaOrgData_LanguageService. */
    private function languageService(): SchemaOrgData_LanguageService {
        return $this->languageServiceInstance ??= new SchemaOrgData_LanguageService(
            self::LANGUAGE_PREFIX_MAP, self::DEFAULT_LANGUAGE
        );
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
        $this->pluginLang = $this->languageService()->resolvePluginLanguage($ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE);

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
    private function renderPostalAddressWidget(string $scope, string $name, array $fieldSchema, array $value, ?string $idPrefix = null, ?array $inheritedValue = null, ?string $inheritedLabel = null): string {
        return $this->formRenderer()->renderPostalAddressWidget(
            $scope, $name, $fieldSchema, $value, $idPrefix, $inheritedValue, $inheritedLabel,
            $this->loadAdminLanguage(), $this->validator(), $this->pluginLang,
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

        $lang = $this->languageService()->resolvePluginLanguage($ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE);
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
