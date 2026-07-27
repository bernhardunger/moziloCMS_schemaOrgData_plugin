<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_ScopeResolver
*
* Kapselt die Geltungsbereichs-Logik (Global/Kategorie/Seite):
* Settings-Key-Ermittlung, Bezeichner-Sanitizing, Laden/Speichern
* der Scope-Konfiguration und -Metadaten sowie die feldweise
* Vererbung zwischen den Ebenen (siehe README.md, Abschnitt
* "Geltungsbereiche und Vererbung"). Wird von der Fassade
* schemaOrgData über einen Lazy-Accessor verdrahtet.
*
* Zustandslos - kein Caching (siehe README.md). Die vier
* settings-abhängigen Methoden erhalten $settings als ersten
* Parameter (bewusst ohne Type-Hint, kompatibel sowohl zu den
* moziloCMS-Properties als auch zu InMemorySettings aus
* tests/bootstrap.php, die kein gemeinsames Interface teilen).
*
***************************************************************/
class SchemaOrgData_ScopeResolver {

    /***************************************************************
    *
    * Liefert den settings-Schlüssel für eine Geltungsebene.
    * Ersetzt getScopeConfFile() — die Konfiguration wird über
    * $this->settings statt über conf/-Dateien gespeichert.
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return string|null Settings-Key oder null bei ungültigem $scope
    *
    ***************************************************************/
    public function getScopeSettingsKey(
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): ?string {
        return match($scope) {
            'global'   => 'config_global',
            'category' => $cat !== null
                ? 'config_cat_' . $cat
                : null,
            'page'     => ($cat !== null && $page !== null)
                ? 'config_page_' . $cat . '_' . $page
                : null,
            default    => null,
        };
    }

    /***************************************************************
    *
    * Entfernt aus einem Bezeichner (CAT_REQUEST/PAGE_REQUEST) alle
    * Zeichen, die in settings-Keys nicht erlaubt bzw. unerwünscht
    * sind, bevor er in getScopeSettingsKey() verwendet wird (Schutz
    * vor Path-Traversal, siehe README.md, Abschnitt "Sicherheit").
    *
    * @return string bereinigter Bezeichner
    *
    ***************************************************************/
    public function sanitizeScopeIdentifier(string $value): string {
        // Buchstaben, Ziffern, Bindestriche, Unterstriche und Prozentzeichen
        // (URL-Encoding moziloCMS) erhalten. Path-Traversal-Zeichen (.,/,\,NUL)
        // werden entfernt — % allein stellt kein Traversal-Risiko dar.
        return preg_replace('/[^a-zA-Z0-9_\-%]/', '', $value);
    }

    /***************************************************************
    *
    * Liefert die (sanitierten) CAT_REQUEST/PAGE_REQUEST-Werte einer
    * Geltungsebene, passend für getScopeSettingsKey().
    *
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{0: string|null, 1: string|null} [cat, page]
    *
    ***************************************************************/
    public function resolveScopeIdentifiers(string $scope): array {
        $cat = (defined('CAT_REQUEST') and CAT_REQUEST) ? $this->sanitizeScopeIdentifier((string) CAT_REQUEST) : null;
        $page = (defined('PAGE_REQUEST') and PAGE_REQUEST) ? $this->sanitizeScopeIdentifier((string) PAGE_REQUEST) : null;

        // Fallback auf Plugin-eigene Parameter im Admin-Kontext
        // (CAT_REQUEST/PAGE_REQUEST sind im moziloCMS-Admin nicht gesetzt).
        // Fallback-Reihenfolge: CMS-Konstanten → POST → GET. POST hat
        // Vorrang vor GET, da das Speichern-Formular ohne Query-String
        // an die admin/index.php sendet (siehe getConfig()).
        if ($cat === null && isset($_POST['schemaOrgData_cat'])) {
            $cat = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_cat']);
            if ($cat === '') $cat = null;
        }
        if ($cat === null && isset($_GET['schemaOrgData_cat'])) {
            $cat = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_cat']);
            if ($cat === '') $cat = null;
        }
        if ($page === null && isset($_POST['schemaOrgData_page'])) {
            $page = $this->sanitizeScopeIdentifier((string) $_POST['schemaOrgData_page']);
            if ($page === '') $page = null;
        }
        if ($page === null && isset($_GET['schemaOrgData_page'])) {
            $page = $this->sanitizeScopeIdentifier((string) $_GET['schemaOrgData_page']);
            if ($page === '') $page = null;
        }

        return match($scope) {
            'category' => [$cat, null],
            'page'     => [$cat, $page],
            default    => [null, null],
        };
    }

    /***************************************************************
    *
    * Führt die Properties mehrerer Geltungsebenen pro Schema-Type
    * zusammen. Spätere Arrays überschreiben gleichnamige Properties
    * früherer Arrays (Global -> Kategorie -> Seite).
    *
    * @param array<string, array<string, mixed>> ...$configs jeweils array('TypeName' => array(...), ...)
    * @return array<string, array<string, mixed>> array('TypeName' => array(...), ...)
    *
    ***************************************************************/
    public function mergeConfigs(array ...$configs): array {
        $result = [];
        foreach($configs as $config) {
            foreach($config as $type => $properties) {
                $result[$type] = array_merge($result[$type] ?? [], $properties);
            }
        }
        return $result;
    }

    /***************************************************************
    *
    * Löst Type-Kollisionen zwischen den Geltungsebenen feldweise auf
    * (Vererbung Global -> Kategorie -> Seite, siehe README.md
    * Abschnitt "Geltungsbereiche und Vererbung"): ist derselbe Schema-Type auf
    * mehreren Ebenen konfiguriert, werden die Felder über
    * mergeConfigs() zusammengeführt - leere/fehlende Felder der
    * spezifischeren Ebene erben den Wert der übergeordneten Ebene,
    * gefüllte Felder (inkl. verschachtelter Felder wie "address" oder
    * "openingHours") überschreiben vollständig (kein Merge innerhalb
    * verschachtelter Felder). Die zusammengeführten Daten werden
    * einmalig auf der spezifischsten Ebene ausgegeben, auf der der
    * Type konfiguriert ist. Verschiedene Types bleiben unabhängig.
    *
    * @param array<string, array<string, array<string, mixed>>> $scopeConfigs
    *              array('global' => [...], 'category' => [...], 'page' => [...]),
    *              jeweils array('TypeName' => array('property' => 'wert', ...), ...)
    * @return array<string, array<string, array<string, mixed>>> dieselbe Struktur, mit feldweise zusammengeführten Daten
    *
    ***************************************************************/
    public function resolveTypeInheritance(array $scopeConfigs): array {
        $typeScopes = [];
        foreach(['global', 'category', 'page'] as $scope) {
            if(!isset($scopeConfigs[$scope])) {
                continue;
            }
            foreach($scopeConfigs[$scope] as $type => $data) {
                $typeScopes[$type] = $scope;
            }
        }

        $merged = $this->mergeConfigs(
            $scopeConfigs['global'] ?? [],
            $scopeConfigs['category'] ?? [],
            $scopeConfigs['page'] ?? []
        );

        foreach($scopeConfigs as $scope => $config) {
            $scopeConfigs[$scope] = [];
        }
        foreach($merged as $type => $data) {
            $scopeConfigs[$typeScopes[$type]][$type] = $data;
        }

        return $scopeConfigs;
    }

    /***************************************************************
    *
    * Lädt die Konfiguration einer Geltungsebene.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $scope 'global' | 'category' | 'page'
    * @param string|null $cat  Kategorie (CAT_REQUEST), für 'category' und 'page'
    * @param string|null $page Seite (PAGE_REQUEST), nur für 'page'
    * @return array<string, array<string, mixed>> array('TypeName' => array('property' => 'wert', ...), ...)
    *
    ***************************************************************/
    public function loadScopeConfig(
        $settings,
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): array {
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null || !$settings->keyExists($key)) {
            return [];
        }
        $data = $settings->get($key);
        return is_array($data) ? $data : [];
    }

    /***************************************************************
    *
    * Lädt die Kollisions-Metadaten einer Geltungsebene
    * (existing_jsonld-Flag, gewählter jsonld_mode sowie die erkannten
    * JSON-LD-Blöcke als Einzelblock-Array für den serverseitigen
    * Pro-Block-Import, siehe README.md, Abschnitt "Vorhandenes
    * JSON-LD und Import").
    *
    * Legacy-Normalisierung: Meta aus Versionen vor
    * existing_jsonld_blocks enthält nur den content-String. Ist er
    * als Ganzes gültiges JSON, war es ein Einzelblock und dient als
    * blocks[0]. Ungültiges JSON deutet auf ein altes
    * Mehrblock-Konkatenat (implode "\n\n") hin - blocks bleibt dann
    * leer, bis der jeweilige Erkennungspfad (Admin-Load global /
    * Frontend-Aufruf page) die Meta neu schreibt.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $scope 'global' | 'category' | 'page'
    * @return array{existing_jsonld: bool, jsonld_mode: string, existing_jsonld_content: string, existing_jsonld_blocks: string[]}
    *
    ***************************************************************/
    public function loadScopeMeta(
        $settings,
        string $scope,
        ?string $cat  = null,
        ?string $page = null
    ): array {
        $defaults = [
            'existing_jsonld' => false,
            'jsonld_mode' => 'keep',
            'existing_jsonld_content' => '',
            'existing_jsonld_blocks' => [],
        ];
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null || !$settings->keyExists($key)) {
            return $defaults;
        }
        $data = $settings->get($key);
        $meta = array_merge($defaults, is_array($data) ? ($data['_meta'] ?? []) : []);

        if ($meta['existing_jsonld_blocks'] === [] && $meta['existing_jsonld_content'] !== '') {
            json_decode($meta['existing_jsonld_content']);
            if (json_last_error() === JSON_ERROR_NONE) {
                $meta['existing_jsonld_blocks'] = [$meta['existing_jsonld_content']];
            }
        }

        return $meta;
    }

    /***************************************************************
    *
    * Speichert die Kollisions-Metadaten einer Geltungsebene
    * (existing_jsonld-Flag, jsonld_mode), ohne die bereits
    * konfigurierten Schema-Type-Properties zu verändern.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $scope 'global' | 'category' | 'page'
    * @param array<string, mixed> $meta z. B. ['existing_jsonld' => true, 'jsonld_mode' => 'override']
    * @return bool false, wenn die Metadaten nicht persistiert wurden -
    *         weil der Scope nicht auflösbar war oder der Schreibzugriff
    *         scheiterte; true nur bei tatsächlich geschriebenem Stand
    *
    ***************************************************************/
    public function saveScopeMeta(
        $settings,
        string $scope,
        array  $meta,
        ?string $cat  = null,
        ?string $page = null
    ): bool {
        $key = $this->getScopeSettingsKey($scope, $cat, $page);
        if ($key === null) {
            return false;
        }
        $existing = $settings->keyExists($key)
            ? $settings->get($key) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing['_meta'] = array_merge(
            $existing['_meta'] ?? [
                'existing_jsonld' => false,
                'jsonld_mode' => 'keep',
                'existing_jsonld_content' => '',
                'existing_jsonld_blocks' => [],
            ],
            $meta
        );
        // Schreibfehler protokollieren — saveScopeMeta hat kein Rückgabe-Array
        // mit Meldungstexten, daher error_log als stilles Fallback zusätzlich
        // zum bool-Rückgabewert. Misserfolg signalisiert der Kern per
        // Rückgabewert false, nicht per Exception; der catch-Zweig bleibt als
        // Netz für unerwartete Fehler daneben stehen.
        try {
            if ($settings->set($key, $existing) === false) {
                error_log('schemaOrgData: saveScopeMeta fehlgeschlagen: Schreibzugriff auf '.$key.' abgelehnt');
                return false;
            }
        } catch (\Throwable $e) {
            error_log('schemaOrgData: saveScopeMeta fehlgeschlagen: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /***************************************************************
    *
    * Löscht den settings-Key einer Geltungsebene vollständig - damit
    * entfallen sowohl die Schema-Type-Konfiguration als auch die
    * Meta-Daten (_meta, existing_jsonld/jsonld_mode) dieser Ebene.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $scope 'global' | 'category' | 'page'
    * @param ?Language $lang liefert den Meldungstext eines
    *        fehlgeschlagenen Schreibzugriffs; ohne Sprachobjekt bleibt
    *        der Schlüsselname als Rohtext übrig
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function deleteConfig($settings, string $scope, ?Language $lang = null): array {
        [$cat, $page] = $this->resolveScopeIdentifiers($scope);
        $key = $this->getScopeSettingsKey($scope, $cat, $page);

        if ($key === null) {
            return ['success' => false, 'errors' => []];
        }

        // Der Kern liefert bei delete() auch dann false, wenn der Schlüssel
        // gar nicht existierte - ein Fehlschlag ist deshalb nur nach der
        // keyExists()-Vorprüfung als solcher zu werten. Misserfolg
        // signalisiert der Kern per Rückgabewert, nicht per Exception.
        if ($settings->keyExists($key) and $settings->delete($key) === false) {
            return ['success' => false, 'errors' => [
                $lang !== null
                    ? $lang->getLanguageValue('error_config_write_failed')
                    : 'error_config_write_failed'
            ]];
        }

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Prüft, ob für $selectedType bereits auf einer allgemeineren
    * Ebene (Global bzw. Global+Kategorie) eine Konfiguration
    * existiert. Ist dies der Fall, erbt diese Ebene leere Felder von
    * der allgemeineren Ebene; gefüllte Felder überschreiben deren
    * Ausgabe für diesen Type (siehe README.md, Abschnitt
    * "Geltungsbereiche und Vererbung" sowie resolveTypeInheritance()).
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param string $scope 'category' | 'page' (für 'global' immer [])
    * @return string[] Liste der allgemeineren Ebenen, von denen geerbt wird
    *
    ***************************************************************/
    public function detectTypeCollision($settings, string $scope, ?string $cat, ?string $page, string $selectedType): array {
        $higherScopes = match($scope) {
            'category' => ['global' => $this->loadScopeConfig($settings, 'global')],
            'page'     => [
                'global'   => $this->loadScopeConfig($settings, 'global'),
                'category' => $this->loadScopeConfig($settings, 'category', $cat),
            ],
            default => [],
        };

        $collisions = [];
        foreach($higherScopes as $higherScope => $higherConfig) {
            if(array_key_exists($selectedType, $higherConfig)) {
                $collisions[] = $higherScope;
            }
        }

        return $collisions;
    }
}
