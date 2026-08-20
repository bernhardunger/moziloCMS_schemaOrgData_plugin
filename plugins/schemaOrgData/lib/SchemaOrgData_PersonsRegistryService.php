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

    /**
     * Bindung an den Verwaltungsschlüssel aus
     * SchemaOrgData_ScopeResolver - das Literal steht dort an einer Stelle,
     * hier nur der Verweis darauf.
     */
    private const KEY_ORG_RELATIONS = SchemaOrgData_ScopeResolver::KEY_ORG_RELATIONS;

    // Settings-Key des globalen Geltungsbereichs, gespiegelt aus
    // SchemaOrgData_ScopeResolver::getScopeSettingsKey('global') - nur für die
    // rein lesende Fundstellen-Prüfung in findReferences(). Bewusst keine
    // Aufnahme des ScopeResolver in die Signatur: die Registry ist orthogonal
    // zum Scope-Modell und soll es bleiben.
    private const GLOBAL_CONFIG_KEY = SchemaOrgData_ScopeResolver::KEY_CONFIG_GLOBAL;

    public const STATUS_ACTIVE      = 'active';
    public const STATUS_INACTIVE    = 'inactive';

    /**
     * Identität des Registry-Personen-Knotens: SCHEMA_TYPE_PERSON ist der
     * schema.org-Type, unter dem eine Registry-Person ausgegeben wird,
     * PREFIX_PERSON das Präfix ihres @id-Fragments (aufgebaut in
     * buildFragment(), zurückgelesen in isPersonFragment() und
     * slugFromPersonFragment()). Beide Werte haben eine Gegenstelle in
     * js/validator.js, die nicht an diese Konstanten gebunden ist - eine
     * Änderung hier verlangt den Abgleich dort.
     */
    public const SCHEMA_TYPE_PERSON = 'Person';
    public const PREFIX_PERSON      = 'person-';

    /**
     * Bindung an SchemaOrgData_Validator - das Literal steht dort an einer
     * Stelle, hier nur der Verweis darauf.
     */
    private const SHARED_PATTERN_URL_SCHEME = SchemaOrgData_Validator::SHARED_PATTERN_URL_SCHEME;

    /**
     * Muster der Sortiernummer. Steht wortgleich in js/validator.js und
     * wird von dort aus bewacht.
     */
    private const SHARED_PATTERN_SORT_ORDER = '/^-?[0-9]+$/';

    private const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * Vorbelegung von sortOrder für Personen ohne eigenen Wert.
     * Öffentlich, weil SchemaOrgData_IdReferenceService und
     * SchemaOrgData_PersonsAdminRenderer dieselbe Vorbelegung für ihre
     * Sortierung bzw. Formularanzeige brauchen.
     */
    public const DEFAULT_SORT_ORDER = 100;

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
    * Wandelt die deutschen Umlaute und das Eszett in ihre
    * ASCII-Entsprechungen (ä→ae, ö→oe, ü→ue, ß→ss). Einzige
    * Transliterationsquelle beider Slug-Wege - der abgeleitete
    * Vorschlag und der vom Nutzer getippte Wert müssen für denselben
    * Eingabetext denselben Slug ergeben, sonst schlägt der
    * Live-Vorschlag etwas anderes vor, als der Server speichert.
    *
    * Muss vor der Kleinschreibung laufen: strtolower() arbeitet
    * ASCII-beschränkt und lässt "Ä"/"Ö"/"Ü" unangetastet - liefe die
    * Transliteration danach, bliebe aus "Ä" ein "Ae" stehen, dessen
    * großes "A" der nachfolgende Zeichenfilter zu einem Bindestrich
    * verkehrte ("Ärztin" ergäbe "erztin" statt "aerztin").
    *
    ***************************************************************/
    private function transliterateSlugInput(string $value): string {
        return str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
            $value
        );
    }

    /***************************************************************
    *
    * Bildet die Zeichenregel eines Slugs ab und ist deren einzige
    * Stelle: Zulässig sind lateinische Buchstaben, Ziffern,
    * Bindestrich und Unterstrich, alles kleingeschrieben. Jede
    * zusammenhängende Folge unzulässiger Zeichen wird zu genau einem
    * Bindestrich, führende und abschließende Bindestriche entfallen.
    *
    * Beide öffentlichen Slug-Wege - generateSlugSuggestion() aus dem
    * Namen und sanitizeSlugCandidate() aus einer Eingabe - teilen
    * diese Methode und liefern deshalb für denselben Eingabetext
    * denselben Slug. Eine je Weg gespiegelte Regel driftet
    * auseinander, sobald einer von beiden angefasst wird.
    *
    * Die Transliteration läuft vor der Kleinschreibung, Begründung
    * siehe transliterateSlugInput().
    *
    * Die abschließende Alphanumerik-Prüfung tritt neben den
    * Rand-Trim, nicht an seine Stelle: Der Trim allein ließe "-_-"
    * durch, weil der Unterstrich zum erlaubten Zeichenvorrat gehört
    * und der Trim nur Bindestriche abträgt. Ein solcher Slug erzeugte
    * dauerhaft das @id-Fragment "#person-_", denn der Slug ist nach
    * Erstanlage unveränderlich.
    *
    ***************************************************************/
    private function normalizeSlug(string $value): string {
        $value = $this->transliterateSlugInput(trim($value));
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9_]+/', '-', $value);
        $value = trim($value, '-');

        return preg_match('/[a-z0-9]/', $value) === 1 ? $value : '';
    }

    /***************************************************************
    *
    * Leitet aus einem Personennamen einen Slug-Vorschlag ab. Die
    * Zeichenregel steht in normalizeSlug().
    *
    * Der Zeichenvorrat eines Slugs ist bewusst auf ASCII beschränkt
    * (lateinische Buchstaben, Ziffern, Bindestrich, Unterstrich) -
    * keine Nebenwirkung des Zeichenfilters, sondern eine Festlegung:
    * der Slug ist zugleich das @id-Fragment der Person und nach
    * Erstanlage unveränderlich. Eine Transliteration über
    * iconv('ASCII//TRANSLIT') oder intl kommt dafür nicht in Frage,
    * weil beide plattformabhängig sind und auf Shared Hosting nicht
    * zugesichert werden können. Ein Name ganz ohne lateinische
    * Zeichen ergibt daher einen leeren Slug; das Formular verlangt
    * dann eine eigene Kennung.
    *
    ***************************************************************/
    public function generateSlugSuggestion(string $name): string {
        return $this->normalizeSlug($name);
    }

    /***************************************************************
    *
    * Bereinigt einen vom Nutzer eingegebenen/übernommenen Slug-Wert.
    * Die Zeichenregel steht in normalizeSlug(); weil beide Slug-Wege
    * dieselbe Normalisierung teilen, liefert diese Methode für
    * denselben Eingabetext denselben Slug wie
    * generateSlugSuggestion() - welcher der beiden Wege den Wert
    * erzeugt hat, ist am Ergebnis nicht mehr ablesbar. Zur bewussten
    * ASCII-Beschränkung siehe dort.
    *
    * Der Slug ist stets kleingeschrieben, denn er ist ein rein
    * technischer Bezeichner, kein benutzersichtbarer Freitextwert.
    * Allein der Zeichenvorrat (lateinische Buchstaben, Ziffern,
    * Bindestrich, Unterstrich) ist analog
    * SchemaOrgData_ScopeResolver::sanitizeScopeIdentifier() - dessen
    * Ersetzungsregel ist eine andere: es erhält Großschreibung sowie
    * "%" und tilgt Umlaute ersatzlos.
    *
    * Bleibt kein einziges alphanumerisches Zeichen übrig, gilt die
    * Kennung als nicht angegeben und der Rückgabewert ist der
    * Leerstring. Der Rand-Trim allein deckte das nicht ab: Er trägt nur
    * Bindestriche ab, weshalb "_" und "-_-" als Slug bestehen blieben
    * und dauerhaft das @id-Fragment "#person-_" ergäben - der Slug ist
    * nach Erstanlage unveränderlich. Die Fehlermeldung bei leerer
    * Kennung sagt zudem zu, dass eine Kennung lateinische Buchstaben
    * oder Ziffern enthalten muss - eine Zusage, die der Code sonst
    * nicht einhielte.
    *
    ***************************************************************/
    public function sanitizeSlugCandidate(string $value): string {
        return $this->normalizeSlug($value);
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
            'name'            => trim(strip_tags($this->scalarFieldValue($raw['name'] ?? null))),
            'honorificPrefix' => trim(strip_tags($this->scalarFieldValue($raw['honorificPrefix'] ?? null))),
            'jobTitle'        => trim(strip_tags($this->scalarFieldValue($raw['jobTitle'] ?? null))),
            'description'     => trim(strip_tags($this->scalarFieldValue($raw['description'] ?? null))),
            'url'             => trim(strip_tags($this->scalarFieldValue($raw['url'] ?? null))),
        ];

        $imageRaw = trim(strip_tags($this->scalarFieldValue($raw['image'] ?? null)));
        $result['image'] = (preg_match(self::SHARED_PATTERN_URL_SCHEME, $imageRaw) === 1)
            ? $imageRaw
            : $this->sanitizeRelativeMediaPath($imageRaw);

        $sameAs = [];
        foreach(preg_split('/\r\n|\r|\n/', $this->scalarFieldValue($raw['sameAs'] ?? null)) as $line) {
            $line = trim(strip_tags($line));
            if($line !== '') {
                $sameAs[] = $line;
            }
        }
        $result['sameAs'] = $sameAs;

        $knowsAbout = [];
        foreach(preg_split('/\r\n|\r|\n/', $this->scalarFieldValue($raw['knowsAbout'] ?? null)) as $line) {
            $line = trim(strip_tags($line));
            if($line !== '') {
                $knowsAbout[] = $line;
            }
        }
        $result['knowsAbout'] = $knowsAbout;

        $status = $this->scalarFieldValue($raw['status'] ?? self::STATUS_ACTIVE);
        $result['status'] = in_array($status, self::STATUSES, true) ? $status : self::STATUS_ACTIVE;

        $sortOrderRaw = trim($this->scalarFieldValue($raw['sortOrder'] ?? null));
        $result['sortOrder'] = ($sortOrderRaw !== '' and preg_match(self::SHARED_PATTERN_SORT_ORDER, $sortOrderRaw) === 1)
            ? (int) $sortOrderRaw
            : self::DEFAULT_SORT_ORDER;

        return $result;
    }

    /***************************************************************
    *
    * Liefert einen einzelnen POST-Teilwert als String - einen
    * nicht-skalaren Wert (untergeschobenes Array/Objekt) jedoch als
    * Leerstring, also so, als wäre das Feld gar nicht gesendet worden.
    * Idiom-Gegenstück zum is_scalar()-Guard in
    * SchemaOrgData_ConfigSaveService::sanitizePostData(), der dort die
    * Feldschleife per continue überspringt; hier werden feste
    * Schlüssel befüllt, weshalb der Leerstring an die Stelle des
    * übersprungenen Felds tritt. Ohne den Guard schriebe der Cast das
    * Ersatzliteral "Array" in die Registry (plus PHP-Warnung).
    *
    ***************************************************************/
    private function scalarFieldValue(mixed $value): string {
        return is_scalar($value) ? (string) $value : '';
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
            if($result['status'] === SchemaOrgData_Validator::SHARED_STATUS_ERROR) {
                $errors[] = $result['message'];
            }
        }

        $image = (string) ($sanitized['image'] ?? '');
        if($image !== '' and preg_match(self::SHARED_PATTERN_URL_SCHEME, $image) === 1) {
            $result = $validator->validateUrl($image, $lang);
            if($result['status'] === SchemaOrgData_Validator::SHARED_STATUS_ERROR) {
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
        if($image === '' or preg_match(self::SHARED_PATTERN_URL_SCHEME, $image) === 1) {
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
        if($this->saveRegistry($settings, $registry) === false) {
            // slug bleibt null wie im Kollisionsfall oben: die Person
            // existiert nicht, ein Slug wäre eine Zusage ohne Deckung.
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ], 'slug' => null];
        }

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
        if($this->saveRegistry($settings, $registry) === false) {
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ]];
        }

        return ['success' => true, 'errors' => []];
    }

    /***************************************************************
    *
    * Löscht eine Person aus der Registry. Idempotent - ein bereits
    * nicht (mehr) vorhandener Slug ist kein Fehler.
    *
    * Eine in den Organisations-Relationen verlinkte Person wird NICHT
    * gelöscht: die Meldung nennt die betroffenen Rollen, die Registry
    * bleibt unverändert (siehe findReferences()). Andernfalls bliebe die
    * verwaiste Relation gespeichert und ihre Slug-Existenzprüfung würde
    * jedes weitere Speichern des globalen Geltungsbereichs blockieren.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param Language $lang liefert die Meldungstexte einer blockierenden
    *        Fundstelle bzw. eines fehlgeschlagenen Schreibzugriffs
    * @return array{success: bool, errors: string[]}
    *
    ***************************************************************/
    public function deletePerson($settings, string $slug, Language $lang): array {
        $registry = $this->loadRegistry($settings);

        // Vorprüfung vor dem Schreiben: ein nicht (mehr) vorhandener Slug
        // ist Erfolg, nicht Schreibfehlschlag - sonst wäre die Idempotenz
        // dahin. Steht bewusst vor der Fundstellen-Prüfung: ein bereits
        // gelöschter Slug soll auch dann Erfolg melden, wenn irgendwo noch
        // eine Relation auf ihn zeigt.
        if(!array_key_exists($slug, $registry)) {
            return ['success' => true, 'errors' => []];
        }

        $references = $this->findReferences($settings, $slug, $lang);
        if($references !== []) {
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_person_delete_has_references', implode(', ', $references))
            ]];
        }

        unset($registry[$slug]);
        if($this->saveRegistry($settings, $registry) === false) {
            return ['success' => false, 'errors' => [
                $lang->getLanguageValue('error_config_write_failed')
            ]];
        }

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
        return self::PREFIX_PERSON.$slug;
    }

    /***************************************************************
    *
    * Prüft, ob ein @id-Fragment auf eine Registry-Person zeigt -
    * Gegenrichtung zu buildFragment(), Erkennung allein über
    * PREFIX_PERSON. Gekapselt, damit das Präfix-Idiom nicht in
    * SchemaOrgData_IdReferenceService und SchemaOrgData_AdminController
    * wiederholt wird.
    *
    ***************************************************************/
    public static function isPersonFragment(string $fragment): bool {
        return str_starts_with($fragment, self::PREFIX_PERSON);
    }

    /***************************************************************
    *
    * Schneidet PREFIX_PERSON von einem Personen-Fragment ab und liefert
    * den Slug - die zweite Gegenrichtung zu buildFragment(), gekapselt
    * für dieselben beiden Aufrufer wie isPersonFragment().
    *
    * Wacht bewusst nicht: Vorbedingung ist eine vorausgegangene positive
    * isPersonFragment()-Prüfung. Ein Guard oder Rückfallwert für
    * fremde Fragmente wäre gegenüber der reinen Kapselung eine
    * Verhaltensänderung.
    *
    ***************************************************************/
    public static function slugFromPersonFragment(string $fragment): string {
        return substr($fragment, strlen(self::PREFIX_PERSON));
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
        if($image === '' or preg_match(self::SHARED_PATTERN_URL_SCHEME, $image) === 1) {
            return $image;
        }

        $mediaBaseUrl = $urlHelper->resolveMediaBaseUrl();
        return $mediaBaseUrl !== '' ? $mediaBaseUrl.$image : $image;
    }

    /***************************************************************
    *
    * Ermittelt die speicherseitig blockierenden Fundstellen eines
    * Personen-Slugs vor dem Löschen (siehe deletePerson()) und
    * beschreibt sie über die Rollen-Label der jeweiligen Relation.
    *
    * Geprüft werden ausschließlich die Organisations-Relationen des
    * globalen Geltungsbereichs (Settings-Key "config_global",
    * Property "org_relations", siehe SchemaOrgData_OrgRelationsService)
    * - denn allein deren Slug-Existenzprüfung blockiert das Speichern
    * einer Scope-Konfiguration. Referenzen aus Kategorie-/Seiten-Scopes
    * (Article.author, ProfilePage.mainEntity) sind NICHT Teil der
    * Prüfung: sie verhindern kein Speichern, werden emissionsseitig
    * ohnehin unterdrückt (siehe
    * SchemaOrgData_IdReferenceService::applyDanglingReferenceGuard()),
    * und die Settings-API bietet keine Aufzählung der vorhandenen
    * Scope-Schlüssel, über die sie vollständig auffindbar wären.
    *
    * Rein lesend - kein Schreibzugriff auf die Settings.
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param Language $lang liefert die Rollen-Label der Fundstellen
    * @return string[] Rollen-Label in Fundreihenfolge, ohne Duplikate
    *         (leer = keine blockierende Fundstelle)
    *
    ***************************************************************/
    public function findReferences($settings, string $slug, Language $lang): array {
        if(!$settings->keyExists(self::GLOBAL_CONFIG_KEY)) {
            return [];
        }

        $config = $settings->get(self::GLOBAL_CONFIG_KEY);
        if(!is_array($config) or !is_array($config[self::KEY_ORG_RELATIONS] ?? null)) {
            return [];
        }

        $roles = SchemaOrgData_OrgRelationsService::roles();
        $labels = [];

        foreach($config[self::KEY_ORG_RELATIONS] as $relation) {
            if(!is_array($relation)) {
                continue;
            }

            $person = $relation['person'] ?? null;
            $role = $relation['role'] ?? null;
            if(!is_string($person) or !is_string($role) or $person !== $slug) {
                continue;
            }

            // Eine Rolle außerhalb der Whitelist hat keinen Sprachschlüssel;
            // getLanguageValue() lieferte dafür einen unbrauchbaren Platzhalter.
            if(!in_array($role, $roles, true)) {
                continue;
            }

            $label = $lang->getLanguageValue('label_role_'.$role);
            if(!in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /***************************************************************
    *
    * Persistiert die vollständige Registry über die moziloCMS-
    * Settings-API (analog SchemaOrgData_ScopeResolver::saveScopeMeta()).
    *
    * @param mixed $settings moziloCMS-Settings-API ($this->settings)
    * @param array<string, array<string, mixed>> $registry vollständige Registry
    * @return bool false, wenn die Registry nicht geschrieben wurde - der
    *         zuvor gespeicherte Stand gilt dann unverändert weiter
    *
    ***************************************************************/
    private function saveRegistry($settings, array $registry): bool {
        // Misserfolg signalisiert der Kern per Rückgabewert false (und
        // rollt seinen In-Memory-Stand zurück), nicht per Exception; der
        // catch-Zweig bleibt als Netz für unerwartete Fehler daneben stehen.
        try {
            if($settings->set(self::SETTINGS_KEY, $registry) === false) {
                error_log('schemaOrgData: Personen-Registry speichern fehlgeschlagen: Schreibzugriff abgelehnt');
                return false;
            }
        } catch (\Throwable $e) {
            error_log('schemaOrgData: Personen-Registry speichern fehlgeschlagen: '.$e->getMessage());
            return false;
        }

        return true;
    }
}
