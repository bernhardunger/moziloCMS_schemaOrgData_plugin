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
require_once __DIR__.'/lib/SchemaOrgData_AdminPageRenderer.php';
require_once __DIR__.'/lib/SchemaOrgData_AdminRequestHandler.php';
require_once __DIR__.'/lib/SchemaOrgData_ConfigSaveService.php';
require_once __DIR__.'/lib/SchemaOrgData_ValidationResult.php';
require_once __DIR__.'/lib/SchemaOrgData_FrontendRenderer.php';
require_once __DIR__.'/lib/SchemaOrgData_FrontendRequestContext.php';
require_once __DIR__.'/lib/SchemaOrgData_AdminRequestContext.php';

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
    private const PLUGIN_VERSION = '0.9.23-rc';

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

    /** Lazy-Instanz von SchemaOrgData_AdminController (siehe adminController()) */
    private ?SchemaOrgData_AdminController $adminControllerInstance = null;

    /** Lazy-Instanz von SchemaOrgData_AdminPageRenderer (siehe adminPageRenderer()) */
    private ?SchemaOrgData_AdminPageRenderer $adminPageRendererInstance = null;

    /** Lazy-Instanz von SchemaOrgData_AdminRequestHandler (siehe adminRequestHandler()) */
    private ?SchemaOrgData_AdminRequestHandler $adminRequestHandlerInstance = null;

    /** Lazy-Instanz von SchemaOrgData_ConfigSaveService (siehe configSaveService()) */
    private ?SchemaOrgData_ConfigSaveService $configSaveServiceInstance = null;

    /** Lazy-Instanz von SchemaOrgData_ImportService (siehe importService()) */
    private ?SchemaOrgData_ImportService $importServiceInstance = null;

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
                new SchemaOrgData_AdminRequestContext(
                    $this->settings, $this->loadAdminLanguage(), $this->scopeResolver(), $this->schemaRepository(),
                    $this->PLUGIN_SELF_DIR, $this->formRenderer(), $this->dataSplitHelper(), $this->urlHelper(),
                    $this->pluginLang, $this->PLUGIN_SELF_URL, $this->loadWeekdayLanguage(), $this->idReferenceService(),
                    $this->validator(), $this->openingHoursHelper(), $this->collisionDetector(),
                    $this->adminPageRenderer(), $this->adminRequestHandler(), $this->configSaveService(),
                    $this->importService()
                )
            );
        }

        return $this->frontendRenderer()->renderFrontend(
            $value,
            new SchemaOrgData_FrontendRequestContext(
                $this->settings, $this->PLUGIN_SELF_DIR, $this->scopeResolver(),
                $this->schemaRepository(), $this->jsonLdBuilder(), $this->idReferenceService(),
                $this->collisionDetector(), $this->urlHelper()
            )
        );
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

    /** Lazy-Accessor für SchemaOrgData_AdminController. */
    private function adminController(): SchemaOrgData_AdminController {
        return $this->adminControllerInstance ??= new SchemaOrgData_AdminController();
    }

    /** Lazy-Accessor für SchemaOrgData_AdminPageRenderer. */
    private function adminPageRenderer(): SchemaOrgData_AdminPageRenderer {
        return $this->adminPageRendererInstance ??= new SchemaOrgData_AdminPageRenderer();
    }

    /** Lazy-Accessor für SchemaOrgData_AdminRequestHandler. */
    private function adminRequestHandler(): SchemaOrgData_AdminRequestHandler {
        return $this->adminRequestHandlerInstance ??= new SchemaOrgData_AdminRequestHandler();
    }

    /** Lazy-Accessor für SchemaOrgData_ConfigSaveService. */
    private function configSaveService(): SchemaOrgData_ConfigSaveService {
        return $this->configSaveServiceInstance ??= new SchemaOrgData_ConfigSaveService();
    }

    /** Lazy-Accessor für SchemaOrgData_ImportService. */
    private function importService(): SchemaOrgData_ImportService {
        return $this->importServiceInstance ??= new SchemaOrgData_ImportService();
    }

    /** Lazy-Accessor für SchemaOrgData_FrontendRenderer. */
    private function frontendRenderer(): SchemaOrgData_FrontendRenderer {
        return $this->frontendRendererInstance ??= new SchemaOrgData_FrontendRenderer();
    }

    /** Lazy-Accessor für SchemaOrgData_LanguageService. */
    private function languageService(): SchemaOrgData_LanguageService {
        return $this->languageServiceInstance ??= new SchemaOrgData_LanguageService(
            self::LANGUAGE_PREFIX_MAP, self::DEFAULT_LANGUAGE
        );
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
            // moziloCMS-Version — konsistent mit Core-Plugins (Breadcrumb, Contact);
            // '2.0 / 3.0' bleibt Kompatibilitätsangabe, bis der Core-eigene
            // Versionsstring korrigiert ist
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
