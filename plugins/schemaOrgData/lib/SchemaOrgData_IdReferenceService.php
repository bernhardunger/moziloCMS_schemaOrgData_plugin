<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_IdReferenceService
*
* Kapselt die @id-Referenz-Naht (siehe README.md, Abschnitt
* "Organisations-Identität und @id-Anker"): Ermittlung der global
* verfügbaren @id-Fragmente für das id_reference_or_literal-Widget
* sowie der Dangling-Reference-Guard, der hängende id_reference-
* Verweise auf fehlende Zielknoten abfängt. Erhält ScopeResolver
* und SchemaRepository als Abhängigkeiten - wird bewusst nicht auf
* drei Komponenten verteilt, damit der De-Dup-/Dangling-Mechanismus
* nicht zerrissen wird.
*
* Zustandslos - kein Caching. Kollaboratoren, Settings und
* PLUGIN_SELF_DIR werden je Aufruf als Parameter übergeben.
* Sprache wird als bereits aufgelöstes Language-Objekt übergeben
* (kein loadAdminLanguage()-Aufruf hier - Cache-Guard und
* pluginLang-Seiteneffekt bleiben auf der Fassade).
*
***************************************************************/
class SchemaOrgData_IdReferenceService {

    /***************************************************************
    *
    * Liefert alle global konfigurierten Knoten mit ui:idFragment als
    * Fragment → Label-Map für das id_reference_or_literal-Widget.
    *
    * Label = Schema-Type-Bezeichnung + gespeicherter name-Wert (falls vorhanden).
    * Types ohne ui:idFragment werden übersprungen.
    *
    * Zusätzlich werden alle aktiven (status === "active") Registry-
    * Personen als Referenzziele aufgenommen (siehe README.md,
    * "Organisations-Identität und @id-Anker"): Fragment "person-{slug}", Label "name"
    * bzw. "name – jobTitle" bei gefülltem jobTitle, sortiert nach
    * sortOrder aufsteigend (bei Gleichstand nach Name). Inaktive
    * Personen bleiben hier ausgeblendet - eine bereits gespeicherte
    * Referenz auf eine später inaktiv gesetzte Person bleibt davon
    * unberührt (weiterhin auflösbar, siehe applyDanglingReferenceGuard()).
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param Language $adminLang bereits aufgelöste Admin-Sprache
    * @return array<string, string> [fragment => label]
    *
    ***************************************************************/
    public function resolveAvailableGlobalFragments(
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepo,
        $settings,
        string $pluginSelfDir,
        Language $adminLang,
        SchemaOrgData_PersonsRegistryService $personsRegistryService
    ): array {
        $globalConfig = $scopeResolver->loadScopeConfig($settings, 'global');
        $result = [];

        foreach($globalConfig as $type => $typeData) {
            if(!is_array($typeData)) {
                continue;
            }
            $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
            if(!is_array($schema)) {
                continue;
            }
            $fragment = trim((string) ($schema['ui:idFragment'] ?? ''));
            if($fragment === '') {
                continue;
            }
            $typeLabelKey = $schema['ui:typeLabel'] ?? $type;
            $typeLabel = $adminLang->getLanguageValue($typeLabelKey);
            $name = trim((string) ($typeData['name'] ?? ''));
            $result[$fragment] = $name !== '' ? $typeLabel.' — '.$name : $typeLabel;
        }

        $activePersons = array_filter(
            $personsRegistryService->loadRegistry($settings),
            fn($person): bool => is_array($person)
                and ($person['status'] ?? '') === SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE
        );
        uasort($activePersons, function(array $a, array $b): int {
            $sortCmp = ((int) ($a['sortOrder'] ?? 100)) <=> ((int) ($b['sortOrder'] ?? 100));
            return $sortCmp !== 0 ? $sortCmp : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        foreach($activePersons as $slug => $person) {
            $name = trim((string) ($person['name'] ?? ''));
            $jobTitle = trim((string) ($person['jobTitle'] ?? ''));
            $result[SchemaOrgData_PersonsRegistryService::buildFragment((string) $slug)] =
                $jobTitle !== '' ? $name.' – '.$jobTitle : $name;
        }

        return $result;
    }

    /***************************************************************
    *
    * Prüft, ob ein einzelnes @id-Fragment für ein Feld mit der
    * deklarativen Zieleinschränkung ui:referenceTargets erlaubt ist.
    * "organization" steht für das schema-statische Fragment
    * gleichen Namens (siehe README.md, "Organisations-Identität und @id-Anker"),
    * "persons" für jedes Registry-Personen-Fragment ("person-{slug}"-
    * Präfix). $referenceTargets === null bedeutet "keine Einschränkung
    * konfiguriert" - vollständige Rückwärtskompatibilität für Felder,
    * die ui:referenceTargets nicht setzen. Ein Fragment, das keiner der
    * beiden bekannten Zielarten entspricht, bleibt bewusst erlaubt (kein
    * Blockieren künftiger, hier noch unbekannter Fragmentarten).
    *
    * @param string $fragment zu prüfendes @id-Fragment
    * @param array<int,string>|null $referenceTargets ui:referenceTargets-Wert des Feld-Schemas
    *
    ***************************************************************/
    public static function isFragmentAllowedForReferenceTargets(string $fragment, ?array $referenceTargets): bool {
        if($referenceTargets === null) {
            return true;
        }
        if($fragment === 'organization') {
            return in_array('organization', $referenceTargets, true);
        }
        if(str_starts_with($fragment, 'person-')) {
            return in_array('persons', $referenceTargets, true);
        }
        return true;
    }

    /***************************************************************
    *
    * Filtert eine Fragment→Label-Map (siehe resolveAvailableGlobalFragments())
    * anhand der ui:referenceTargets-Property eines konkreten
    * id_reference_or_literal-Feld-Schemas. Fehlt die Property, bleibt die
    * komplette Liste erhalten - rein additive Einschränkung, kein
    * bestehendes Schema muss sie setzen. Filtert nur nachträglich anhand
    * bereits berechneter Fragmente (isFragmentAllowedForReferenceTargets()),
    * dupliziert resolveAvailableGlobalFragments() selbst also nicht.
    *
    * @param array<string,string> $availableFragments Ergebnis von resolveAvailableGlobalFragments()
    * @param array<string,mixed> $fieldSchema Schema-Definition der id_reference_or_literal-Property
    * @return array<string,string>
    *
    ***************************************************************/
    public static function filterFragmentsByReferenceTargets(array $availableFragments, array $fieldSchema): array {
        $referenceTargets = $fieldSchema['ui:referenceTargets'] ?? null;
        if(!is_array($referenceTargets)) {
            return $availableFragments;
        }

        return array_filter(
            $availableFragments,
            fn(string $fragment): bool => self::isFragmentAllowedForReferenceTargets($fragment, $referenceTargets),
            ARRAY_FILTER_USE_KEY
        );
    }

    /***************************************************************
    *
    * Dangling-Reference-Guard für id_reference-Properties.
    *
    * Wird in getContent() nach resolveTypeInheritance() und vor der
    * Ausgabeschleife aufgerufen. Prüft, ob eine aktive id_reference
    * auf einen @id-Knoten verweist, der im finalen Graph fehlt, und
    * reagiert entsprechend:
    *
    * - Zielknoten bereits im Graph → No-op.
    * - Zielknoten fehlt, weil Global durch keep-Modus unterdrückt
    *   wurde → id_reference unterdrücken ($suppressedIdTargets), damit
    *   kein Dangling-@id gegen den ausdrücklichen Nutzerwunsch erzeugt
    *   wird (keep hat explizit Vorrang).
    * - Zielknoten fehlt aus anderem Grund (z. B. excluded_cats) →
    *   Minimal-Stub des Zielknotens synthetisch in $scopeConfigs['global']
    *   einfügen (nur @type, @id und name). Der Stub durchläuft denselben
    *   resolveNodeId()-Mechanismus wie reguläre Knoten.
    *
    * Ziele mit dem Fragment-Präfix "person-" (Registry-Personen, siehe
    * README.md, "Organisations-Identität und @id-Anker") werden eigens
    * behandelt, da sie nicht Teil von $scopeConfigs sind, sondern separat
    * aus der Personen-Registry emittiert werden (siehe
    * SchemaOrgData_FrontendRenderer::renderFrontend()): existiert der
    * referenzierte Slug in der Registry, gilt das Ziel als vorhanden -
    * der Slug landet in $activePersonSlugs, die Emission des Knotens
    * übernimmt der Aufrufer. Existiert der Slug NICHT (mehr), wird die
    * Referenz unterdrückt (lieber keine Referenz als eine hängende @id
    * - ein Minimal-Stub wie bei schema-statischen Fragmenten ist hier
    * nicht möglich, da es keine globale Ersatzkonfiguration für eine
    * gelöschte Person gibt). Der Status der Person ist für diese
    * Prüfung irrelevant - eine bereits gespeicherte Referenz auf eine
    * inzwischen inaktive, aber weiterhin existierende Person bleibt
    * auflösbar (nur die Auswahlliste in resolveAvailableGlobalFragments()
    * blendet inaktive Personen aus).
    *
    * Organisations-Relationen (org_relations, siehe
    * SchemaOrgData_OrgRelationsService) fließen als zusätzliche
    * "person-{slug}"-Ziele in dieselbe Sammelschleife ein, statt einen
    * eigenen Dangling-Mechanismus zu duplizieren - eine Relation zu
    * einem gelöschten Slug landet dadurch wie jede andere hängende
    * Personen-Referenz in $suppressedIdTargets, ein weiterhin
    * existierender Slug in $activePersonSlugs (Emission des
    * Person-Knotens). Die zusätzliche Status-Filterung der
    * org_relations-Ausgabe selbst (inaktive Personen werden dort NICHT
    * ausgegeben, anders als bei id_reference_or_literal) erfolgt separat
    * in SchemaOrgData_OrgRelationsService::buildOutputGroups().
    *
    * Vollunterdrückung des gesamten Knotens bei gelöschter Pflicht-
    * referenz: Trägt ein id_reference-/id_reference_or_literal-Feld
    * "ui:required: true" und zeigt sein Referenz-Modus-Ziel auf eine
    * gelöschte Registry-Person (Fragment-Präfix "person-", Ziel in
    * $suppressedIdTargets), wird nicht nur die Property, sondern der
    * gesamte Type-Knoten dieser Ebene aus $scopeConfigs entfernt - ein
    * Knoten ohne seine als Pflicht deklarierte Referenz wäre ein
    * unvollständiger, irreführender Torso (Beispiel: ProfilePage ohne
    * mainEntity verfehlt die von Google für diesen Type geforderte
    * Pflichtangabe). Bewusst auf das Fragment-Präfix "person-"
    * eingeschränkt: Bei schema-statischen Fragmenten (z. B.
    * "organization") landet ein Ziel auch durch die gewollte
    * keep-Modus-Unterdrückung in $suppressedIdTargets, ohne dass der
    * Zielknoten deswegen nicht existiert - eine Vollunterdrückung wäre
    * dort fachlich falsch (siehe DonateActionTest, keep-Modus-Fall).
    * Nicht als Pflicht deklarierte Referenzen (z. B. Event.organizer)
    * bleiben unverändert: nur die Property entfällt, der Knoten bleibt.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param array<string, array<string, mixed>> $scopeConfigs finale Scope-Konfiguration (nach resolveTypeInheritance)
    * @param bool $globalSuppressedByKeep true, wenn Global durch keep unterdrückt wurde
    * @param array<int, array{person: string, role: string}> $orgRelations siehe SchemaOrgData_OrgRelationsService
    * @return array{0: array<string, array<string, mixed>>, 1: array<string>, 2: string[]} [$scopeConfigs, $suppressedIdTargets, $activePersonSlugs]
    *
    ***************************************************************/
    public function applyDanglingReferenceGuard(
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepo,
        $settings,
        string $pluginSelfDir,
        array $scopeConfigs,
        bool $globalSuppressedByKeep,
        SchemaOrgData_PersonsRegistryService $personsRegistryService,
        array $orgRelations = []
    ): array {
        $suppressedIdTargets = [];
        $activePersonSlugs = [];

        // Alle id_reference- und id_reference_or_literal-Targets sammeln.
        // $requiredReferenceBindings merkt sich zusätzlich Scope/Type je
        // als "ui:required" deklariertem Referenzfeld, als Grundlage für
        // die Vollunterdrückungs-Prüfung weiter unten.
        $activeTargets = [];
        $requiredReferenceBindings = [];
        foreach($scopeConfigs as $scope => $config) {
            foreach($config as $type => $typeData) {
                $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
                if(!is_array($schema)) {
                    continue;
                }
                foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $schemaRepo->resolveSchemaRef($propSchema, $schema);
                    $widget = $propSchema['ui:widget'] ?? '';
                    $required = ($propSchema['ui:required'] ?? false) === true;
                    if($widget === 'id_reference') {
                        $target = trim((string) ($propSchema['ui:idTarget'] ?? ''));
                        if($target !== '') {
                            $activeTargets[] = $target;
                            if($required) {
                                $requiredReferenceBindings[] = ['scope' => $scope, 'type' => $type, 'target' => $target];
                            }
                        }
                    } elseif($widget === 'id_reference_or_literal') {
                        // Nur Referenz-Modus erzeugt eine @id-Abhängigkeit.
                        $stored = is_array($typeData[$propName] ?? null) ? $typeData[$propName] : null;
                        if($stored !== null and ($stored['_mode'] ?? '') === 'reference') {
                            $fragment = trim((string) ($stored['_fragment'] ?? ''));
                            if($fragment !== '') {
                                $activeTargets[] = $fragment;
                                if($required) {
                                    $requiredReferenceBindings[] = ['scope' => $scope, 'type' => $type, 'target' => $fragment];
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach($orgRelations as $relation) {
            $slug = trim((string) ($relation['person'] ?? ''));
            if($slug !== '') {
                $activeTargets[] = SchemaOrgData_PersonsRegistryService::buildFragment($slug);
            }
        }

        if($activeTargets === []) {
            return [$scopeConfigs, $suppressedIdTargets, $activePersonSlugs];
        }

        // Bereits im Graph vorhandene @id-Fragmente bestimmen.
        $presentFragments = [];
        foreach($scopeConfigs as $config) {
            foreach(array_keys($config) as $type) {
                $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
                if(!is_array($schema)) {
                    continue;
                }
                $fragment = trim((string) ($schema['ui:idFragment'] ?? ''));
                if($fragment !== '') {
                    $presentFragments[] = $fragment;
                }
            }
        }

        foreach(array_unique($activeTargets) as $target) {
            if(str_starts_with($target, 'person-')) {
                // Registry-Personen sind nicht Teil von $scopeConfigs -
                // Präsenz wird stattdessen gegen die Registry geprüft
                // (siehe Docblock oben).
                $slug = substr($target, strlen('person-'));
                if($personsRegistryService->slugExists($settings, $slug)) {
                    $activePersonSlugs[] = $slug;
                } else {
                    $suppressedIdTargets[] = $target;
                }
                continue;
            }

            if(in_array($target, $presentFragments, true)) {
                // Zielknoten vorhanden - kein Eingriff nötig.
                continue;
            }

            if($globalSuppressedByKeep) {
                // keep-Modus hat Vorrang: id_reference nicht emittieren,
                // damit kein Dangling-@id gegen die Nutzerwahl erzeugt wird.
                $suppressedIdTargets[] = $target;
                continue;
            }

            // Zielknoten fehlt (z. B. excluded_cats): Minimal-Stub erzwingen.
            // Aus der globalen Konfiguration den Type mit dem passenden
            // ui:idFragment laden und als Stub mit @type, @id und name einfügen.
            $globalConfig = $scopeResolver->loadScopeConfig($settings, 'global');
            unset($globalConfig['_meta'], $globalConfig['excluded_cats'], $globalConfig['debug_output']);

            foreach($globalConfig as $globalType => $globalData) {
                $schema = $schemaRepo->loadSchema($pluginSelfDir, $globalType);
                if(!is_array($schema)) {
                    continue;
                }
                if(trim((string) ($schema['ui:idFragment'] ?? '')) !== $target) {
                    continue;
                }

                // Stub-Inhalt: nur name als Pflicht-Identifikator;
                // @type und @id werden durch die reguläre Ausgabeschleife
                // (buildJsonLdScript + resolveNodeId) ergänzt.
                $stub = [];
                $nameValue = is_array($globalData) ? ($globalData['name'] ?? '') : '';
                if(is_string($nameValue) and $nameValue !== '') {
                    $stub['name'] = $nameValue;
                }

                if(!isset($scopeConfigs['global'])) {
                    $scopeConfigs['global'] = [];
                }
                $scopeConfigs['global'][$globalType] = $stub;
                break;
            }
        }

        // Vollunterdrückung des gesamten Knotens (siehe Docblock oben):
        // nur bei "person-"-Fragmenten, da dort ein Suppressed-Eintrag
        // stets echte Nichtexistenz bedeutet (gelöschte Registry-Person),
        // nie eine keep-Modus-Entscheidung.
        foreach($requiredReferenceBindings as $binding) {
            if(str_starts_with($binding['target'], 'person-') and in_array($binding['target'], $suppressedIdTargets, true)) {
                unset($scopeConfigs[$binding['scope']][$binding['type']]);
            }
        }

        return [$scopeConfigs, $suppressedIdTargets, $activePersonSlugs];
    }
}
