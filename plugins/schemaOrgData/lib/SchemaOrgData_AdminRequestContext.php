<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminRequestContext
*
* Bündelt die Laufzeit-Kollaboratoren und -Parameter, die
* SchemaOrgData_AdminController::renderAdminPage() und
* renderScopeSection() für den Aufbau der Admin-Seite benötigen.
*
***************************************************************/
final class SchemaOrgData_AdminRequestContext {

    /***************************************************************
    *
    * @param mixed $settings moziloCMS-Settings-API-Instanz (bewusst
    *              ohne Type-Hint, siehe SchemaOrgData_ScopeResolver -
    *              kompatibel zu Properties/InMemorySettings-Test-Mocks)
    * @param Language $lang Admin-Sprachinstanz (admin_language_*.txt)
    * @param string $pluginSelfDir Plugin-Verzeichnis (PLUGIN_SELF_DIR)
    * @param string $pluginLang aufgelöstes Locale-Kürzel (z. B. "deDE")
    * @param string $pluginSelfUrl Plugin-Basis-URL (PLUGIN_SELF_URL)
    * @param Language $weekdayLang CMS-Sprachinstanz für Wochentag-Labels
    *              (cms_language_*.txt)
    *
    ***************************************************************/
    public function __construct(
        public readonly mixed $settings,
        public readonly Language $lang,
        public readonly SchemaOrgData_ScopeResolver $scopeResolver,
        public readonly SchemaOrgData_SchemaRepository $schemaRepository,
        public readonly string $pluginSelfDir,
        public readonly SchemaOrgData_FormRenderer $formRenderer,
        public readonly SchemaOrgData_DataSplitHelper $dataSplitHelper,
        public readonly SchemaOrgData_UrlHelper $urlHelper,
        public readonly string $pluginLang,
        public readonly string $pluginSelfUrl,
        public readonly Language $weekdayLang,
        public readonly SchemaOrgData_IdReferenceService $idReferenceService,
        public readonly SchemaOrgData_Validator $validator,
        public readonly SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        public readonly SchemaOrgData_CollisionDetector $collisionDetector,
        public readonly SchemaOrgData_AdminPageRenderer $adminPageRenderer,
        public readonly SchemaOrgData_AdminRequestHandler $adminRequestHandler,
        public readonly SchemaOrgData_ConfigSaveService $configSaveService,
        public readonly SchemaOrgData_ImportService $importService
    ) {
    }
}
