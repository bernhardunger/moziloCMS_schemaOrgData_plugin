<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_IdReferenceService
*
* Kapselt die @id-Referenz-Naht (siehe README.md, Abschnitt
* "@id-Anker und Knotenreferenzen"): Ermittlung der global
* verfügbaren @id-Fragmente für das id_reference_or_literal-Widget
* sowie der Dangling-Reference-Guard, der hängende id_reference-
* Verweise auf fehlende Zielknoten abfängt. Erhält ScopeResolver
* und SchemaRepository als Abhängigkeiten (siehe
* doc/adr_komponenten_refactoring.md, Entscheidung c) - wird
* bewusst nicht auf drei Komponenten verteilt, damit der
* De-Dup-/Dangling-Mechanismus nicht zerrissen wird.
*
* Zustandslos - kein Caching. Kollaboratoren, Settings und
* PLUGIN_SELF_DIR werden je Aufruf als Parameter übergeben,
* analog zur Linie aus den Schritten 3-5. Sprache wird als bereits
* aufgelöstes Language-Objekt übergeben (kein loadAdminLanguage()-
* Aufruf hier - Cache-Guard und pluginLang-Seiteneffekt bleiben
* auf der Fassade, siehe Entscheidung i).
*
***************************************************************/
class SchemaOrgData_IdReferenceService {

    /***************************************************************
    *
    * Liefert alle global konfigurierten Knoten mit ui:idFragment als
    * Fragment → Label-Map für das id_reference_or_literal-Widget.
    *
    * Label = Schema-Typbezeichnung + gespeicherter name-Wert (falls vorhanden).
    * Typen ohne ui:idFragment werden übersprungen.
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
        Language $adminLang
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

        return $result;
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
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param array<string, array<string, mixed>> $scopeConfigs finale Scope-Konfiguration (nach resolveTypeInheritance)
    * @param bool $globalSuppressedByKeep true, wenn Global durch keep unterdrückt wurde
    * @return array{0: array<string, array<string, mixed>>, 1: array<string>} [$scopeConfigs, $suppressedIdTargets]
    *
    ***************************************************************/
    public function applyDanglingReferenceGuard(
        SchemaOrgData_ScopeResolver $scopeResolver,
        SchemaOrgData_SchemaRepository $schemaRepo,
        $settings,
        string $pluginSelfDir,
        array $scopeConfigs,
        bool $globalSuppressedByKeep
    ): array {
        $suppressedIdTargets = [];

        // Alle id_reference- und id_reference_or_literal-Targets sammeln.
        $activeTargets = [];
        foreach($scopeConfigs as $config) {
            foreach($config as $type => $typeData) {
                $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
                if(!is_array($schema)) {
                    continue;
                }
                foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                    $propSchema = $schemaRepo->resolveSchemaRef($propSchema, $schema);
                    $widget = $propSchema['ui:widget'] ?? '';
                    if($widget === 'id_reference') {
                        $target = trim((string) ($propSchema['ui:idTarget'] ?? ''));
                        if($target !== '') {
                            $activeTargets[] = $target;
                        }
                    } elseif($widget === 'id_reference_or_literal') {
                        // Nur Referenz-Modus erzeugt eine @id-Abhängigkeit.
                        $stored = is_array($typeData[$propName] ?? null) ? $typeData[$propName] : null;
                        if($stored !== null and ($stored['_mode'] ?? '') === 'reference') {
                            $fragment = trim((string) ($stored['_fragment'] ?? ''));
                            if($fragment !== '') {
                                $activeTargets[] = $fragment;
                            }
                        }
                    }
                }
            }
        }

        if($activeTargets === []) {
            return [$scopeConfigs, $suppressedIdTargets];
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

        return [$scopeConfigs, $suppressedIdTargets];
    }
}
