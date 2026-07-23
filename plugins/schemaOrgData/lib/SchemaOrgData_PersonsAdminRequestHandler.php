<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_PersonsAdminRequestHandler
*
* POST-Dispatch für den vierten Admin-Bereich (Personen-Registry,
* siehe SchemaOrgData_PersonsAdminRenderer): wertet den Submit-Button
* "schemaOrgData_persons_action" aus ("create" | "update:<slug>" |
* "delete:<slug>") und delegiert an SchemaOrgData_PersonsRegistryService.
* Vollständig getrennt vom Scope-Formular (SchemaOrgData_AdminRequestHandler)
* - siehe SchemaOrgData_AdminController::renderAdminPage(), das die
* normale Scope-Verarbeitung überspringt, sobald eine Personen-Aktion
* im POST vorliegt (Vorrang analog "schemaOrgData_import_action").
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_Validator,
* SchemaOrgData_PersonsRegistryService, $this->settings) werden je
* Aufruf als Parameter übergeben, nicht im Konstruktor eingefroren
* (siehe README.md, Abschnitt "Entwicklerdokumentation").
*
***************************************************************/
class SchemaOrgData_PersonsAdminRequestHandler {

    /***************************************************************
    *
    * Verarbeitet die $_POST-Daten des Personen-Formulars.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return ?array{success: bool, errors: string[], persons: bool, action: string|null, slug: string|null}
    *         null, wenn kein "schemaOrgData_persons_action" im POST vorliegt
    *
    ***************************************************************/
    public function handlePersonsPostRequest(
        $settings,
        Language $lang,
        SchemaOrgData_PersonsRegistryService $registryService,
        SchemaOrgData_Validator $validator
    ): ?array {
        if(!isset($_POST['schemaOrgData_persons_action'])) {
            return null;
        }

        $raw = (string) $_POST['schemaOrgData_persons_action'];
        $rawData = is_array($_POST['schemaOrgData_persons_data'] ?? null) ? $_POST['schemaOrgData_persons_data'] : [];

        if($raw === 'create') {
            $result = $registryService->createPerson($settings, $rawData, $lang, $validator);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => 'create', 'slug' => $result['slug']];
        }

        if(str_starts_with($raw, 'update:')) {
            $slug = substr($raw, strlen('update:'));
            $result = $registryService->updatePerson($settings, $slug, $rawData, $lang, $validator);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => 'update', 'slug' => $slug];
        }

        if(str_starts_with($raw, 'delete:')) {
            $slug = substr($raw, strlen('delete:'));
            $result = $registryService->deletePerson($settings, $slug);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => 'delete', 'slug' => $slug];
        }

        return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_invalid_action')], 'persons' => true, 'action' => null, 'slug' => null];
    }
}
