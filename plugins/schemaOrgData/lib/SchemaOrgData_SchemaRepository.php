<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_SchemaRepository
*
* Lädt und löst JSON-Schema-Dateien aus schemas/ auf. Wird von
* der Fassade schemaOrgData über einen Lazy-Accessor verdrahtet
* (siehe README.md, Abschnitt "Schema-getriebenes Formular").
*
* Zustandslos - kein Caching (siehe README.md).
*
***************************************************************/
class SchemaOrgData_SchemaRepository {

    /***************************************************************
    *
    * Lädt ein JSON-Schema aus schemas/{type}.json.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @return array|null dekodiertes Schema oder null bei Fehler
    *
    ***************************************************************/
    public function loadSchema(string $pluginSelfDir, string $type): ?array {
        $file = $pluginSelfDir.'schemas/'.basename($type).'.json';
        if(!file_exists($file)) {
            return null;
        }
        $schema = json_decode(file_get_contents($file), true);
        return is_array($schema) ? $schema : null;
    }

    /***************************************************************
    *
    * Löst ein "$ref": "#/definitions/..." innerhalb eines Feld-
    * Schemas auf und führt die referenzierten Properties mit den
    * lokalen (überschreibenden) Properties zusammen.
    *
    * @param array $fieldSchema Schema des Feldes, ggf. mit "$ref"
    * @param array $rootSchema  vollständiges Schema (für "definitions")
    * @return array aufgelöstes Feld-Schema
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
    * @return array Liste der Type-Namen (ohne .json), alphabetisch
    *
    ***************************************************************/
    public function getAvailableSchemaTypes(string $pluginSelfDir): array {
        $types = [];
        foreach(glob($pluginSelfDir.'schemas/*.json') as $file) {
            $types[] = basename($file, '.json');
        }
        sort($types);
        return $types;
    }
}
