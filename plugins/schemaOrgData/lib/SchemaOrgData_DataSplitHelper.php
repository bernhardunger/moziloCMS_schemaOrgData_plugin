<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_DataSplitHelper
*
* Trennt gespeicherte bzw. importierte Properties eines Schema-Types
* anhand des aktiven JSON-Schemas in bekannte Formularfelder und
* unbekannte Erweiterungs-Properties auf. Geteilter Mapper für
* FormRenderer (renderTypeFields()) und ImportService (importJsonLd()).
* Zustandslos, keine CMS-Abhängigkeiten.
*
***************************************************************/
class SchemaOrgData_DataSplitHelper {

    /***************************************************************
    *
    * Trennt gespeicherte Properties eines Schema-Types in
    * Formularfelder (im Schema definiert) und Erweiterungs-
    * Properties (nicht im Schema definiert) auf - Umkehrung des
    * beim Speichern durchgeführten Merge.
    *
    * @param array<string, mixed> $data gespeicherte Properties eines Schema-Types
    * @param array<string, mixed>|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @return array{form: array<string, mixed>, extension: array<string, mixed>}
    *
    ***************************************************************/
    public function splitDataForRendering(array $data, ?array $schema): array {
        // Ein Schema, dessen properties keine Array-Form hat, gilt wie ein
        // fehlendes Schema: keine bekannten Properties, alles landet in der
        // Erweiterung - statt array_keys() in einen TypeError laufen zu lassen.
        $properties = $schema['properties'] ?? null;
        $knownProperties = is_array($properties) ? array_keys($properties) : [];
        $form = [];
        $extension = [];

        foreach($data as $property => $value) {
            if(in_array($property, $knownProperties, true)) {
                $form[$property] = $value;
            } else {
                $extension[$property] = $value;
            }
        }

        return ['form' => $form, 'extension' => $extension];
    }
}
