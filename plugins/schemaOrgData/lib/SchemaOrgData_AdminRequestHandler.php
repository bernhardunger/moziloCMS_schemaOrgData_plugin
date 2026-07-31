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
* eingefroren (siehe README.md, Abschnitt "Entwicklerdokumentation").
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
    * @param SchemaOrgData_PersonsRegistryService $personsRegistryService wird an saveConfig() durchgereicht
    * @param SchemaOrgData_OrgRelationsService $orgRelationsService wird an saveConfig() durchgereicht
    * Neben "errors" wird "notices" über alle verarbeiteten Ebenen
    * gefaltet (nicht blockierende Hinweise auf bereinigte oder
    * verworfene Eingaben, siehe
    * SchemaOrgData_ConfigSaveService::saveConfig()). Wurde in einem
    * Request mehr als eine Ebene verarbeitet, werden beide Listen je
    * Ebene mit deren Bezeichnung versehen (buildScopeLabel()) - sonst
    * ließe sich eine Meldung keiner Ebene mehr zuordnen. Im Regelfall
    * (genau eine Ebene) bleibt die Meldung unverändert schlank.
    *
    * @param SchemaOrgData_PersonSuggestionService $personSuggestionService für handleAcceptPersonSuggestion()
    * @return ?array{success: bool, errors: string[], notices: string[], import?: bool}
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
        SchemaOrgData_DataSplitHelper $dataSplitHelper,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        SchemaOrgData_OrgRelationsService $orgRelationsService,
        SchemaOrgData_PersonSuggestionService $personSuggestionService
    ): ?array {
        if(isset($_POST['schemaOrgData_import_action'])) {
            return $this->withNotices($this->handleImportAction(
                (string) $_POST['schemaOrgData_import_action'],
                $settings, $scopeResolver,
                $schemaRepository, $pluginSelfDir, $lang, $importService, $dataSplitHelper, $openingHoursHelper
            ));
        }

        if(isset($_POST['schemaOrgData_accept_person_suggestion'])) {
            return $this->withNotices($this->handleAcceptPersonSuggestion(
                (string) $_POST['schemaOrgData_accept_person_suggestion'],
                $settings, $scopeResolver, $schemaRepository, $pluginSelfDir, $lang,
                $personSuggestionService, $personsRegistryService, $orgRelationsService, $validator,
                $configSaveService, $openingHoursHelper, $adminPageRenderer
            ));
        }

        $scopes = $_POST['schemaOrgData'] ?? null;

        // Keine schemaOrgData-Formulardaten im POST - kein Speichervorgang,
        // kein Ergebnis zurückgeben (verhindert falsche Erfolgsmeldung).
        if(!is_array($scopes)) {
            return null;
        }

        $success = true;

        // Die Ergebnisse werden zunächst je Ebene getrennt gesammelt: ob
        // ihre Meldungen ein Scope-Label brauchen, steht erst fest, wenn
        // alle Ebenen dieses Requests durch sind.
        $processed = [];

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
                ? $scopeResolver->deleteConfig($settings, 'global', $lang)
                : $configSaveService->saveConfig('global', $globalData, $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper, $adminPageRenderer, $personsRegistryService, $orgRelationsService);

            $success = $success && $result['success'];
            $processed[] = ['scope' => 'global', 'cat' => null, 'page' => null, 'result' => $result];
        }

        foreach(['category', 'page'] as $scope) {
            // Klammerung zwingend: "=" bindet stärker als "and", ohne sie
            // würde nur das isset()-Ergebnis zugewiesen und ein manipulierter
            // POST mit skalarem Scope-Wert lief unten in einen TypeError von
            // saveConfig(array $postData).
            $hasData = (isset($scopes[$scope]) and is_array($scopes[$scope]));

            if(!$hasData) {
                continue;
            }

            $result = !empty($_POST['schemaOrgData_delete_'.$scope])
                ? $scopeResolver->deleteConfig($settings, $scope, $lang)
                : $configSaveService->saveConfig($scope, $scopes[$scope], $settings, $lang, $scopeResolver, $schemaRepository, $pluginSelfDir, $validator, $openingHoursHelper, $adminPageRenderer, $personsRegistryService, $orgRelationsService);

            $success = $success && $result['success'];
            $processed[] = [
                'scope'  => $scope,
                'cat'    => ($catParam !== '') ? $catParam : null,
                'page'   => ($pageParam !== '') ? $pageParam : null,
                'result' => $result,
            ];
        }

        $errors = [];
        $notices = [];

        // Nur bei mehr als einer verarbeiteten Ebene wird das Label
        // vorangestellt - im Regelfall ist die Zuordnung eindeutig und ein
        // Präfix wäre nur Ballast. deleteConfig() liefert kein "notices"
        // (ein Löschvorgang bereinigt nichts), deshalb der Rückfall.
        $labelPerScope = (count($processed) > 1);

        foreach($processed as $entry) {
            $label = $labelPerScope
                ? $adminPageRenderer->buildScopeLabel($entry['scope'], $entry['cat'], $entry['page'], $lang)
                : null;

            foreach($entry['result']['errors'] as $error) {
                $errors[] = ($label !== null) ? $label.': '.$error : $error;
            }

            foreach($entry['result']['notices'] ?? [] as $notice) {
                $notices[] = ($label !== null) ? $label.': '.$notice : $notice;
            }
        }

        return ['success' => $success, 'errors' => $errors, 'notices' => $notices];
    }

    /***************************************************************
    *
    * Ergänzt ein Ergebnis der Sonderpfade (Import, Personen-Vorschlag)
    * um "notices", sofern es den Schlüssel nicht selbst führt - die
    * Ergebnisform von handlePostRequest() ist damit unabhängig davon,
    * welcher Zweig sie erzeugt hat. Ein bereits vorhandener Wert bleibt
    * erhalten.
    *
    * @param array<string, mixed> $result
    * @return array<string, mixed>
    *
    ***************************************************************/
    private function withNotices(array $result): array {
        return $result + ['notices' => []];
    }

    /***************************************************************
    *
    * Verarbeitet den Import-Submit (Button "schemaOrgData_import_action",
    * siehe SchemaOrgData_AdminPageRenderer::renderExistingJsonLdNotice()).
    *
    * Der Button-Value trägt "{scope}" oder "{scope}:{blockIndex}"; der
    * zu importierende Rohtext wird serverseitig aus der persistierten
    * Scope-Meta gelesen (existing_jsonld_blocks[$index], siehe
    * SchemaOrgData_ScopeResolver::loadScopeMeta() inkl. Legacy-
    * Normalisierung) - es gibt kein Import-Textarea und keinen
    * Client-Roundtrip mehr. handlePostRequest() läuft vor der
    * Template-Live-Erkennung in renderAdminPage(), die Meta entspricht
    * daher exakt dem Stand, der dem Nutzer beim Klick angezeigt wurde.
    *
    * Ermittelt den Schema-Type aus dem Block selbst (Henne-Ei-Problem:
    * importJsonLd() benötigt das Schema bereits für die Formular-/
    * Erweiterungsfeld-Trennung, das Schema hängt aber vom @type ab)
    * und validiert ihn gegen den aktiven Scope, bevor
    * SchemaOrgData_ImportService::importJsonLd() aufgerufen wird
    * (bleibt dadurch unverändert/byte-identisch).
    *
    * Bei Erfolg wird das Ergebnis nach $_POST['schemaOrgData'][$scope]
    * zurückgeschrieben, damit der bestehende Redisplay-Pfad
    * (SchemaOrgData_AdminController::renderScopeSection(), $postScope)
    * das importierte Formular ohne eigenen Mechanismus anzeigt.
    *
    * openingHours: importJsonLd() liefert openingHours in komprimierter
    * schema.org-Notation ("Mo-Th 08:00-12:00") - der $_POST-Redisplay-
    * Pfad erwartet dort die Pro-Tag-Formularstruktur, daher Konvertierung
    * über parseOpeningHours() (dieselbe Methode wie beim regulären
    * Rendering aus gespeicherter Config).
    *
    * Scope-Identifier für category/page stammen aus den bereits im
    * Formular vorhandenen Feldern schemaOrgData_cat/schemaOrgData_page
    * (sanitizeScopeIdentifier(), analog renderAdminPage()).
    *
    * @param string $rawAction Wert des Import-Buttons ("{scope}" | "{scope}:{index}")
    * @return array{success: bool, errors: string[], import: bool}
    *
    ***************************************************************/
    private function handleImportAction(
        string $rawAction,
        $settings,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        Language $lang,
        SchemaOrgData_ImportService $importService,
        SchemaOrgData_DataSplitHelper $dataSplitHelper,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper
    ): array {
        $parts = explode(':', $rawAction, 2);
        $rawScope = $parts[0];
        $rawIndex = $parts[1] ?? '0';

        if(!in_array($rawScope, ['global', 'category', 'page'], true) or !ctype_digit($rawIndex)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_json_invalid')], 'import' => true];
        }
        $blockIndex = (int) $rawIndex;

        // Scope-Identifier analog renderAdminPage(): für category/page aus
        // den ohnehin mitgesendeten Formularfeldern.
        $cat  = ($rawScope !== 'global' and isset($_POST['schemaOrgData_cat']))
            ? ($scopeResolver->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_cat']) ?: null) : null;
        $page = ($rawScope === 'page' and isset($_POST['schemaOrgData_page']))
            ? ($scopeResolver->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_page']) ?: null) : null;

        $meta = $scopeResolver->loadScopeMeta($settings, $rawScope, $cat, $page);
        $blocks = $meta['existing_jsonld_blocks'];

        if(!isset($blocks[$blockIndex])) {
            // Meta veraltet (Template/Seiteninhalt zwischenzeitlich geändert)
            // oder Legacy-Mehrblock-Konkatenat vor Neuerkennung.
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_import_block_not_found')], 'import' => true];
        }

        $raw = trim((string) $blocks[$blockIndex]);
        $decoded = json_decode($raw, true);
        if($raw === '' or json_last_error() !== JSON_ERROR_NONE or !is_array($decoded)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_detected_block_invalid')], 'import' => true];
        }

        $rawType = $decoded['@type'] ?? '';

        // "@type" darf in JSON-LD eine Liste sein und ist das in
        // Fremd-Blöcken auch. Ein Cast darauf erzeugt eine PHP-Warnung
        // ("Array to string conversion") und den Wert "Array"; abgelehnt
        // wird stattdessen mit eigener Meldung. Einen Type aus der Liste
        // zu wählen wäre eine stille Bedeutungsänderung des importierten
        // Blocks. Die Meldung deckt auch sonstige Nicht-String-Werte ab,
        // die gleichfalls keinen einzelnen Type bezeichnen.
        if(!is_string($rawType)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_import_multivalue_type')], 'import' => true];
        }

        $type = $rawType;
        $schema = ($type !== '') ? $schemaRepository->loadSchema($pluginSelfDir, $type) : null;

        if($schema === null or !in_array($rawScope, $schema['ui:scopes'] ?? [], true)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_invalid_schema_type', $type)], 'import' => true];
        }

        $result = $importService->importJsonLd($raw, $schema, $dataSplitHelper);

        if(!$result['success']) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_detected_block_invalid')], 'import' => true];
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

        return ['success' => true, 'errors' => [], 'import' => true];
    }

    /***************************************************************
    *
    * Verarbeitet den Submit-Button "schemaOrgData_accept_person_suggestion"
    * (siehe SchemaOrgData_PersonSuggestionService::renderSuggestionNotice()):
    * der Button-Value trägt ausschließlich den Property-Namen
    * ("employee"/"founder"/"member") - der betroffene Type wird hier aus
    * dem aktuell aktiven globalen Organisations-Identity-Type ermittelt
    * (der Vorschlag erscheint ausschließlich innerhalb dessen Formular-
    * Sektion, siehe SchemaOrgData_AdminController::renderScopeSection()).
    * Kein saveConfig() in diesem Request, auch wenn das Formular
    * zusätzlich Felddaten der aktiven Sektion mitsendet (analog zum
    * Import-Dispatch) - außer im Post-Import-Redisplay-Fall (siehe unten).
    *
    * Trägt das Marker-Hidden-Feld "schemaOrgData_person_suggestion_from_import"
    * (siehe renderSuggestionNotice()) mit im POST, wird die globale Sektion
    * zunächst implizit gespeichert, bevor acceptSuggestion() aufgerufen wird -
    * direkt nach einem Import liegt "employee"/"founder"/"member" noch nicht
    * in der persistierten Konfiguration vor, sondern nur im POST-Redisplay.
    * Ohne dieses Marker-Feld bleibt der bestehende Vorrang-Schutz unverändert
    * bestehen (kein implizites Speichern mitgesendeter, nicht gespeicherter
    * Formulardaten im Normalfall).
    *
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    private function handleAcceptPersonSuggestion(
        string $property,
        $settings,
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepository,
        string $pluginSelfDir,
        Language $lang,
        SchemaOrgData_PersonSuggestionService $personSuggestionService,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        SchemaOrgData_OrgRelationsService $orgRelationsService,
        SchemaOrgData_Validator $validator,
        SchemaOrgData_ConfigSaveService $configSaveService,
        SchemaOrgData_OpeningHoursHelper $openingHoursHelper,
        SchemaOrgData_AdminPageRenderer $adminPageRenderer
    ): array {
        $fromImport = !empty($_POST['schemaOrgData_person_suggestion_from_import']);
        $globalPostData = is_array($_POST['schemaOrgData']['global'] ?? null) ? $_POST['schemaOrgData']['global'] : null;

        // Das implizite Speichern durchläuft denselben Bereinigungspfad wie
        // ein regulärer Submit - seine Hinweise gehören deshalb ins
        // Endergebnis, sonst gingen sie in genau diesem Sonderfall verloren.
        $carriedNotices = [];

        if($fromImport and $globalPostData !== null) {
            $saveResult = $configSaveService->saveConfig(
                'global', $globalPostData, $settings, $lang, $scopeResolver, $schemaRepository,
                $pluginSelfDir, $validator, $openingHoursHelper, $adminPageRenderer,
                $personsRegistryService, $orgRelationsService
            );
            if(!$saveResult['success']) {
                return ['success' => false, 'errors' => $saveResult['errors'], 'notices' => []];
            }
            $carriedNotices = $saveResult['notices'];
        }

        $config = $scopeResolver->loadScopeConfig($settings, 'global');
        $type = $schemaRepository->resolveActiveType($config, $pluginSelfDir);
        $schema = ($type !== null) ? $schemaRepository->loadSchema($pluginSelfDir, $type) : null;

        if($schema === null or ($schema['ui:idFragment'] ?? '') !== 'organization') {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_suggestion_outdated')], 'notices' => []];
        }

        $result = $personSuggestionService->acceptSuggestion(
            $property, $config, $type, $settings, $personsRegistryService, $orgRelationsService, $lang, $validator
        );

        $result['notices'] = $result['success'] ? $carriedNotices : [];

        return $result;
    }
}
