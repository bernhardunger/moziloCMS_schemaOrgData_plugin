<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_PersonSuggestionService
*
* Erkennt Personen-Literale (Property "employee"/"founder"/"member"
* mit "@type": "Person" als einzelnes Objekt), die im Erweiterungsfeld
* eines global konfigurierten Organisations-Identity-Types
* ("ui:idFragment": "organization") stehen, und bietet eine Übernahme
* in die Personen-Registry an - entweder eine Verknüpfung mit einer
* bereits vorhandenen Person (Namensgleichheit) oder die Neuanlage
* einer Registry-Person samt Organisations-Relation (siehe
* SchemaOrgData_OrgRelationsService). Bei Bestätigung wird die
* übernommene Property aus der Type-Konfiguration entfernt.
*
* Zustandslos: Kollaboratoren ($settings, SchemaOrgData_PersonsRegistryService,
* SchemaOrgData_OrgRelationsService, Language, SchemaOrgData_Validator)
* werden je Aufruf als Parameter übergeben, nicht im Konstruktor
* eingefroren (siehe README.md, Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_PersonSuggestionService {

    /***************************************************************
    *
    * Sucht in der Type-Konfiguration eines global konfigurierten
    * Organisations-Identity-Types nach "employee"/"founder"/"member"-
    * Properties, die als einzelnes Objekt mit "@type": "Person"
    * vorliegen (Erweiterungsfeld-Rohdaten, siehe README.md, Abschnitt
    * "Erweiterungsfeld"). Ein Array mehrerer Personen unter demselben
    * Key wird bewusst nicht erkannt.
    *
    * @param array<string, mixed> $typeConfig bereits persistierte
    *        Type-Konfiguration ($config[$type], siehe
    *        SchemaOrgData_ScopeResolver::loadScopeConfig())
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array<int, array{property: string, literal: array<string, mixed>, matchedSlug: string|null}>
    *
    ***************************************************************/
    public function detectSuggestions(
        array $typeConfig,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        $settings
    ): array {
        $registry = $personsRegistryService->loadRegistry($settings);
        $suggestions = [];

        foreach(SchemaOrgData_OrgRelationsService::roles() as $property) {
            $literal = $typeConfig[$property] ?? null;
            if(!is_array($literal) or ($literal['@type'] ?? '') !== 'Person') {
                continue;
            }

            $name = trim((string) ($literal['name'] ?? ''));
            $suggestions[] = [
                'property' => $property,
                'literal' => $literal,
                'matchedSlug' => $this->matchPersonByName($name, $registry),
            ];
        }

        return $suggestions;
    }

    /***************************************************************
    *
    * Rendert den Übernahme-Hinweis samt Submit-Button je gefundenem
    * Personen-Literal (siehe detectSuggestions()). Der Button-Value
    * trägt den Property-Namen ("employee"/"founder"/"member") -
    * SchemaOrgData_AdminRequestHandler ermittelt den betroffenen Type
    * selbst aus dem aktuell aktiven globalen Organisations-Identity-Type
    * (siehe dort).
    *
    * @param array<int, array{property: string, literal: array<string, mixed>, matchedSlug: string|null}> $suggestions
    * @param string $idPrefix Präfix für die HTML-ID des Hinweisblocks
    * @return string HTML-Snippet oder '' ohne Funde
    *
    ***************************************************************/
    public function renderSuggestionNotice(array $suggestions, string $type, Language $lang, string $idPrefix): string {
        if($suggestions === []) {
            return '';
        }

        $html = '<div id="schemaOrgData-person-suggestion-'.htmlspecialchars($idPrefix, ENT_QUOTES, CHARSET).'"'
            .' class="schemaOrgData-notice schemaOrgData-notice--info schemaOrgData-person-suggestion">'."\n";

        foreach($suggestions as $suggestion) {
            $property = (string) $suggestion['property'];
            $name = trim((string) ($suggestion['literal']['name'] ?? ''));
            $roleLabel = $lang->getLanguageValue('label_role_'.$property);
            $buttonKey = ($suggestion['matchedSlug'] !== null)
                ? 'button_person_suggestion_link'
                : 'button_person_suggestion_create';

            $html .= '<p>'.$lang->getLanguageHtml('hint_person_suggestion_found', $roleLabel, $name).'</p>'."\n";
            $html .= '<button type="submit" name="schemaOrgData_accept_person_suggestion" value="'
                .htmlspecialchars($property, ENT_QUOTES, CHARSET).'" class="mo-btn">'
                .$lang->getLanguageHtml($buttonKey).'</button>'."\n";
        }

        $html .= '</div>'."\n";

        return $html;
    }

    /***************************************************************
    *
    * Übernimmt einen Übernahme-Vorschlag (siehe detectSuggestions()):
    * verlinkt mit einer per Namensgleichheit gefundenen Registry-Person
    * oder legt eine neue an (SchemaOrgData_PersonsRegistryService::
    * createPerson()), ergänzt eine passende Organisations-Relation
    * (org_relations, siehe SchemaOrgData_OrgRelationsService) und
    * entfernt die übernommene Property aus der Type-Konfiguration.
    *
    * Liest den aktuellen Property-Wert aus $config[$type] statt eines
    * separat übergebenen Literals, damit ein zwischenzeitlich
    * geänderter/entfernter Wert (Wettlauf-Fall) erkannt wird statt
    * stillschweigend übernommen zu werden.
    *
    * @param array<string, array<string, mixed>> $config vollständige, bereits
    *        geladene globale Konfiguration (config_global)
    * @param string $type aktuell aktiver globaler Organisations-Identity-Type
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function acceptSuggestion(
        string $property,
        array $config,
        string $type,
        $settings,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        SchemaOrgData_OrgRelationsService $orgRelationsService,
        Language $lang,
        SchemaOrgData_Validator $validator
    ): array {
        if(!in_array($property, SchemaOrgData_OrgRelationsService::roles(), true)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_suggestion_invalid_property')]];
        }

        $literal = $config[$type][$property] ?? null;
        if(!is_array($literal) or ($literal['@type'] ?? '') !== 'Person') {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_suggestion_outdated')]];
        }

        $name = trim((string) ($literal['name'] ?? ''));
        $slug = $this->matchPersonByName($name, $personsRegistryService->loadRegistry($settings));

        if($slug === null) {
            $sameAs = $literal['sameAs'] ?? null;
            $rawData = [
                'name'            => $name,
                'honorificPrefix' => (string) ($literal['honorificPrefix'] ?? ''),
                'jobTitle'        => (string) ($literal['jobTitle'] ?? ''),
                'description'     => (string) ($literal['description'] ?? ''),
                'url'             => (string) ($literal['url'] ?? ''),
                'image'           => (string) ($literal['image'] ?? ''),
                // sanitizePersonData() zerlegt "sameAs" zeilenweise (Textarea-
                // Format) - ein Array wird hier zu genau dieser Zeilenform
                // zusammengesetzt, damit createPerson() unverändert wiederverwendet
                // werden kann, statt die Bereinigungslogik zu duplizieren.
                'sameAs'          => is_array($sameAs) ? implode("\n", array_map('strval', $sameAs)) : (string) $sameAs,
            ];

            $result = $personsRegistryService->createPerson($settings, $rawData, $lang, $validator);
            if(!$result['success']) {
                return ['success' => false, 'errors' => $result['errors']];
            }
            $slug = $result['slug'];
        }

        $config['org_relations'] = is_array($config['org_relations'] ?? null) ? $config['org_relations'] : [];

        // Verlinken einer Person, die über eine frühere Übernahme bereits mit
        // derselben Rolle verlinkt ist, darf keinen doppelten org_relations-
        // Eintrag erzeugen (Reproduktionsfall: identisches Personen-Literal
        // mehrfach ins Erweiterungsfeld eingefügt und jeweils per "Verlinken"
        // bestätigt). Die Property wird trotzdem entfernt, damit sie nicht im
        // Erweiterungsfeld verbleibt.
        $alreadyLinked = false;
        foreach($config['org_relations'] as $existingRelation) {
            if(($existingRelation['person'] ?? null) === $slug and ($existingRelation['role'] ?? null) === $property) {
                $alreadyLinked = true;
                break;
            }
        }
        if(!$alreadyLinked) {
            $config['org_relations'][] = ['person' => $slug, 'role' => $property];
        }
        unset($config[$type][$property]);

        try {
            $settings->set('config_global', $config);
        } catch (\Throwable $e) {
            error_log('schemaOrgData: acceptSuggestion fehlgeschlagen: '.$e->getMessage());
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_config_write_failed')]];
        }

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Sucht in der vollständigen Personen-Registry (unabhängig vom
    * Status) nach einer Person mit exakt (getrimmt) gleichem Namen.
    *
    * @param array<string, array<string, mixed>> $registry siehe
    *        SchemaOrgData_PersonsRegistryService::loadRegistry()
    * @return string|null Slug der gefundenen Person oder null
    *
    ***************************************************************/
    private function matchPersonByName(string $name, array $registry): ?string {
        if($name === '') {
            return null;
        }

        foreach($registry as $slug => $person) {
            if(trim((string) ($person['name'] ?? '')) === $name) {
                return (string) $slug;
            }
        }

        return null;
    }
}
