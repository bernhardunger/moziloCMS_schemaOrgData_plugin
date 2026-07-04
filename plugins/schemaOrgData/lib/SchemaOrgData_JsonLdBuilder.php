<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_JsonLdBuilder
*
* Baut aus zusammengeführten Formulardaten den fertigen
* <script type="application/ld+json">-Block, inklusive @id-Anker-
* Vergabe und Leerfeld-Bereinigung (siehe README.md, Abschnitte
* "JSON-LD-Ausgabe" und "@id-Anker und Knotenreferenzen"). Wird
* von der Fassade schemaOrgData über einen Lazy-Accessor
* verdrahtet.
*
* Zustandslos - kein Caching. SchemaRepository und UrlHelper
* werden je Aufruf als Parameter übergeben (kein eingefrorener
* State), analog zur Linie aus Schritt 3/4.
*
***************************************************************/
class SchemaOrgData_JsonLdBuilder {

    /***************************************************************
    *
    * Wandelt HTML-Entities in allen String-Werten eines (verschachtelten)
    * Arrays zurück in Klartext (Gegenstück zu htmlspecialchars(), siehe
    * sanitizePostData()), bevor das Array als JSON-LD ausgegeben wird.
    *
    * @param array<string, mixed> $data Properties eines Schema-Types
    * @return array<string, mixed> Properties mit dekodierten String-Werten
    *
    ***************************************************************/
    public function decodeJsonLdValues(array $data): array {
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $data[$key] = $this->decodeJsonLdValues($value);
            } elseif(is_string($value)) {
                $data[$key] = htmlspecialchars_decode($value, ENT_QUOTES);
            }
        }
        return $data;
    }

    /***************************************************************
    *
    * Entfernt rekursiv Properties mit leerem Wert (null, '' oder [])
    * aus einem (verschachtelten) Array, damit sie nicht im JSON-LD
    * ausgegeben werden.
    *
    * @param array<string, mixed> $data Properties eines Schema-Types
    * @return array<string, mixed> Properties ohne leere Werte
    *
    ***************************************************************/
    public function removeEmptyJsonLdProperties(array $data): array {
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $value = $this->removeEmptyJsonLdProperties($value);
            }
            if($value === null or $value === '' or $value === []) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /***************************************************************
    *
    * Bestimmt den @id-Anker für einen auszugebenden Knoten anhand der
    * Schema-Property "ui:idFragment" (siehe README.md, Abschnitt
    * "@id-Anker"). Der Mechanismus ist vollständig schema-getrieben -
    * im PHP stehen keine Type-Namen.
    *
    * De-Dup-Guard: Ein Fragment darf pro Seite nur EINEM Knoten
    * zugewiesen werden. Tragen mehrere Knoten dasselbe Fragment
    * (z. B. "organization"), erhält nur der erste in Ausgabereihenfolge
    * den Anker; die übrigen bleiben ohne @id. $assignedFragments wird
    * dafür je vergebenem Fragment fortgeschrieben.
    *
    * Lässt sich die Basis-URL nicht auflösen, wird KEIN (leeres) @id
    * gebildet - das Fragment bleibt dann unbelegt.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $type Schema.org-Type des Knotens
    * @param string[] $assignedFragments bereits vergebene Fragmente (per Referenz)
    * @return string vollständige @id-URI oder '' (kein Anker)
    *
    ***************************************************************/
    public function resolveNodeId(
        SchemaOrgData_SchemaRepository $schemaRepo,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginSelfDir,
        string $type,
        array &$assignedFragments
    ): string {
        $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
        $fragment = is_array($schema) ? trim((string) ($schema['ui:idFragment'] ?? '')) : '';
        if($fragment === '') {
            // Schema ohne ui:idFragment -> kein @id (unverändertes Verhalten).
            return '';
        }

        if(in_array($fragment, $assignedFragments, true)) {
            // Fragment bereits an einen anderen Knoten dieser Seite vergeben.
            return '';
        }

        $baseUrl = $urlHelper->resolveBaseUrl();
        if($baseUrl === '') {
            // Basis-URL nicht auflösbar -> kein leeres @id schlucken.
            return '';
        }

        $assignedFragments[] = $fragment;
        return $baseUrl.'#'.$fragment;
    }

    /***************************************************************
    *
    * Erzeugt aus den zusammengeführten Properties einen
    * <script type="application/ld+json">-Block.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $type Schema.org-Type, z. B. "LocalBusiness"
    * @param array<string, mixed> $data Properties (Formular + Erweiterungsfeld zusammengeführt)
    * @param string $nodeId optionaler @id-Anker (siehe README.md, "@id-Anker");
    *               wird - sofern nicht-leer - direkt hinter @type eingefügt
    * @return string fertiger <script>-Block inkl. Zeilenumbruch
    *
    ***************************************************************/
    public function buildJsonLdScript(
        SchemaOrgData_SchemaRepository $schemaRepo,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginSelfDir,
        string $type,
        array $data,
        string $nodeId = '',
        array $suppressedIdTargets = []
    ): string {
        // Werte wurden beim Speichern mit htmlspecialchars() gesichert -
        // vor dem JSON-Encode wieder in Klartext umwandeln.
        $data = $this->decodeJsonLdValues($data);

        // Leere Properties (null, '', []) entfernen, auch verschachtelt
        // (z. B. unvollständige PostalAddress/GeoCoordinates-Angaben).
        $data = $this->removeEmptyJsonLdProperties($data);

        // Adresse, Geokoordinaten, Mitarbeiter und Veranstaltungsort als
        // verschachtelte, typisierte schema.org-Objekte ausgeben.
        foreach(['address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'employee' => 'Person', 'location' => 'Place'] as $property => $nestedType) {
            if(isset($data[$property]) and is_array($data[$property])) {
                $data[$property] = array_merge(['@type' => $nestedType], $data[$property]);
            }
        }

        // location.address ist eine Ebene tiefer als die obige Map reicht -
        // eigener PostalAddress-@type für die verschachtelte Adresse (Event.location).
        if(isset($data['location']['address']) and is_array($data['location']['address'])) {
            $data['location']['address'] = array_merge(['@type' => 'PostalAddress'], $data['location']['address']);
        }

        // id_reference-Properties aus dem Schema einsetzen (Build-Zeit-Emitter).
        // Diese Properties haben keinen gespeicherten Wert - ihr Wert wird hier
        // zur Ausgabezeit als {"@id": "<Basis-URL>#<Fragment>"} aufgelöst.
        // Einfügung NACH removeEmptyJsonLdProperties(), damit ein gesetzter
        // @id-Verweis nie still getilgt wird (analoges Muster zum @id-Anker,
        // siehe README.md, "@id-Anker"). Bei keep-Modus auf der Zielebene wird
        // das Target in $suppressedIdTargets übergeben - dann unterbleibt die
        // Emission, um kein Dangling-@id gegen den Nutzerwunsch zu erzeugen.
        $schema = $schemaRepo->loadSchema($pluginSelfDir, $type);
        if(is_array($schema)) {
            $baseUrl = $urlHelper->resolveBaseUrl();
            foreach($schema['properties'] ?? [] as $propName => $propSchema) {
                $propSchema = $schemaRepo->resolveSchemaRef($propSchema, $schema);
                $widget = $propSchema['ui:widget'] ?? '';
                if($widget === 'id_reference') {
                    $target = trim((string) ($propSchema['ui:idTarget'] ?? ''));
                    if($target !== '' and $baseUrl !== '' and !in_array($target, $suppressedIdTargets, true)) {
                        $data[$propName] = ['@id' => $baseUrl.'#'.$target];
                    }
                } elseif($widget === 'id_reference_or_literal') {
                    // Gespeicherten Wert (Array mit _mode + _fragment oder Literal-Felder)
                    // in das fertige JSON-LD-Objekt umwandeln.
                    $stored = is_array($data[$propName] ?? null) ? $data[$propName] : null;
                    unset($data[$propName]);
                    if($stored !== null) {
                        $mode = (string) ($stored['_mode'] ?? '');
                        if($mode === 'reference') {
                            $fragment = trim((string) ($stored['_fragment'] ?? ''));
                            if($fragment !== '' and $baseUrl !== '' and !in_array($fragment, $suppressedIdTargets, true)) {
                                $data[$propName] = ['@id' => $baseUrl.'#'.$fragment];
                            }
                        } elseif($mode === 'literal') {
                            $literal = $stored;
                            unset($literal['_mode']);
                            // Leere Felder entfernen, bevor @type ergänzt wird,
                            // damit ein leeres Objekt nicht allein durch @type
                            // als nicht-leer gilt.
                            $literal = $this->removeEmptyJsonLdProperties($literal);
                            if($literal !== []) {
                                $literalType = trim((string) ($propSchema['ui:literalType'] ?? ''));
                                if($literalType !== '') {
                                    $literal = array_merge(['@type' => $literalType], $literal);
                                }
                                $data[$propName] = $literal;
                            }
                        }
                    }
                }
            }
        }

        // @id-Anker erst NACH dem Leerfilter setzen, damit ein gesetzter
        // Anker nie still getilgt wird (siehe README.md, "@id-Anker").
        $head = ['@context' => 'https://schema.org', '@type' => $type];
        if($nodeId !== '') {
            $head['@id'] = $nodeId;
        }

        $jsonLd = array_merge($head, $data);

        // JSON_HEX_TAG kodiert Winkelklammern in Feldwerten als Unicode-Escapes
        // und verhindert so den Ausbruch aus dem <script>-Block (Stored XSS).
        $json = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG);
        if($json === false) {
            return '';
        }

        return '<script type="application/ld+json">'."\n".$json."\n".'</script>'."\n";
    }
}
