<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_JsonLdBuilder
*
* Baut aus zusammengeführten Formulardaten den fertigen
* <script type="application/ld+json">-Block, inklusive @id-Anker-
* Vergabe und Leerfeld-Bereinigung (siehe README.md, Abschnitte
* "JSON-LD-Ausgabe" und "Organisations-Identität und @id-Anker"). Wird
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
    * Entfernt rekursiv Properties mit leerem Wert (null, '' oder [])
    * aus einem (verschachtelten) Array, damit sie nicht im JSON-LD
    * ausgegeben werden.
    *
    * Listen behalten dabei ihre Listen-Natur: unset() hinterlässt eine
    * Lücke in den Indizes, und json_encode() gibt ein lückenhaft
    * indiziertes Array als JSON-Objekt mit numerischen Schlüsseln aus
    * ({"0":…,"2":…}) statt als Array - strukturell ungültiges
    * schema.org für jede Listen-Property (openingHours, sameAs,
    * knowsAbout, die Ausgabegruppen der Organisations-Relationen).
    * Entscheidend ist die Form der EINGABE, nicht die des Ergebnisses:
    * eine assoziative Map darf nicht neu indiziert werden, sonst gingen
    * ihre Schlüssel verloren.
    *
    * @param array<string, mixed> $data Properties eines Schema-Types
    * @return array<string, mixed> Properties ohne leere Werte
    *
    ***************************************************************/
    public function removeEmptyJsonLdProperties(array $data): array {
        $wasList = array_is_list($data);

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

        return $wasList ? array_values($data) : $data;
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
    * Wie resolveNodeId(), aber für Registry-Personen (siehe README.md,
    * "Organisations-Identität und @id-Anker"): das Fragment wird ausschließlich
    * aus dem Slug gebildet (SchemaOrgData_PersonsRegistryService::buildFragment()),
    * kein Schema-Lookup - Registry-Personen haben kein eigenes
    * Schema-Type-File mehr. Nutzt denselben $assignedFragments-De-Dup-
    * Guard wie resolveNodeId(), damit beide Mechanismen konsistent
    * bleiben, falls ein Fragment künftig aus beiden Quellen vergeben
    * werden könnte.
    *
    * @param string $slug Registry-Slug der Person
    * @param string[] $assignedFragments bereits vergebene Fragmente (per Referenz)
    * @return string vollständige @id-URI oder '' (kein Anker)
    *
    ***************************************************************/
    public function resolvePersonNodeId(
        SchemaOrgData_UrlHelper $urlHelper,
        string $slug,
        array &$assignedFragments
    ): string {
        $fragment = SchemaOrgData_PersonsRegistryService::buildFragment($slug);
        if(in_array($fragment, $assignedFragments, true)) {
            return '';
        }

        $baseUrl = $urlHelper->resolveBaseUrl();
        if($baseUrl === '') {
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
    * @param SchemaOrgData_OpeningHoursHelper|null $openingHoursHelper wird nur für
    *              den ui:emitAs-Zweig benötigt; ohne ihn bleibt eine so markierte
    *              Property flach (unverändertes Verhalten für Aufrufer ohne Helper)
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
        array $suppressedIdTargets = [],
        ?SchemaOrgData_OpeningHoursHelper $openingHoursHelper = null
    ): string {
        // Leere Properties (null, '', []) entfernen, auch verschachtelt
        // (z. B. unvollständige PostalAddress/GeoCoordinates-Angaben).
        $data = $this->removeEmptyJsonLdProperties($data);

        // Adresse, Geokoordinaten und Veranstaltungsort als verschachtelte,
        // typisierte schema.org-Objekte ausgeben. Ein eventuell im
        // Erweiterungsfeld mitgeliefertes "@type" wird vorher entfernt,
        // damit der schema-vorgegebene Type nicht überschreibbar ist
        // (analoges Muster zu den reservierten Top-Level-Schlüsseln oben).
        foreach(['address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'location' => 'Place', 'jobLocation' => 'Place'] as $property => $nestedType) {
            if(isset($data[$property]) and is_array($data[$property])) {
                unset($data[$property]['@type']);
                $data[$property] = array_merge(['@type' => $nestedType], $data[$property]);
            }
        }

        // {location,jobLocation}.address ist eine Ebene tiefer als die obige
        // Map reicht - eigener PostalAddress-@type für die verschachtelte
        // Adresse (Event.location, JobPosting.jobLocation).
        foreach(['location', 'jobLocation'] as $placeProperty) {
            if(isset($data[$placeProperty]['address']) and is_array($data[$placeProperty]['address'])) {
                unset($data[$placeProperty]['address']['@type']);
                $data[$placeProperty]['address'] = array_merge(['@type' => 'PostalAddress'], $data[$placeProperty]['address']);
            }
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
                    $rawValue = $data[$propName] ?? null;
                    unset($data[$propName]);

                    $stored = null;
                    if(is_array($rawValue) and array_key_exists('_mode', $rawValue)) {
                        // Maßgeblich ist die ANWESENHEIT von _mode, nicht sein Wert:
                        // nur ein von diesem Widget gespeicherter Wert führt den
                        // Schlüssel, und nur ein solcher darf umgedeutet werden.
                        $stored = $rawValue;
                    } elseif(is_string($rawValue)) {
                        // Lesekompatibilität für Freitext-Bestandsdaten (z. B.
                        // Article.author vor der Umstellung auf dieses Widget): ein
                        // reiner String wird als Literal-Wert im ersten konfigurierten
                        // Literal-Feld behandelt, damit die Ausgabe ohne erneutes
                        // Speichern erhalten bleibt.
                        if(trim($rawValue) !== '') {
                            $literalFields = $propSchema['ui:literalFields'] ?? [];
                            $primaryField = (string) ($literalFields[0] ?? 'name');
                            $stored = ['_mode' => 'literal', $primaryField => trim($rawValue)];
                        }
                    } elseif($rawValue !== null) {
                        // Werte, die nicht aus diesem Widget stammen - ein über das
                        // Erweiterungsfeld gesetztes Rohobjekt (etwa
                        // {"@type":"Person","name":"..."}) oder ein skalarer Wert -
                        // sind bereits gültiges JSON-LD und werden unverändert
                        // durchgereicht. Das Widget übersetzt ausschließlich sein
                        // eigenes Speicherformat: ein Rohobjekt trägt in der Regel
                        // schon ein eigenes @type, das ui:literalType sonst
                        // überschreiben würde.
                        $data[$propName] = $rawValue;
                    }

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
                        } else {
                            // Unbekannter Modus: nicht emittieren. Ein Durchreichen
                            // würde den internen Schlüssel _mode in die Ausgabe tragen.
                            error_log('schemaOrgData: unbekannter _mode "'.$mode.'" bei Property "'.$propName.'" - Wert wird nicht ausgegeben');
                        }
                    }
                } elseif($openingHoursHelper !== null and is_array($propSchema['ui:emitAs'] ?? null)) {
                    // ui:emitAs lenkt eine flach eingegebene Property in einen
                    // typisierten Unterknoten um. Anlass: openingHours ist laut
                    // schema.org nur auf CivicStructure/LocalBusiness gültig,
                    // nicht auf Organization/NGO - dort gehört die Angabe als
                    // openingHoursSpecification an einen Place unterhalb von
                    // location. Das Formular bleibt davon unberührt; die
                    // Umlenkung geschieht ausschließlich hier zur Ausgabezeit
                    // und ist vollständig schema-getrieben (keine Type-Namen
                    // im PHP).
                    $emitAs      = $propSchema['ui:emitAs'];
                    $targetProp  = trim((string) ($emitAs['property'] ?? ''));
                    $wrapperType = trim((string) ($emitAs['wrapperType'] ?? ''));
                    $asProp      = trim((string) ($emitAs['as'] ?? ''));
                    $itemType    = trim((string) ($emitAs['itemType'] ?? ''));

                    // Unvollständige Deklaration -> flaches Verhalten belassen,
                    // statt einen halb aufgebauten Knoten zu erzeugen.
                    if($targetProp !== '' and $wrapperType !== '' and $asProp !== '' and $itemType !== '') {
                        $rawValue = $data[$propName] ?? null;
                        // Die umgelenkte Property darf nicht zusätzlich flach
                        // am Wurzelknoten stehen bleiben.
                        unset($data[$propName]);

                        $specs = [];
                        if(is_array($rawValue) and $rawValue !== []) {
                            $days = $propSchema['ui:days'] ?? null;
                            $specs = $openingHoursHelper->buildOpeningHoursSpecifications(
                                $rawValue,
                                is_array($days) ? $days : ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']
                            );
                            foreach($specs as $index => $spec) {
                                // Der Item-Type kommt aus dem Schema, nicht aus
                                // dem Helper - derselbe Serialisierer bleibt so
                                // für andere Zielstrukturen verwendbar.
                                $spec['@type'] = $itemType;
                                $specs[$index] = $spec;
                            }
                        }

                        if($specs !== []) {
                            if(isset($data[$targetProp]) and is_array($data[$targetProp])) {
                                // Ein bereits vorhandener Zielknoten (z. B. aus
                                // dem Erweiterungsfeld) gewinnt: sein @type und
                                // seine übrigen Angaben bleiben unangetastet,
                                // ergänzt wird höchstens die fehlende Liste.
                                if(!isset($data[$targetProp][$asProp])) {
                                    $data[$targetProp][$asProp] = $specs;
                                }
                            } else {
                                // Die Adresse wird in den Unterknoten kopiert
                                // statt per @id referenziert - der Place trägt
                                // damit ohne zusätzlichen Anker eine vollständige
                                // Ortsangabe und bleibt am Wurzelknoten erhalten.
                                $node = ['@type' => $wrapperType];
                                if(isset($data['address']) and is_array($data['address'])) {
                                    $node['address'] = $data['address'];
                                }
                                $node[$asProp] = $specs;
                                $data[$targetProp] = $node;
                            }
                        }
                    }
                }
            }
        }

        // Reservierte Top-Level-Schlüssel dürfen vom Erweiterungsfeld
        // (bereits mit den Formulardaten in $data zusammengeführt) nicht
        // überschrieben werden - $head gewinnt für @context/@type/@id immer.
        foreach(['@context', '@type', '@id'] as $reservedKey) {
            unset($data[$reservedKey]);
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
