<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_FrontendRequestContext
*
* Bündelt die Laufzeit-Kollaboratoren und -Parameter, die
* SchemaOrgData_FrontendRenderer::renderFrontend() für den
* Aufbau der Frontend-Ausgabepipeline benötigt.
*
* Enthält bewusst nicht den Seiteninhalt ($value) - dieser ist
* Methoden-Input für die Kollisionserkennung, kein Laufzeit-
* Kontext, und bleibt daher ein separater Parameter.
*
***************************************************************/
final class SchemaOrgData_FrontendRequestContext {

    /***************************************************************
    *
    * @param mixed $settings moziloCMS-Settings-API-Instanz (bewusst
    *              ohne Type-Hint, siehe SchemaOrgData_ScopeResolver -
    *              kompatibel zu Properties/InMemorySettings-Test-Mocks)
    * @param string $pluginSelfDir Plugin-Verzeichnis (PLUGIN_SELF_DIR)
    *
    ***************************************************************/
    public function __construct(
        public readonly mixed $settings,
        public readonly string $pluginSelfDir,
        public readonly SchemaOrgData_ScopeResolver $scopeResolver,
        public readonly SchemaOrgData_SchemaRepository $schemaRepository,
        public readonly SchemaOrgData_JsonLdBuilder $jsonLdBuilder,
        public readonly SchemaOrgData_IdReferenceService $idReferenceService,
        public readonly SchemaOrgData_CollisionDetector $collisionDetector,
        public readonly SchemaOrgData_UrlHelper $urlHelper
    ) {
    }
}
