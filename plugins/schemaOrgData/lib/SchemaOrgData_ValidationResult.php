<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_ValidationResult
*
* Ergebnis-Objekt der Validierungsphase in SchemaOrgData_ConfigSaveService
* (Fahrplan-Schritt 7, siehe doc/adr_ziel_architektur.md, Abschnitt 4):
* ersetzt implizite Rückgabewerte durch ein explizites, unveränderliches
* Objekt. Wird ausschließlich intern von SchemaOrgData_ConfigSaveService
* verwendet - die äußere Rückgabe von saveConfig() bleibt unverändert
* array{success: bool, errors: string[]} (siehe SchemaOrgData_AdminRequestHandler,
* das diese Struktur konsumiert und unverändert bleibt).
*
* $extensionData transportiert die bereits dekodierten Erweiterungsfeld-
* Properties von der Validierungs- in die Normalisierungsphase, damit
* json_decode() nicht doppelt aufgerufen werden muss.
*
***************************************************************/
final class SchemaOrgData_ValidationResult {
    public function __construct(
        public readonly bool $success,
        public readonly array $errors,
        public readonly array $extensionData = []
    ) {}
}
