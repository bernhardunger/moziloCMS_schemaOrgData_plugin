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

    /**
     * Zwei getrennte Vokabulare rund um die Personen-Aktion.
     *
     * Wire-Protokoll - die Werte, die der Submit-Button
     * schemaOrgData_persons_action liefert: ACTION_CREATE ist ein
     * vollständiger Wert, PREFIX_UPDATE und PREFIX_DELETE sind
     * Präfixe, denen der Slug der betroffenen Person folgt.
     *
     * Ergebnis-Vokabular - der Wert des Schlüssels action im
     * Rückgabearray von handlePersonsPostRequest(), konsumiert von
     * SchemaOrgData_AdminController. Dass ACTION_CREATE und
     * RESULT_CREATE denselben Wert tragen, ist Entwurf und keine
     * Abhängigkeit: der Wire-Wert des Anlegens braucht keinen Slug,
     * also fällt er mit dem Ergebniswert zusammen - beim Aktualisieren
     * und Löschen tut er es nicht.
     */
    public const ACTION_CREATE = 'create';
    public const PREFIX_UPDATE = 'update:';
    public const PREFIX_DELETE = 'delete:';

    public const RESULT_CREATE = 'create';
    public const RESULT_UPDATE = 'update';
    public const RESULT_DELETE = 'delete';

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

        if($raw === self::ACTION_CREATE) {
            $result = $registryService->createPerson($settings, $rawData, $lang, $validator);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => self::RESULT_CREATE, 'slug' => $result['slug']];
        }

        if(str_starts_with($raw, self::PREFIX_UPDATE)) {
            $slug = substr($raw, strlen(self::PREFIX_UPDATE));
            $result = $registryService->updatePerson($settings, $slug, $rawData, $lang, $validator);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => self::RESULT_UPDATE, 'slug' => $slug];
        }

        if(str_starts_with($raw, self::PREFIX_DELETE)) {
            $slug = substr($raw, strlen(self::PREFIX_DELETE));
            $result = $registryService->deletePerson($settings, $slug, $lang);
            return ['success' => $result['success'], 'errors' => $result['errors'], 'persons' => true, 'action' => self::RESULT_DELETE, 'slug' => $slug];
        }

        return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_invalid_action')], 'persons' => true, 'action' => null, 'slug' => null];
    }
}
