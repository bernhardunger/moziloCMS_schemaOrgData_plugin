<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_ImportService
*
* Parst einen vom Nutzer eingefügten JSON-LD-Block und trennt die
* enthaltenen Properties per SchemaOrgData_DataSplitHelper in
* bekannte Formularfelder und unbekannte Erweiterungs-Properties.
* Zustandslos, keine CMS-Abhängigkeiten.
*
***************************************************************/
class SchemaOrgData_ImportService {

    /***************************************************************
    *
    * Importiert einen vorhandenen JSON-LD-Block.
    *
    * Zerlegt die Properties anhand des aktiven Schemas in bekannte
    * Formularfelder und unbekannte Properties (Erweiterungsfeld).
    * Es erfolgt kein Merge mit der aktuellen Konfiguration - der
    * Aufrufer (Admin-Formular) ersetzt die Konfiguration vollständig
    * mit dem Ergebnis dieser Methode.
    *
    * @param string $jsonLdText Inhalt des Import-Textarea-Felds
    * @param array<string, mixed>|null $schema aktives JSON-Schema (schemas/{Type}.json)
    * @param SchemaOrgData_DataSplitHelper $dataSplitHelper Mapper für die Formular-/Erweiterungsfeld-Trennung
    * @return array{
    *   success: bool,
    *   error: string|null,
    *   type: string|null,
    *   formData: array<string, mixed>,
    *   extensionData: array<string, mixed>
    * }
    *
    ***************************************************************/
    public function importJsonLd(string $jsonLdText, ?array $schema, SchemaOrgData_DataSplitHelper $dataSplitHelper): array {
        $data = json_decode($jsonLdText, true);

        if(json_last_error() !== JSON_ERROR_NONE or !is_array($data)) {
            return [
                'success' => false,
                'error' => json_last_error_msg(),
                'type' => null,
                'formData' => [],
                'extensionData' => [],
            ];
        }

        $type = $data['@type'] ?? null;
        unset($data['@context'], $data['@type']);

        $split = $dataSplitHelper->splitDataForRendering($data, $schema);

        return [
            'success' => true,
            'error' => null,
            'type' => $type,
            'formData' => $split['form'],
            'extensionData' => $split['extension'],
        ];
    }
}
