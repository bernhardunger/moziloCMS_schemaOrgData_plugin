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
    * @param SchemaOrgData_OrgRelationsService $orgRelationsService Organisations-
    *              Relationen (founder/employee/member, siehe README.md,
    *              Abschnitt "Organisations-Identität und @id-Anker")
    * @param SchemaOrgData_OpeningHoursHelper|null $openingHoursHelper wird von
    *              buildJsonLdScript() für den ui:emitAs-Zweig benötigt; optional,
    *              damit bestehende Konstruktionsaufrufe unverändert bleiben
    * @param string $pluginSelfUrl absolute Basis-URL des Plugin-Ordners
    *              (PLUGIN_SELF_URL) als Präfix des src-Attributs ausgelieferter
    *              JS-Assets; der Default hält die Signatur nach einem
    *              optionalen Parameter legal, produktiv setzen ihn alle Aufrufer
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
        public readonly SchemaOrgData_UrlHelper $urlHelper,
        public readonly SchemaOrgData_PersonsRegistryService $personsRegistryService,
        public readonly SchemaOrgData_OrgRelationsService $orgRelationsService,
        public readonly ?SchemaOrgData_OpeningHoursHelper $openingHoursHelper = null,
        public readonly string $pluginSelfUrl = ''
    ) {
    }
}
