<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für Schema-Konsistenz über alle schemas/*.json hinweg:
*
*   - PostalAddress-Block (definitions.PostalAddress) muss in
*     jeder Datei, die ihn führt, strukturell identisch sein
*     (Länder-Enum, required, ui:*-Metadaten) — fängt Drift ab,
*     den der add-country-Skill riskiert, wenn eine Datei beim
*     Länder-Update vergessen wird.
*   - required[] und ui:required:true müssen über alle Ebenen
*     jeder Schema-Datei bidirektional konsistent sein.
*
***************************************************************/
final class SchemaConsistencyTest extends TestCase {

    /**
     * Dateien, die einen definitions.PostalAddress-Block führen
     * (per grep gegen "PostalAddress"/"ui:widget":"postal_address"
     * ermittelt, Stand Batch C).
     */
    private const POSTAL_ADDRESS_FILES = [
        'LocalBusiness.json',
        'LegalService.json',
        'MedicalBusiness.json',
        'AccountingService.json',
        'ProfessionalService.json',
        'NGO.json',
        'Organization.json',
        'JobPosting.json',
        'Event.json',
    ];

    private function schemaDir(): string {
        return BASE_DIR.PLUGIN_DIR_NAME.'/schemaOrgData/schemas/';
    }

    private function loadSchema(string $file): array {
        $schema = json_decode((string) file_get_contents($this->schemaDir().$file), true);
        $this->assertIsArray($schema, "$file: konnte nicht als JSON gelesen werden");
        return $schema;
    }

    // PostalAddress-Konsistenz --------------------------------------------------

    function testPostalAddressBlockIstIdentischUeberAlleTypes(): void {
        $referenceFile = self::POSTAL_ADDRESS_FILES[0];
        $reference = $this->loadPostalAddressDefinition($referenceFile);

        foreach (self::POSTAL_ADDRESS_FILES as $file) {
            if($file === $referenceFile) {
                continue;
            }

            $definition = $this->loadPostalAddressDefinition($file);

            $this->assertSame(
                json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                "Abweichung im PostalAddress-Block von $file gegenüber Referenz $referenceFile"
            );
        }
    }

    private function loadPostalAddressDefinition(string $file): array {
        $schema = $this->loadSchema($file);
        $definition = $schema['definitions']['PostalAddress'] ?? null;

        $this->assertIsArray($definition, "$file: definitions.PostalAddress fehlt");

        return $this->recursiveKsort($definition);
    }

    /**
     * Sortiert assoziative Schlüssel rekursiv, damit reine
     * Reihenfolge-Unterschiede (z. B. durch manuelle Bearbeitung
     * einer einzelnen Datei) keine falschen Abweichungen melden.
     * Listen (z. B. das Länder-Enum) bleiben in ihrer Reihenfolge
     * unangetastet, da diese fachlich relevant sein kann.
     */
    private function recursiveKsort(array $data): array {
        if(array_is_list($data)) {
            return array_map(
                fn($value) => is_array($value) ? $this->recursiveKsort($value) : $value,
                $data
            );
        }

        ksort($data);
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $data[$key] = $this->recursiveKsort($value);
            }
        }

        return $data;
    }

    // required <-> ui:required-Konsistenz ----------------------------------------

    function testRequiredUndUiRequiredSindUeberAlleSchemasKonsistent(): void {
        $errors = [];

        foreach(glob($this->schemaDir().'*.json') as $file) {
            $schema = $this->loadSchema(basename($file));
            $this->collectRequiredMismatches($schema, '', basename($file), $errors);
        }

        $this->assertSame([], $errors, implode("\n", $errors));
    }

    /**
     * Läuft rekursiv über properties/required (auch verschachtelt,
     * z. B. address/geo/jobLocation/location), items (z. B.
     * FAQPage.mainEntity) und definitions (z. B. PostalAddress).
     * $ref-Properties selbst tragen kein eigenes ui:required und
     * werden hier übersprungen — ihre Zieldefinition wird über den
     * definitions-Zweig separat geprüft. Properties ohne ui:widget
     * (reine Struktur-Container ohne eigenes Formularfeld, z. B.
     * FAQPage.mainEntity.items.acceptedAnswer — nur dessen
     * verschachteltes "text"-Feld wird tatsächlich gerendert, siehe
     * SchemaOrgData_FormRenderer::renderFaqListWidget()) werden von
     * der required<->ui:required-Prüfung ausgenommen, da ui:required
     * ausschließlich als Renderer-Metadaten für ein Widget existiert.
     */
    private function collectRequiredMismatches(array $node, string $path, string $fileLabel, array &$errors): void {
        if(isset($node['properties']) && is_array($node['properties'])) {
            $required = is_array($node['required'] ?? null) ? $node['required'] : [];

            foreach($node['properties'] as $propName => $propSchema) {
                if(!is_array($propSchema)) {
                    continue;
                }

                $propPath = $path === '' ? $propName : "$path.$propName";

                if(array_key_exists('ui:widget', $propSchema)) {
                    $isRequired = in_array($propName, $required, true);
                    $uiRequired = ($propSchema['ui:required'] ?? null) === true;

                    if($isRequired && !$uiRequired) {
                        $errors[] = "$fileLabel: '$propPath' ist in required, aber ui:required fehlt/false";
                    }
                    if($uiRequired && !$isRequired) {
                        $errors[] = "$fileLabel: '$propPath' hat ui:required=true, ist aber nicht im required-Array";
                    }
                }

                $this->collectRequiredMismatches($propSchema, $propPath, $fileLabel, $errors);
            }
        }

        if(isset($node['items']) && is_array($node['items'])) {
            $this->collectRequiredMismatches($node['items'], "$path"."[]", $fileLabel, $errors);
        }

        if(isset($node['definitions']) && is_array($node['definitions'])) {
            foreach($node['definitions'] as $defName => $defSchema) {
                if(is_array($defSchema)) {
                    $this->collectRequiredMismatches($defSchema, "definitions.$defName", $fileLabel, $errors);
                }
            }
        }
    }
}
