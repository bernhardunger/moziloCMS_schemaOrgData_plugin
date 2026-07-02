<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_SchemaRepository
*
* Lädt und löst JSON-Schema-Dateien aus schemas/ auf. Wird von
* der Fassade schemaOrgData über einen Lazy-Accessor verdrahtet
* (siehe README.md, Abschnitt "Schema-getriebenes Formular").
*
* Zustandslos gegenüber der Fassade; cacht Schema-Lesevorgänge für
* die Lebensdauer der Instanz (siehe README.md, Abschnitt
* "Schema-getriebenes Formular", und doc/adr_komponenten_refactoring.md,
* Entscheidung (k)).
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
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @return array<string, mixed>|null dekodiertes Schema oder null bei Fehler
    *
    ***************************************************************/
    public function loadSchema(string $pluginSelfDir, string $type): ?array {
        $cacheKey = $pluginSelfDir.'|'.$type;
        // array_key_exists() statt isset(): auch ein gecachtes null-Ergebnis
        // (Schema nicht gefunden) gilt als Cache-Treffer, siehe
        // doc/adr_komponenten_refactoring.md, Entscheidung (k).
        if(array_key_exists($cacheKey, $this->schemaCache)) {
            return $this->schemaCache[$cacheKey];
        }

        $file = $pluginSelfDir.'schemas/'.basename($type).'.json';
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
}
