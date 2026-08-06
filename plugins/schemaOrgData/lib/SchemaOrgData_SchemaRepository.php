<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_SchemaRepository
*
* Lädt und löst JSON-Schema-Dateien aus schemas/ auf. Wird von
* der Fassade schemaOrgData über einen Lazy-Accessor verdrahtet.
*
* Zustandslos gegenüber der Fassade; cacht Schema-Lesevorgänge für
* die Lebensdauer der Instanz.
*
***************************************************************/
class SchemaOrgData_SchemaRepository {

    /** Cache für loadSchema(), Key: "{pluginSelfDir}|{type}". Siehe Backlog-Eintrag Caching. */
    private array $schemaCache = [];

    /** Cache für getAvailableSchemaTypes(), Key: pluginSelfDir. */
    private array $availableTypesCache = [];

    /***************************************************************
    *
    * Lädt ein JSON-Schema aus schemas/{type}.json.
    *
    * Kontrakt: Ein Type-Name, der nicht wörtlich als Datei im Bestand von
    * schemas/ liegt, liefert null - auch dann, wenn er nach einer
    * Normalisierung auf ein vorhandenes Schema zeigen würde. Die Prüfung
    * sitzt hier und nicht bei den Aufrufern, weil der Rückgabewert dieser
    * Methode das Schema ist, nicht der Name: Ein still bereinigter Name
    * ("./LocalBusiness", "foo/LocalBusiness") lädt zwar ein Schema, der
    * Aufrufer speichert und emittiert danach aber weiterhin den rohen Wert.
    * Er arbeitet also mit einem anderen Type weiter, als das geladene Schema
    * beschreibt - im JSON-LD landet ein "@type", den schema.org nicht kennt,
    * und derselbe Type kann eine Scope-Konfiguration doppelt belegen.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @return array<string, mixed>|null dekodiertes Schema oder null bei
    *         unbekanntem Type bzw. nicht lesbarem/defektem Schema
    *
    ***************************************************************/
    public function loadSchema(string $pluginSelfDir, string $type): ?array {
        $cacheKey = $pluginSelfDir.'|'.$type;
        // array_key_exists() statt isset(): auch ein gecachtes null-Ergebnis
        // (Schema nicht gefunden) gilt als Cache-Treffer.
        if(array_key_exists($cacheKey, $this->schemaCache)) {
            return $this->schemaCache[$cacheKey];
        }

        if(!in_array($type, $this->getAvailableSchemaTypes($pluginSelfDir), true)) {
            return $this->schemaCache[$cacheKey] = null;
        }

        // Der Guard bleibt trotz Bestandsprüfung: die Bestandsliste ist
        // ihrerseits gecacht und kann gegenüber der Platte veralten, wenn
        // eine Schema-Datei zwischen Listen-Aufbau und Lesen verschwindet.
        $file = $pluginSelfDir.'schemas/'.$type.'.json';
        if(!file_exists($file)) {
            return $this->schemaCache[$cacheKey] = null;
        }
        $schema = json_decode(file_get_contents($file), true);
        return $this->schemaCache[$cacheKey] = (is_array($schema) ? $schema : null);
    }

    /***************************************************************
    *
    * Löst ein "$ref": "#/definitions/..." innerhalb eines Feld-
    * Schemas auf und führt die referenzierten Properties mit den
    * lokalen (überschreibenden) Properties zusammen.
    *
    * @param array<string, mixed> $fieldSchema Schema des Feldes, ggf. mit "$ref"
    * @param array<string, mixed> $rootSchema  vollständiges Schema (für "definitions")
    * @return array<string, mixed> aufgelöstes Feld-Schema
    *
    ***************************************************************/
    public function resolveSchemaRef(array $fieldSchema, array $rootSchema): array {
        if(!isset($fieldSchema['$ref']) or !is_string($fieldSchema['$ref'])) {
            return $fieldSchema;
        }

        $ref = $fieldSchema['$ref'];
        if(!str_starts_with($ref, '#/')) {
            return $fieldSchema;
        }

        $resolved = $rootSchema;
        foreach(explode('/', substr($ref, 2)) as $segment) {
            if(!is_array($resolved) or !array_key_exists($segment, $resolved)) {
                return $fieldSchema;
            }
            $resolved = $resolved[$segment];
        }

        unset($fieldSchema['$ref']);
        return is_array($resolved) ? array_merge($resolved, $fieldSchema) : $fieldSchema;
    }

    /***************************************************************
    *
    * Liefert alle verfügbaren Schema-Types anhand der .json-Dateien
    * im Verzeichnis schemas/.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @return string[] Liste der Type-Namen (ohne .json), alphabetisch
    *
    ***************************************************************/
    public function getAvailableSchemaTypes(string $pluginSelfDir): array {
        if(array_key_exists($pluginSelfDir, $this->availableTypesCache)) {
            return $this->availableTypesCache[$pluginSelfDir];
        }

        $types = [];
        foreach(glob($pluginSelfDir.'schemas/*.json') as $file) {
            $types[] = basename($file, '.json');
        }
        sort($types);
        return $this->availableTypesCache[$pluginSelfDir] = $types;
    }

    /***************************************************************
    *
    * Ermittelt den aktiven Schema-Type einer Scope-Konfiguration
    * (erster Array-Schlüssel, der ein bekanntes Schema referenziert).
    * Die Verwaltungsschlüssel einer Ebene
    * (SchemaOrgData_ScopeResolver::MANAGEMENT_KEYS) fallen dabei ohne
    * eigene Prüfung heraus, weil loadSchema() für sie null liefert -
    * die Methode führt bewusst keine zweite Liste. Wird u. a. für die
    * LocalBusiness-Familie-Einschränkung verwendet, um den bei Global
    * konfigurierten Type zu ermitteln.
    *
    * @param array<string, mixed> $scopeConfig z. B. Ergebnis von
    *        SchemaOrgData_ScopeResolver::loadScopeConfig()
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @return string|null aktiver Type oder null, wenn keiner gefunden wurde
    *
    ***************************************************************/
    public function resolveActiveType(array $scopeConfig, string $pluginSelfDir): ?string {
        foreach(array_keys($scopeConfig) as $type) {
            if($this->loadSchema($pluginSelfDir, (string) $type) !== null) {
                return (string) $type;
            }
        }
        return null;
    }
}
