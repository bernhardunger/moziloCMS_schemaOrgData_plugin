<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_OrgRelationsService
*
* Organisations-Relationen (founder/employee/member) zwischen der
* globalen Organisations-Identität (jeder Type mit
* "ui:idFragment": "organization", siehe README.md, Abschnitt
* "Organisations-Identität und @id-Anker") und Registry-Personen. Gespeichert
* als eigenständige Liste (Settings-Key "config_global", Property
* "org_relations") unabhängig vom aktiven globalen Schema-Type, damit
* ein Type-Wechsel innerhalb der LocalBusiness-Familie bzw. zwischen
* Organization/NGO die Relationen nicht verliert.
*
* Zustandslos: Kollaboratoren ($settings, SchemaOrgData_PersonsRegistryService,
* SchemaOrgData_UrlHelper) werden je Aufruf als Parameter übergeben, nicht im
* Konstruktor eingefroren (siehe README.md, Abschnitt "Entwicklerdokumentation").
*
***************************************************************/
class SchemaOrgData_OrgRelationsService {

    /**
     * Bindung an die Verwaltungsschlüssel aus
     * SchemaOrgData_ScopeResolver - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const KEY_ORG_RELATIONS = SchemaOrgData_ScopeResolver::KEY_ORG_RELATIONS;

    public const ROLE_FOUNDER  = 'founder';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_MEMBER   = 'member';

    private const ROLES = [self::ROLE_FOUNDER, self::ROLE_EMPLOYEE, self::ROLE_MEMBER];

    /***************************************************************
    *
    * Liste der zulässigen Rollen (Widget-Dropdown, Whitelist-Prüfung).
    *
    * @return string[]
    *
    ***************************************************************/
    public static function roles(): array {
        return self::ROLES;
    }

    /***************************************************************
    *
    * Bereinigt und validiert die POST-Rohdaten des Relationen-Widgets
    * (indizierte Zeilen, analog SchemaOrgData_FormRenderer::renderFaqListWidget()):
    * je Zeile ein Personen-Slug und eine Rolle. Zeilen ohne Personen-Slug
    * werden verworfen: die stets mitgesendete leere Anlege-Zeile
    * kommentarlos (sie ist kein Verlust, sondern Normalbetrieb), eine
    * Zeile mit nicht-skalarem person-Teilwert (siehe scalarRowValue())
    * dagegen mit einem nicht blockierenden Hinweis in "notices" - dort
    * war etwas gesendet worden, das nicht übernommen werden konnte.
    * Ist ein Slug gesetzt, wird sowohl die Rolle gegen die
    * Whitelist (ROLES) als auch der Slug gegen die Personen-Registry
    * geprüft (SchemaOrgData_PersonsRegistryService::slugExists()) - beide
    * Prüfungen erzeugen bei Fehlschlag eine Fehlermeldung und blockieren
    * das Speichern (siehe SchemaOrgData_ConfigSaveService::saveConfig()).
    *
    * @param array<int, array{person?: mixed, role?: mixed}> $rawRows
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array{relations: array<int, array{person: string, role: string}>, errors: string[], notices: array<int, array{field: string, kind: string}>}
    *
    ***************************************************************/
    public function sanitizeAndValidate(
        array $rawRows,
        $settings,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        Language $lang
    ): array {
        $relations = [];
        $errors = [];
        $notices = [];

        foreach($rawRows as $row) {
            if(!is_array($row)) {
                continue;
            }

            $rawPerson = $row['person'] ?? null;
            $slug = trim(strip_tags($this->scalarRowValue($rawPerson)));
            if($slug === '') {
                // Ein nicht-skalarer Teilwert kommt hier als Leerstring an
                // und sähe damit aus wie die leere Anlege-Zeile - gesendet
                // wurde aber etwas, das nicht übernommen werden konnte.
                if($rawPerson !== null and !is_scalar($rawPerson)) {
                    $notices[] = ['field' => self::KEY_ORG_RELATIONS, 'kind' => 'dropped'];
                }
                continue;
            }

            $role = trim(strip_tags($this->scalarRowValue($row['role'] ?? null)));

            if(!in_array($role, self::ROLES, true)) {
                $errors[] = $lang->getLanguageValue('error_org_relation_invalid_role', $role);
                continue;
            }

            if(!$personsRegistryService->slugExists($settings, $slug)) {
                $errors[] = $lang->getLanguageValue('error_org_relation_unknown_person', $slug);
                continue;
            }

            $relations[] = ['person' => $slug, 'role' => $role];
        }

        return ['relations' => $relations, 'errors' => $errors, 'notices' => $notices];
    }

    /***************************************************************
    *
    * Liefert einen Teilwert einer Relationen-Zeile als String - einen
    * nicht-skalaren Wert (untergeschobenes Array/Objekt) jedoch als
    * Leerstring, also so, als wäre das Feld gar nicht gesendet worden.
    * Idiom-Gegenstück zum is_scalar()-Guard in
    * SchemaOrgData_ConfigSaveService::sanitizePostData(), der dort die
    * Feldschleife per continue überspringt. Die is_array()-Prüfung in
    * sanitizeAndValidate() schützt nur die Zeile selbst, nicht ihre
    * Felder; ohne diesen Guard erzeugte der Cast das Ersatzliteral
    * "Array" (plus PHP-Warnung) und daraus eine Fehlermeldung, die den
    * eigentlichen Grund nicht nennt.
    *
    ***************************************************************/
    private function scalarRowValue(mixed $value): string {
        return is_scalar($value) ? (string) $value : '';
    }

    /***************************************************************
    *
    * Baut die nach Rolle gruppierten @id-Referenzen für die JSON-LD-
    * Ausgabe des Organisations-Knotens (siehe README.md,
    * "Organisations-Identität und @id-Anker"): je Rolle ein Array von {"@id": "..."}-
    * Objekten, bereit zum direkten Merge in die Properties des Knotens.
    *
    * Zwei Filter greifen unabhängig voneinander:
    * - Dangling (Existenz): eine Relation zu einem inzwischen gelöschten
    *   Slug wird übersprungen - erkennbar daran, dass
    *   SchemaOrgData_IdReferenceService::applyDanglingReferenceGuard()
    *   das zugehörige "person-{slug}"-Fragment bereits in
    *   $suppressedIdTargets aufgenommen hat (dieselbe Prüfung wie für
    *   id_reference(_or_literal)-Felder, siehe dort).
    * - Status (Sichtbarkeit): anders als bei id_reference_or_literal
    *   (dort bleibt der Status für die Ausgabe irrelevant, siehe
    *   applyDanglingReferenceGuard()) wird eine Relation zu einer
    *   inaktiv gesetzten Person hier NICHT ausgegeben - sie bleibt
    *   aber gespeichert und erscheint nach Reaktivierung automatisch
    *   wieder (SRD Abschnitt 4.6). Bewusster Kontrast zur AP2-Statuslogik,
    *   siehe PersonFragmentEmissionTest.php.
    *
    * @param array<int, array{person: string, role: string}> $orgRelations
    * @param string[] $suppressedIdTargets siehe applyDanglingReferenceGuard()
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array<string, array<int, array{'@id': string}>> Rolle => Liste von @id-Referenzen
    *
    ***************************************************************/
    public function buildOutputGroups(
        array $orgRelations,
        array $suppressedIdTargets,
        $settings,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        SchemaOrgData_UrlHelper $urlHelper
    ): array {
        if($orgRelations === []) {
            return [];
        }

        $baseUrl = $urlHelper->resolveBaseUrl();
        if($baseUrl === '') {
            return [];
        }

        $grouped = [];
        foreach($orgRelations as $relation) {
            $slug = (string) ($relation['person'] ?? '');
            $role = (string) ($relation['role'] ?? '');
            if($slug === '' or !in_array($role, self::ROLES, true)) {
                continue;
            }

            $fragment = SchemaOrgData_PersonsRegistryService::buildFragment($slug);
            if(in_array($fragment, $suppressedIdTargets, true)) {
                continue;
            }

            $person = $personsRegistryService->getPerson($settings, $slug);
            if($person === null or ($person['status'] ?? '') !== SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE) {
                continue;
            }

            $grouped[$role][] = ['@id' => $baseUrl.'#'.$fragment];
        }

        return $grouped;
    }
}
