<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminRequestHandler
*
* POST/Actions-Dispatch des Admin-Formulars (Fahrplan-Schritt 5, siehe
* doc/adr_ziel_architektur.md): handlePostRequest() verarbeitet die
* $_POST-Daten je Geltungsebene und delegiert an deleteConfig()
* (SchemaOrgData_ScopeResolver) bzw. saveConfig()
* (SchemaOrgData_ConfigSaveService, seit Fahrplan-Schritt 6).
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_ScopeResolver,
* SchemaOrgData_SchemaRepository, SchemaOrgData_Validator,
* SchemaOrgData_OpeningHoursHelper, SchemaOrgData_AdminPageRenderer,
* SchemaOrgData_ConfigSaveService, $this->settings, PLUGIN_SELF_DIR)
* werden je Aufruf als Parameter übergeben, nicht im Konstruktor
* eingefroren (siehe README.md, Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_AdminRequestHandler {

    /***************************************************************
    *
    * Verarbeitet die $_POST-Daten des Admin-Formulars. Für jede
    * übermittelte Geltungsebene (schemaOrgData[global|category|page],
    * siehe SchemaOrgData_AdminController::renderScopeSection()) wird
    * je nach Flag "schemaOrgData_delete_{scope}" deleteConfig() oder
    * saveConfig() aufgerufen.
    *
    * Wird von renderAdminPage() aufgerufen, bevor das Formular
    * gerendert wird, sofern $_POST nicht leer ist.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param SchemaOrgData_AdminPageRenderer $adminPageRenderer wird an saveConfig() durchgereicht
    * @param SchemaOrgData_ConfigSaveService $configSaveService für den saveConfig()-Aufruf
    * @return ?array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function handlePostRequest(
        $settings,
        Language $lang,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_AdminPageRenderer $adminPageRenderer,
        SchemaOrgData_ConfigSaveService $configSaveService
    ): ?array {
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
        $catParam  = $scopeResolver->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_cat']  ?? ''));
        $pageParam = $scopeResolver->sanitizeScopeIdentifier((string) ($_POST['schemaOrgData_page'] ?? ''));
        $isGlobalScope = ($catParam === '' && $pageParam === '');

        if($isGlobalScope) {
            $globalData = (isset($scopes['global']) and is_array($scopes['global']))
                ? $scopes['global'] : [];

            $result = !empty($_POST['schemaOrgData_delete_global'])
                ? $scopeResolver->deleteConfig($settings, 'global')
                : $configSaveService->saveConfig('global', $globalData, $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper, $adminPageRenderer);

            $success = $success && $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        foreach(['category', 'page'] as $scope) {
            $hasData = isset($scopes[$scope]) and is_array($scopes[$scope]);

            if(!$hasData) {
                continue;
            }

            $result = !empty($_POST['schemaOrgData_delete_'.$scope])
                ? $scopeResolver->deleteConfig($settings, $scope)
                : $configSaveService->saveConfig($scope, $scopes[$scope], $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper, $adminPageRenderer);

            $success = $success && $result['success'];
            $errors = array_merge($errors, $result['errors']);
        }

        return ['success' => $success, 'errors' => $errors];
    }
}
