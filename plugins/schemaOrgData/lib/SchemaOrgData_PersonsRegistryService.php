<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_PersonsRegistryService
*
* Personen-Registry: eigenständiges, settings-basiertes Verzeichnis
* (Settings-Key "persons_registry", siehe loadRegistry()) orthogonal
* zum Scope-Modell (Global/Kategorie/Seite) - keine Vererbung, kein
* Eintrag im Type-Dropdown, keine Berührung von
* SchemaOrgData_ScopeResolver/resolveTypeInheritance()/
* SchemaOrgData_CollisionDetector.
*
* Der Slug (Array-Key der Registry) ist zugleich die ID der Person
* und nach Erstanlage unveränderlich (stabile Grundlage für spätere
* @id-Fragmente). Kapselt Slug-Bildung/-Sanitizing, Bereinigung und
* Validierung der Formularfelder sowie CRUD gegen $this->settings.
*
* Zustandslos: Kollaboratoren (Language, SchemaOrgData_Validator,
* $settings) werden je Aufruf als Parameter übergeben, nicht im
* Konstruktor eingefroren (siehe README.md, Abschnitt "Entwicklerdokumentation").
*
***************************************************************/
class SchemaOrgData_PersonsRegistryService {

    /** Settings-Key der Personen-Registry (siehe README.md, Personen-Registry). */
    public const SETTINGS_KEY = 'persons_registry';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];
    private const DEFAULT_SORT_ORDER = 100;

    /***************************************************************
    *
    * Lädt die vollständige Personen-Registry.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array<string, array<string, mixed>> Slug => Person-Properties
    *
    ***************************************************************/
    public function loadRegistry($settings): array {
        if(!$settings->keyExists(self::SETTINGS_KEY)) {
            return [];
        }

        $data = $settings->get(self::SETTINGS_KEY);
        return is_array($data) ? $data : [];
    }

    /***************************************************************
    *
    * Lädt eine einzelne Person anhand ihres Slugs.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    *
    ***************************************************************/
    public function getPerson($settings, string $slug): ?array {
        $registry = $this->loadRegistry($settings);
        return is_array($registry[$slug] ?? null) ? $registry[$slug] : null;
    }

    /***************************************************************
    *
    * Prüft, ob ein Slug bereits in der Registry vergeben ist.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    *
    ***************************************************************/
    public function slugExists($settings, string $slug): bool {
        return array_key_exists($slug, $this->loadRegistry($settings));
    }

    /***************************************************************
    *
    * Leitet aus einem Personennamen einen Slug-Vorschlag ab:
    * Kleinschreibung, Umlaut-Transliteration (ä→ae, ö→oe, ü→ue,
    * ß→ss), Leerzeichen/übrige Sonderzeichen → Bindestrich, führende/
    * abschließende Bindestriche entfernt. Rein clientseitiger
    * Vorschlag - maßgeblich bleibt sanitizeSlugCandidate() auf dem
    * tatsächlich vom Nutzer übernommenen bzw. angepassten Wert.
    *
    ***************************************************************/
    public function generateSlugSuggestion(string $name): string {
        $value = trim($name);
        $value = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
            $value
        );
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-');
    }

    /***************************************************************
    *
    * Bereinigt einen vom Nutzer eingegebenen/übernommenen Slug-Wert
    * auf die zulässige Zeichenmenge (Buchstaben, Ziffern, Bindestrich,
    * Unterstrich - Zeichenregel analog
    * SchemaOrgData_ScopeResolver::sanitizeScopeIdentifier()), stets
    * kleingeschrieben (Slugs sind rein technische Bezeichner, keine
    * benutzersichtbaren Freitextwerte).
    *
    ***************************************************************/
    public function sanitizeSlugCandidate(string $value): string {
        $value = strtolower(trim($value));
        // Leerraum wird vor dem Zeichenfilter in Bindestriche umgewandelt,
        // damit ein manuell eingegebener Slug-Wert ("Max Mustermann") nicht
        // zu einem verklebten "maxmustermann" wird, sondern zum erwarteten
        // "max-mustermann" (analog generateSlugSuggestion()).
        $value = (string) preg_replace('/\s+/', '-', $value);
        return (string) preg_replace('/[^a-z0-9_\-]/', '', $value);
    }

    /***************************************************************
    *
    * Bereinigt einen relativen Medienpfad (Feld "image", sofern keine
    * absolute URL): keine Pfad-Traversal-Segmente ("..", "."), keine
    * führenden Slashes, je Pfadsegment nur Dateisystem-sichere Zeichen
    * (Buchstaben, Ziffern, Bindestrich, Unterstrich, Punkt - Muster
    * sanitizeSlugCandidate()/sanitizeScopeIdentifier() um "." für
    * Dateiendungen erweitert). Wird sowohl beim Speichern (der
    * bereinigte Wert wird persistiert) als auch vor der
    * Existenzprüfung (validatePersonImageExistence()) verwendet, damit
    * ein manipulierter Pfad nicht zur Existenzprüfung beliebiger
    * Dateien außerhalb des Medienverzeichnisses missbraucht werden kann.
    *
    ***************************************************************/
    public function sanitizeRelativeMediaPath(string $value): string {
        $value = str_replace('\\', '/', trim($value));
        $value = ltrim($value, '/');

        $cleanSegments = [];
        foreach(explode('/', $value) as $segment) {
            $segment = (string) preg_replace('/[^a-zA-Z0-9_\-.]/', '', $segment);
            if($segment === '' or $segment === '.' or $segment === '..') {
                continue;
            }
            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments);
    }

    /***************************************************************
    *
    * Bereinigt die POST-Rohdaten eines Personen-Formulars (Trim/
    * strip_tags analog SchemaOrgData_ConfigSaveService::sanitizePostData()):
    * sameAs und knowsAbout werden je zeilenweise aufgeteilt, Leerzeilen
    * getilgt (knowsAbout ohne URL-Validierung - reine Freitext-Themen);
    * ein relativer image-Pfad wird über sanitizeRelativeMediaPath()
    * bereinigt (absolute URLs bleiben unverändert); status wird gegen
    * die Whitelist geprüft (Default STATUS_ACTIVE); sortOrder wird als
    * Integer interpretiert (Default 100 bei leerem/ungültigem Wert).
    *
    * @param array<string, mixed> $raw POST-Rohdaten (schemaOrgData_persons_data)
    * @return array<string, mixed> bereinigte Person-Properties, bereit für die Registry
    *
    ***************************************************************/
    public function sanitizePersonData(array $raw): array {
        $result = [
            'name'            => trim(strip_tags((string) ($raw['name'] ?? ''))),
            'honorificPrefix' => trim(strip_tags((string) ($raw['honorificPrefix'] ?? ''))),
            'jobTitle'        => trim(strip_tags((string) ($raw['jobTitle'] ?? ''))),
            'description'     => trim(strip_tags((string) ($raw['description'] ?? ''))),
            'url'             => trim(strip_tags((string) ($raw['url'] ?? ''))),
        ];

        $imageRaw = trim(strip_tags((string) ($raw['image'] ?? '')));
        $result['image'] = (preg_match('#^https?://#i', $imageRaw) === 1)
            ? $imageRaw
            : $this->sanitizeRelativeMediaPath($imageRaw);

        $sameAs = [];
        foreach(preg_split('/\r\n|\r|\n/', (string) ($raw['sameAs'] ?? '')) as $line) {
            $line = trim(strip_tags($line));
            if($line !== '') {
                $sameAs[] = $line;
            }
        }
        $result['sameAs'] = $sameAs;

        $knowsAbout = [];
        foreach(preg_split('/\r\n|\r|\n/', (string) ($raw['knowsAbout'] ?? '')) as $line) {
            $line = trim(strip_tags($line));
            if($line !== '') {
                $knowsAbout[] = $line;
            }
        }
        $result['knowsAbout'] = $knowsAbout;

        $status = (string) ($raw['status'] ?? self::STATUS_ACTIVE);
        $result['status'] = in_array($status, self::STATUSES, true) ? $status : self::STATUS_ACTIVE;

        $sortOrderRaw = trim((string) ($raw['sortOrder'] ?? ''));
        $result['sortOrder'] = ($sortOrderRaw !== '' and preg_match('/^-?[0-9]+$/', $sortOrderRaw) === 1)
            ? (int) $sortOrderRaw
            : self::DEFAULT_SORT_ORDER;

        return $result;
    }

    /***************************************************************
    *
    * Validiert bereits bereinigte Personen-Properties (siehe
    * sanitizePersonData()): name ist Pflichtfeld, url/sameAs/absolute
    * image-URL werden über SchemaOrgData_Validator::validateUrl()
    * geprüft. Die Existenzprüfung eines relativen image-Pfads
    * (validatePersonImageExistence()) ist bewusst NICHT Teil dieser
    * Methode - sie liefert nur eine nicht blockierende Warnung (siehe
    * checkImageAvailability()), kein Speicherhindernis.
    *
    * @param array<string, mixed> $sanitized Rückgabe von sanitizePersonData()
    * @return string[] Fehlermeldungen (leer = alle Prüfungen ok)
    *
    ***************************************************************/
    public function validatePersonData(array $sanitized, Language $lang, SchemaOrgData_Validator $validator): array {
        $errors = [];

        if(trim((string) ($sanitized['name'] ?? '')) === '') {
            $errors[] = $lang->getLanguageValue('error_required_field', $lang->getLanguageValue('label_person_name'));
        }

        $url = (string) ($sanitized['url'] ?? '');
        if($url !== '') {
            $result = $validator->validateUrl($url, $lang);
            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        $image = (string) ($sanitized['image'] ?? '');
        if($image !== '' and preg_match('#^https?://#i', $image) === 1) {
            $result = $validator->validateUrl($image, $lang);
            if($result['status'] === 'error') {
                $errors[] = $result['message'];
            }
        }

        $errors = array_merge($errors, $validator->validateSameAsEntries(
            is_array($sanitized['sameAs'] ?? null) ? $sanitized['sameAs'] : [], $lang
        ));

        return $errors;
    }

    /***************************************************************
    *
    * Ermittelt, ob für ein relatives image (nicht für absolute URLs)
    * eine nicht blockierende "Datei nicht gefunden"-Warnung angezeigt
    * werden soll (siehe SchemaOrgData_Validator::validatePersonImageExistence()).
    *
    * @return array{status: string|null, message: string|null}
    *
    ***************************************************************/
    public function checkImageAvailability(string $image, Language $lang, SchemaOrgData_Validator $validator, SchemaOrgData_UrlHelper $urlHelper): array {
        if($image === '' or preg_match('#^https?://#i', $image) === 1) {
            return ['status' => null, 'message' => null];
        }

        return $validator->validatePersonImageExistence($image, $urlHelper->resolveMediaBaseDir(), $lang);
    }

    /***************************************************************
    *
    * Legt eine neue Person an. Der Slug wird - falls im Formular
    * angepasst - aus $rawData['slug'] übernommen (sanitizeSlugCandidate()),
    * sonst aus dem Namen abgeleitet (generateSlugSuggestion()). Ein
    * leerer oder bereits vergebener Slug ist ein Fehler; die
    * Fehlermeldung bei Kollision nennt den Namen der bereits
    * vorhandenen Person.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param array<string, mixed> $rawData POST-Rohdaten (schemaOrgData_persons_data)
    * @return array{success: bool, errors: string[], slug: string|null}
    *
    ***************************************************************/
    public function createPerson($settings, array $rawData, Language $lang, SchemaOrgData_Validator $validator): array {
        $sanitized = $this->sanitizePersonData($rawData);
        $errors = $this->validatePersonData($sanitized, $lang, $validator);

        $slugCandidate = trim((string) ($rawData['slug'] ?? ''));
        $slug = $slugCandidate !== ''
            ? $this->sanitizeSlugCandidate($slugCandidate)
            : $this->generateSlugSuggestion($sanitized['name']);

        if($slug === '') {
            $errors[] = $lang->getLanguageValue('error_person_slug_required');
        } elseif($this->slugExists($settings, $slug)) {
            $existing = $this->getPerson($settings, $slug);
            $existingName = ($existing !== null and trim((string) ($existing['name'] ?? '')) !== '')
                ? (string) $existing['name']
                : $slug;
            $errors[] = $lang->getLanguageValue('error_person_slug_exists', $existingName);
        }

        if($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'slug' => null];
        }

        $registry = $this->loadRegistry($settings);
        $registry[$slug] = $sanitized;
        $this->saveRegistry($settings, $registry);

        return ['success' => true, 'errors' => [], 'slug' => $slug];
    }

    /***************************************************************
    *
    * Aktualisiert eine bestehende Person. Der Slug ist nach Erstanlage
    * unveränderlich - ein etwaiger $rawData['slug']-Wert wird
    * ignoriert, das Zielobjekt wird ausschließlich über den Parameter
    * $slug ermittelt.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param array<string, mixed> $rawData POST-Rohdaten (schemaOrgData_persons_data)
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function updatePerson($settings, string $slug, array $rawData, Language $lang, SchemaOrgData_Validator $validator): array {
        if(!$this->slugExists($settings, $slug)) {
            return ['success' => false, 'errors' => [$lang->getLanguageValue('error_person_not_found')]];
        }

        $sanitized = $this->sanitizePersonData($rawData);
        $errors = $this->validatePersonData($sanitized, $lang, $validator);

        if($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $registry = $this->loadRegistry($settings);
        $registry[$slug] = $sanitized;
        $this->saveRegistry($settings, $registry);

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Löscht eine Person aus der Registry. Idempotent - ein bereits
    * nicht (mehr) vorhandener Slug ist kein Fehler (analog
    * SchemaOrgData_ScopeResolver::deleteConfig()).
    *
    * Referenzen auf den gelöschten Slug (org_relations, Artikel-Autor
    * u. a.) werden hier NICHT geprüft/bereinigt - siehe findReferences().
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function deletePerson($settings, string $slug): array {
        $registry = $this->loadRegistry($settings);

        if(!array_key_exists($slug, $registry)) {
            return ['success' => true, 'errors' => []];
        }

        unset($registry[$slug]);
        $this->saveRegistry($settings, $registry);

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Baut das @id-Fragment einer Registry-Person aus ihrem Slug
    * (siehe README.md, Abschnitt "Organisations-Identität und @id-Anker").
    * Rein slug-basiert - keine Type-Namen-Kopplung im PHP.
    *
    ***************************************************************/
    public static function buildFragment(string $slug): string {
        return 'person-'.$slug;
    }

    /***************************************************************
    *
    * Vervollständigt einen relativen image-Pfad (bereits über
    * sanitizeRelativeMediaPath() bereinigt) zu einer absoluten URL für
    * die JSON-LD-Ausgabe, gespiegelt zur lokalen Existenzprüfung in
    * checkImageAvailability() (dort resolveMediaBaseDir(), hier
    * resolveMediaBaseUrl() - beide kombinieren dieselbe relative
    * Pfadangabe mit dem jeweils passenden Basis-Pendant). Absolute
    * URLs (http(s)://) bleiben unverändert. Lässt sich keine Basis-URL
    * auflösen, bleibt der Rohwert unverändert (kein leerer/kaputter
    * Link).
    *
    ***************************************************************/
    public function resolveAbsoluteImageUrl(string $image, SchemaOrgData_UrlHelper $urlHelper): string {
        if($image === '' or preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        $mediaBaseUrl = $urlHelper->resolveMediaBaseUrl();
        return $mediaBaseUrl !== '' ? $mediaBaseUrl.$image : $image;
    }

    /***************************************************************
    *
    * Erweiterungspunkt für eine Fundstellen-Prüfung vor dem Löschen
    * einer Person (org_relations, gespeicherter Artikel-Autor u. a.).
    * Beide Datenquellen existieren zum jetzigen Umsetzungsstand noch
    * nicht (folgen in AP3/AP4, siehe README.md) - liefert daher bewusst
    * immer ein leeres Ergebnis. Für V1 dieses Pakets genügt ein
    * einfacher Bestätigungsdialog vor dem Löschen (siehe
    * SchemaOrgData_PersonsAdminRenderer, JS-confirm() am Löschen-Button).
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @return string[] lesbare Fundstellen-Beschreibungen, aktuell immer leer
    *
    ***************************************************************/
    public function findReferences($settings, string $slug): array {
        return [];
    }

    /***************************************************************
    *
    * Persistiert die vollständige Registry über die moziloCMS-
    * Settings-API (analog SchemaOrgData_ScopeResolver::saveScopeMeta()).
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param array<string, array<string, mixed>> $registry vollständige Registry
    *
    ***************************************************************/
    private function saveRegistry($settings, array $registry): void {
        try {
            $settings->set(self::SETTINGS_KEY, $registry);
        } catch (\Throwable $e) {
            error_log('schemaOrgData: Personen-Registry speichern fehlgeschlagen: '.$e->getMessage());
        }
    }
}
