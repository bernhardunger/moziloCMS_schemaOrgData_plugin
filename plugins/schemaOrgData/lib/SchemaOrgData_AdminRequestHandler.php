<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_AdminRequestHandler
*
* POST/Actions-Dispatch des Admin-Formulars: handlePostRequest()
* verarbeitet die $_POST-Daten je Geltungsebene und delegiert an
* deleteConfig() (SchemaOrgData_ScopeResolver) bzw. saveConfig()
* (SchemaOrgData_ConfigSaveService).
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
    * Ist "schemaOrgData_import_action" gesetzt, wird stattdessen
    * ausschließlich der Import verarbeitet (siehe handleImportAction())
    * - kein saveConfig()/deleteConfig() in diesem Request, auch wenn
    * das Formular zusätzlich Felddaten der aktiven Sektion mitsendet.
    *
    * Wird von renderAdminPage() aufgerufen, bevor das Formular
    * gerendert wird, sofern $_POST nicht leer ist.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param SchemaOrgData_AdminPageRenderer $adminPageRenderer wird an saveConfig() durchgereicht
    * @param SchemaOrgData_ConfigSaveService $configSaveService für den saveConfig()-Aufruf
    * @param SchemaOrgData_ImportService $importService für handleImportAction()
    * @param SchemaOrgData_DataSplitHelper $dataSplitHelper für handleImportAction() (importJsonLd())
    * @return ?array{success: bool, errors: string[], import?: bool}
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
        SchemaOrgData_ConfigSaveService $configSaveService,
        SchemaOrgData_ImportService $importService,
        SchemaOrgData_DataSplitHelper $dataSplitHelper
    ): ?array {
        if(isset($_POST['schemaOrgData_import_action'])) {
            return $this->handleImportAction(
                (string) $_POST['schemaOrgData_import_action'],
                $schemaRepository, $pluginSelfDir, $lang, $importService, $dataSplitHelper, $openingHoursHelper
            );
        }

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

    /***************************************************************
    *
    * Verarbeitet den Import-Submit (Button "schemaOrgData_import_action",
    * siehe SchemaOrgData_AdminPageRenderer::renderExistingJsonLdNotice()).
    *
    * Ermittelt den Schema-Type aus dem eingefügten JSON-LD selbst
    * (Henne-Ei-Problem: importJsonLd() benötigt das Schema bereits für
    * die Formular-/Erweiterungsfeld-Trennung, das Schema hängt aber vom
    * @type des Imports ab) und validiert ihn gegen den aktiven Scope,
    * bevor SchemaOrgData_ImportService::importJsonLd() aufgerufen wird
    * (bleibt dadurch unverändert/byte-identisch).
    *
    * Bei Erfolg wird das Ergebnis nach $_POST['schemaOrgData'][$scope]
    * zurückgeschrieben, damit der bestehende Redisplay-Pfad
    * (SchemaOrgData_AdminController::renderScopeSection(), $postScope)
    * das importierte Formular ohne eigenen Mechanismus anzeigt. Die rohe
    * Textarea-Eingabe wird dabei gelöscht, da sie nach erfolgreichem
    * Import leer bleiben soll - das signalisiert renderScopeSection()
    * zugleich eindeutig, dass kein Import-Fehler für diesen Scope vorliegt.
    *
    * openingHours: importJsonLd() liefert openingHours unverändert in der
    * komprimierten schema.org-Notation ("Mo-Th 08:00-12:00"), wie sie
    * auch im importierten JSON-LD steht. Der $_POST-Redisplay-Pfad
    * (renderScopeSection()/renderOpeningHoursWidget()) erwartet dort
    * aber bereits die Pro-Tag-Formularstruktur (dieser Pfad wurde
    * ursprünglich nur für das Redisplay nach fehlgeschlagenem Save
    * gebaut, wo sanitizePostData() erst nach erfolgreicher Validierung
    * komprimiert) - daher wird ein noch komprimierter Wert hier über
    * parseOpeningHours() (dieselbe Methode, die auch beim regulären
    * Rendering aus gespeicherter Config verwendet wird) in die
    * Pro-Tag-Struktur konvertiert, bevor er zurückgeschrieben wird.
    *
    * @param string $rawScope Wert des Import-Buttons ("global"|"category"|"page")
    * @param SchemaOrgData_DataSplitHelper $dataSplitHelper Mapper für importJsonLd()
    * @param SchemaOrgData_OpeningHoursHelper $openingHoursHelper für die openingHours-Konvertierung
    * @return array{success: bool, errors: string[], import: bool}
    *
    ***************************************************************/
    private function handleImportAction(
        string $rawScope,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        Language $lang,
        SchemaOrgData_ImportService $importService,
        SchemaOrgData_DataSplitHelper $dataSplitHelper,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper
    ): array {
        if(!in_array($rawScope, ['global', 'category', 'page'], true)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_json_invalid')], 'import' => true];
        }

        $raw = trim((string) ($_POST['schemaOrgData_import_'.$rawScope] ?? ''));
        if($raw === '') {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_json_invalid')], 'import' => true];
        }

        $decoded = json_decode($raw, true);
        if(json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_json_invalid')], 'import' => true];
        }

        $type = (string) ($decoded['@type'] ?? '');
        $schema = ($type !== '') ? $schemaRepository->loadSchema($pluginSelfDir, $type) : null;

        if($schema === null or !in_array($rawScope, $schema['ui:scopes'] ?? [], true)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_invalid_schema_type', $type)], 'import' => true];
        }

        $result = $importService->importJsonLd($raw, $schema, $dataSplitHelper);

        if(!$result['success']) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_json_invalid')], 'import' => true];
        }

        // openingHours liegt nach dem Import noch in komprimierter
        // schema.org-Notation vor - siehe Docblock oben.
        if(isset($result['formData']['openingHours'])
            and is_array($result['formData']['openingHours'])
            and !$openingHoursHelper->isPerDayOpeningHoursValue($result['formData']['openingHours'])) {
            $fieldSchema = $schema['properties']['openingHours'] ?? [];
            $days = $fieldSchema['ui:days'] ?? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
            $result['formData']['openingHours'] = $openingHoursHelper->parseOpeningHours(
                $result['formData']['openingHours'], $days
            );
        }

        $extensionJson = ($result['extensionData'] === [])
            ? ''
            : json_encode($result['extensionData'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // $_POST-Rückschreibung, siehe Docblock oben.
        $_POST['schemaOrgData'][$rawScope] = [
            'type' => $result['type'],
            'data' => $result['formData'],
            'extension' => [$result['type'] => $extensionJson],
        ];
        unset($_POST['schemaOrgData_import_'.$rawScope]);

        return ['success' => true, 'errors' => [], 'import' => true];
    }
}
