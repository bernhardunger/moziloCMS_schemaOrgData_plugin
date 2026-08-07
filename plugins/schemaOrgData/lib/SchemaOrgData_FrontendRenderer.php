<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_FrontendRenderer
*
* Orchestriert die Frontend-Ausgabepipeline von getContent():
* Scope-Konfiguration laden, excluded_cats- und jsonld_mode='keep'-
* Filter anwenden, feldweise Vererbung und Dangling-Reference-Guard
* durchlaufen, JSON-LD-Blöcke je Type ausgeben, tatsächlich
* referenzierte Registry-Personen als eigenständige Knoten emittieren,
* optional das Debug-Widget anhängen. Die Kollisionserkennung des
* Layout-Templates wird dabei live ausgewertet und nicht gespeichert.
*
* Zustandslos: keine Konstruktor-Injection, alle Kollaboratoren
* werden je Aufruf als Parameter durchgereicht (siehe README.md,
* Abschnitt "Entwicklerdokumentation").
*
***************************************************************/
class SchemaOrgData_FrontendRenderer {

    /***************************************************************
    *
    * Rendert die JSON-LD-<script>-Blöcke für die aktuelle Frontend-
    * Seite (1:1 übernommener Rumpf des Frontend-Zweigs von
    * getContent(), siehe README.md, Abschnitt "JSON-LD-Ausgabe").
    *
    * @param mixed $value Parameterteil des Platzhalters, für den
    *              parameterlosen Aufruf der Leerstring - wird nicht
    *              ausgewertet, bleibt aber in der Signatur, weil der
    *              moziloCMS-Kern getContent() so aufruft
    * @param SchemaOrgData_FrontendRequestContext $context bündelt Settings
    *              und Kollaboratoren (siehe SchemaOrgData_FrontendRequestContext)
    * @return string fertige <script>-Blöcke (ggf. inkl. Debug-Widget)
    *
    ***************************************************************/
    public function renderFrontend(mixed $value, SchemaOrgData_FrontendRequestContext $context): string {
        global $TEMPLATE_FILE;

        // Galerie-Vollansichten (GET-Parameter "galtemplate", schaltet den
        // Core auf gallerytemplate.html um) haben keine eigene Seiten-
        // Identität: CAT_REQUEST/PAGE_REQUEST fallen dabei requestseitig auf
        // die Default-Kategorie samt Startseite zurück, unabhängig davon,
        // von welcher Seite aus die Galerie geöffnet wurde. Ohne diesen
        // Guard würde hier fälschlich das JSON-LD der Default-Kategorie/
        // Startseite ausgegeben (siehe README.md, Abschnitt "JSON-LD-Ausgabe").
        if(getRequestValue('galtemplate', 'get')) {
            return '';
        }

        $output = '';

        // Lese-/Schreibpfad-Symmetrie: CAT_REQUEST/PAGE_REQUEST werden hier
        // einmalig über sanitizeScopeIdentifier() bereinigt (identisch zum
        // Schreibpfad in resolveScopeIdentifiers()) und als $cat/$page an
        // alle nachfolgenden Aufrufstellen durchgereicht, damit Lese- und
        // Schreib-Settings-Key für dieselbe Kategorie/Seite übereinstimmen
        // (siehe README.md, Abschnitt "Geltungsbereiche und Vererbung").
        $cat  = (defined('CAT_REQUEST') and CAT_REQUEST) ? $context->scopeResolver->sanitizeScopeIdentifier((string) CAT_REQUEST) : null;
        $page = (defined('PAGE_REQUEST') and PAGE_REQUEST) ? $context->scopeResolver->sanitizeScopeIdentifier((string) PAGE_REQUEST) : null;

        // Konfiguration je Geltungsebene laden (sofern vorhanden)
        $scopeConfigs = ['global' => $context->scopeResolver->loadScopeConfig($context->settings, 'global')];

        if($cat !== null) {
            $scopeConfigs['category'] = $context->scopeResolver->loadScopeConfig($context->settings, 'category', $cat);
        }
        if($cat !== null and $page !== null) {
            $scopeConfigs['page'] = $context->scopeResolver->loadScopeConfig($context->settings, 'page', $cat, $page);
        }

        // Ausschlussliste prüfen (nur global): die globale Ausgabe wird
        // unterdrückt, wenn die aktive Kategorie in excluded_cats steht
        // (siehe README.md, Abschnitt "Geltungsbereiche und Vererbung").
        $excludedCats = !empty($scopeConfigs['global']['excluded_cats'])
            ? explode(',', (string) $scopeConfigs['global']['excluded_cats'])
            : [];

        if($cat !== null and in_array($cat, $excludedCats, true)) {
            unset($scopeConfigs['global']);
        }

        // Debug-Modus: Flag aus config_global lesen, bevor die Verwaltungsdaten
        // entfernt werden (debug_output ist kein Schema-Type, sondern ein
        // Meta-Schlüssel analog zu excluded_cats).
        $debugOutput = !empty($scopeConfigs['global']['debug_output'] ?? false);

        // Organisations-Relationen (org_relations, siehe SchemaOrgData_OrgRelationsService):
        // ebenfalls ein Meta-Schlüssel in config_global, unabhängig vom aktiven
        // globalen Schema-Type (übersteht dadurch einen Type-Wechsel innerhalb
        // der LocalBusiness-Familie bzw. zwischen Organization/NGO).
        $orgRelations = is_array($scopeConfigs['global']['org_relations'] ?? null) ? $scopeConfigs['global']['org_relations'] : [];

        // Verwaltungsdaten entfernen - übrig bleiben je Ebene nur noch die
        // Schema-Type-Konfigurationen. Welche Schlüssel dazu zählen, führt
        // SchemaOrgData_ScopeResolver::MANAGEMENT_KEYS an einer Stelle.
        foreach($scopeConfigs as $scope => $config) {
            $scopeConfigs[$scope] = $context->scopeResolver->stripManagementKeys($config);
        }

        // Kollisionserkennung des Layout-Templates, ausgewertet bevor über die
        // Emission entschieden wird: Ein dort gefundener Block ist layoutweit
        // gültig und allein aus der ausgelieferten Layout-Datei ermittelbar,
        // ohne Request-Kontext.
        $templateBlocks = array_values(array_map('trim',
            $context->collisionDetector->extractExistingJsonLdBlocksFromTemplate((string) ($TEMPLATE_FILE ?? ''))));
        $hasJsonLdInTemplate = !empty($templateBlocks);

        // Seiteninhalts-Kollisionserkennung, symmetrisch zum Layout-Scan:
        // Der Seiten-Scope entscheidet gegen den Live-Zustand seines
        // Inhalts, damit eine entfernte Fremdeinbindung sofort wirkt statt
        // erst beim nächsten Öffnen der Plugin-Verwaltung.
        // CAT_REQUEST/PAGE_REQUEST gehen roh in die Erkennung - der Kern
        // schlägt sie im CatPageArray nach. Die sanitierten $cat/$page oben
        // dienen ausschließlich der Bildung des Settings-Schlüssels.
        $hasJsonLdInPageContent = $context->collisionDetector->extractExistingJsonLdBlocksFromPage(
            $GLOBALS['CatPage'] ?? null,
            (defined('CAT_REQUEST') and CAT_REQUEST) ? (string) CAT_REQUEST : '',
            (defined('PAGE_REQUEST') and PAGE_REQUEST) ? (string) PAGE_REQUEST : ''
        ) !== [];

        // jsonld_mode prüfen: wurde bereits vorhandenes JSON-LD erkannt
        // und für diese Ebene "Vorhandenes beibehalten" gewählt (Standard,
        // solange der Admin keine Wahl getroffen hat), wird die eigene
        // Ausgabe komplett unterdrückt (siehe loadScopeMeta/
        // renderExistingJsonLdNotice sowie README.md, Abschnitt
        // "Vorhandenes JSON-LD und Import").
        // $globalSuppressedByKeep wird für den Dangling-Reference-Guard
        // unten benötigt: keep hat Vorrang vor einem erzwungenen Stub.
        $globalSuppressedByKeep = false;
        foreach($scopeConfigs as $scope => $config) {
            $scopeArgs = match($scope) {
                'category' => [$cat],
                'page'     => [$cat, $page],
                default    => [],
            };

            $meta = $context->scopeResolver->loadScopeMeta($context->settings, $scope, ...$scopeArgs);

            // Die Nutzerentscheidung jsonld_mode kommt auf allen drei Ebenen
            // unabhängig von der Erkennung aus dem gespeicherten
            // Meta (siehe loadScopeMeta()). Global und Seite entscheiden
            // gegen den Live-Zustand ihrer jeweiligen Quelle - Layout-Datei
            // beziehungsweise Seiteninhalt. Die Kategorie hat keine eigene
            // Quelle und bleibt beim gespeicherten Flag; dort entsteht auf
            // regulärem Weg kein gesetzter Bestand, der Zweig bleibt als
            // Absicherung für von Hand bearbeitete Bestände und ist über die
            // Modus-Auswahl abschaltbar.
            $hasExistingJsonLd = match($scope) {
                'global' => $hasJsonLdInTemplate,
                'page'   => $hasJsonLdInPageContent,
                default  => $meta['existing_jsonld'],
            };

            if($hasExistingJsonLd and ($meta['jsonld_mode'] ?? 'override') === 'keep') {
                if($scope === 'global') {
                    $globalSuppressedByKeep = true;
                }
                unset($scopeConfigs[$scope]);
            }
        }

        // Feldweise Vererbung: ist derselbe Schema-Type auf mehreren
        // Ebenen konfiguriert, werden die Felder zusammengeführt
        // (Global -> Kategorie -> Seite, siehe resolveTypeInheritance()).
        $scopeConfigs = $context->scopeResolver->resolveTypeInheritance($scopeConfigs);

        // Organisations-Relationen dürfen nur dann Personen-Referenzen
        // beitragen, wenn auf dieser Seite tatsächlich ein Knoten mit
        // ui:idFragment == "organization" ausgegeben wird (z. B. nicht der
        // Fall bei keep-Modus/excluded_cats-Unterdrückung des Global-Scopes,
        // oder wenn der aktive globale Type kein Organisations-Type ist) -
        // sonst blieben referenzierte Personen als Knoten ohne jede
        // sichtbare Referenz im Graph zurück. org_relations wird
        // ausschließlich unter config_global gespeichert (siehe
        // SchemaOrgData_OrgRelationsService) - der Vor-Check beschränkt sich
        // deshalb bewusst auf den globalen Scope, statt einen beliebigen
        // Scope mit passendem ui:idFragment als ausreichenden Trägerknoten
        // zu werten (sonst würde ein Kategorie-/Seiten-Type mit demselben
        // Fragment fälschlich als Träger akzeptiert).
        //
        // Der Vor-Check läuft bewusst vor applyDanglingReferenceGuard().
        // Dessen Vollunterdrückung könnte einen Organisations-Knoten
        // nachträglich entfernen, ohne dass $orgNodePresent das noch
        // erfährt - org_relations bliebe dann beitragsberechtigt, ohne
        // Trägerknoten. Erreichbar wäre das erst mit einem
        // Organisations-Schema, das eine Personen-Referenz als
        // ui:required deklariert; keines der Schemata mit
        // ui:idFragment "organization" tut das. Die Gegenrichtung ist
        // unkritisch: eine Stub-Einfügung kann $orgNodePresent nur
        // nachträglich wahr machen.
        $orgNodePresent = false;
        foreach(array_keys($scopeConfigs['global'] ?? []) as $type) {
            $typeSchema = $context->schemaRepository->loadSchema($context->pluginSelfDir, $type);
            if(is_array($typeSchema) and ($typeSchema['ui:idFragment'] ?? '') === 'organization') {
                $orgNodePresent = true;
                break;
            }
        }
        if(!$orgNodePresent) {
            $orgRelations = [];
        }

        // Dangling-Reference-Guard: prüft, ob eine id_reference auf einen
        // @id-Knoten verweist, der auf dieser Seite nicht ausgegeben wird,
        // und erzwingt ggf. einen Minimal-Stub (nur bei excluded_cats-
        // Unterdrückung). Bei keep-Modus wird die id_reference stattdessen
        // unterdrückt (siehe applyDanglingReferenceGuard(), README.md,
        // "@id-Anker"). Organisations-Relationen (org_relations) fließen als
        // zusätzliche Personen-Referenzquelle in denselben Mechanismus ein.
        [$scopeConfigs, $suppressedIdTargets, $activePersonSlugs] = $context->idReferenceService->applyDanglingReferenceGuard(
            $context->scopeResolver, $context->schemaRepository, $context->settings, $context->pluginSelfDir,
            $scopeConfigs, $globalSuppressedByKeep, $context->personsRegistryService, $orgRelations
        );

        // Gruppierte @id-Referenzen (Rolle => Liste) für den Organisations-
        // Knoten - Status-/Dangling-Filter siehe SchemaOrgData_OrgRelationsService::buildOutputGroups().
        $orgRelationsGrouped = $context->orgRelationsService->buildOutputGroups(
            $orgRelations, $suppressedIdTargets, $context->settings, $context->personsRegistryService, $context->urlHelper
        );

        // JSON-LD-Blöcke der verbleibenden Types ausgeben; bei aktivem
        // Debug-Modus Metadaten je Block für buildDebugWidget() sammeln.
        // Pro Seite vergebene @id-Fragmente, für den De-Dup-Guard in
        // resolveNodeId() (siehe README.md, "@id-Anker").
        $assignedFragments = [];

        $debugBlocks = [];
        foreach($scopeConfigs as $scope => $config) {
            foreach($config as $type => $data) {
                // org_relations gehört ausschließlich zur globalen
                // Organisations-Identität (config_global, siehe
                // SchemaOrgData_OrgRelationsService) - der Merge bleibt daher
                // auf den globalen Scope beschränkt, auch wenn ein
                // Kategorie-/Seiten-Type zufällig dasselbe ui:idFragment
                // deklariert (sonst bekäme dieser Knoten fälschlich dieselben
                // Referenzen wie der globale Organisations-Knoten).
                if($orgRelationsGrouped !== [] and $scope === 'global') {
                    $typeSchema = $context->schemaRepository->loadSchema($context->pluginSelfDir, $type);
                    if(is_array($typeSchema) and ($typeSchema['ui:idFragment'] ?? '') === 'organization') {
                        $data = array_merge($data, $orgRelationsGrouped);
                    }
                }
                $nodeId = $context->jsonLdBuilder->resolveNodeId($context->schemaRepository, $context->urlHelper, $context->pluginSelfDir, $type, $assignedFragments);
                $output .= $context->jsonLdBuilder->buildJsonLdScript($context->schemaRepository, $context->urlHelper, $context->pluginSelfDir, $type, $data, $nodeId, $suppressedIdTargets, $context->openingHoursHelper);
                if($debugOutput) {
                    $scopeKey = match($scope) {
                        'category' => 'cat_'.($cat ?? ''),
                        'page'     => 'page_'.($cat ?? '').'_'.($page ?? ''),
                        default    => 'global',
                    };
                    $debugBlocks[] = ['scope' => $scopeKey, 'type' => $type, 'data' => $data, 'id' => $nodeId];
                }
            }
        }

        // Registry-Personen-Knoten (siehe README.md, "Organisations-
        // Identität und @id-Anker"): $activePersonSlugs enthält nur Slugs, die
        // tatsächlich referenziert UND in der Registry vorhanden sind
        // (Dangling-Vermeidung bereits durch applyDanglingReferenceGuard()
        // erledigt) - Status der Person ist hier bewusst irrelevant.
        foreach($activePersonSlugs as $slug) {
            $person = $context->personsRegistryService->getPerson($context->settings, $slug);
            if($person === null) {
                continue;
            }

            $personData = [
                'name'            => $person['name'] ?? '',
                'honorificPrefix' => $person['honorificPrefix'] ?? '',
                'jobTitle'        => $person['jobTitle'] ?? '',
                'description'     => $person['description'] ?? '',
                'url'             => $person['url'] ?? '',
                'sameAs'          => $person['sameAs'] ?? [],
                'knowsAbout'      => $person['knowsAbout'] ?? [],
                'image'           => $context->personsRegistryService->resolveAbsoluteImageUrl(
                    (string) ($person['image'] ?? ''), $context->urlHelper
                ),
            ];

            $nodeId = $context->jsonLdBuilder->resolvePersonNodeId($context->urlHelper, $slug, $assignedFragments);
            $output .= $context->jsonLdBuilder->buildJsonLdScript(
                $context->schemaRepository, $context->urlHelper, $context->pluginSelfDir, 'Person', $personData, $nodeId, $suppressedIdTargets,
                $context->openingHoursHelper
            );
            if($debugOutput) {
                $debugBlocks[] = ['scope' => 'person_'.$slug, 'type' => 'Person', 'data' => $personData, 'id' => $nodeId];
            }
        }

        if($debugOutput and $debugBlocks !== []) {
            $output .= $this->buildDebugWidget(
                $debugBlocks, $context->jsonLdBuilder, $context->schemaRepository,
                $context->urlHelper, $context->pluginSelfDir, $suppressedIdTargets,
                $context->openingHoursHelper, $context->pluginSelfUrl
            );
        }

        return $output;
    }

    /***************************************************************
    *
    * Baut das schwebende Debug-Widget (Trigger-Button unten rechts
    * und einen <dialog> mit je einem Abschnitt pro ausgegebenem
    * JSON-LD-Block (Scope-Herkunft, formatiertes JSON, Kopier-Button)
    * sowie einem Link auf validator.schema.org.
    *
    * Der Rückgabewert enthält ausschließlich zwei <script>-Blöcke,
    * keinerlei <button>/<dialog>-Markup direkt an der Einfügestelle:
    * Der Platzhalter {schemaOrgData} steht laut Vorgabe im <head>, wo
    * <button>/<dialog> keine gültigen Metadaten-Elemente sind (siehe
    * README.md, Abschnitt "JSON-LD-Ausgabe").
    *
    * Der erste Block trägt die Vorschau-Daten (Scope, Type,
    * formatiertes JSON je Block) als <script type="application/json">
    * mit der ID "schemaOrgData-debug-data"; der Browser führt ihn nicht
    * aus. Der zweite bindet js/debug-widget.js per src ein, mit
    * filemtime()-Cache-Buster wie die Admin-Assets. Trigger-Button,
    * Dialog und alle Kindelemente entstehen dort erst zur Laufzeit per
    * document.createElement()/textContent (kein innerHTML), sobald
    * DOMContentLoaded gefeuert hat. Alle IDs bleiben mit
    * "schemaOrgData-debug-" präfixiert, alle Styles inline — keine
    * globalen CSS-Klassen, da dies auf der echten Frontend-Seite
    * landet.
    *
    * Die Vorschau je Block wird über buildJsonLdScript() erzeugt (statt
    * über eine eigene, partielle Nachbildung dessen Transformationen) -
    * das garantiert byte-identisches JSON zum echten <script>-Block,
    * inklusive Place-/PostalAddress-Verschachtelung (address/geo/
    * location/jobLocation) und id_reference(_or_literal)-Auflösung
    * (z. B. Event.organizer), statt der rohen {"_mode": ...}-Repräsentation.
    *
    * Die sichtbaren Texte des Widgets stehen bewusst literal in
    * js/debug-widget.js und laufen nicht über die Sprachdateien: Das Widget ist ein
    * Entwickler-Werkzeug hinter dem debug_output-Schalter, und die
    * Pluralbildung des Block-Zählers ist sprachstrukturell an das Deutsche
    * gebunden - bloßes Auslagern der Zeichenketten löste sie nicht auf.
    *
    * @param array<int, array{scope: string, type: string, data: array<string, mixed>, id: string}> $blocks
    *              je Block Scope ('global'|'cat_x'|'page_x_y'), Type, Properties und @id-Anker
    * @param string[] $suppressedIdTargets siehe buildJsonLdScript()
    * @param SchemaOrgData_OpeningHoursHelper|null $openingHoursHelper wird an
    *              buildJsonLdScript() durchgereicht, damit die Vorschau auch die
    *              ui:emitAs-Umlenkung byte-identisch zur echten Ausgabe zeigt
    * @param string $pluginSelfUrl absolute Basis-URL des Plugin-Ordners
    *              (PLUGIN_SELF_URL) als Präfix des src-Attributs; leer nur in
    *              Testaufrufen, die die Einbindungsform nicht prüfen
    * @return string der JSON-Datenblock und die src-Einbindung des Widget-Skripts
    *
    ***************************************************************/
    public function buildDebugWidget(
        array $blocks,
        SchemaOrgData_JsonLdBuilder $jsonLdBuilder,
        SchemaOrgData_SchemaRepository $schemaRepository,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginSelfDir,
        array $suppressedIdTargets = [],
        ?SchemaOrgData_OpeningHoursHelper $openingHoursHelper = null,
        string $pluginSelfUrl = ''
    ): string {
        $count = count($blocks);
        $plural = $count !== 1 ? 'Blöcke' : 'Block';

        $blockData = [];
        foreach($blocks as $block) {
            $type   = $block['type'];
            $scope  = $block['scope'];
            $data   = $block['data'];
            $nodeId = (string) ($block['id'] ?? '');

            // buildJsonLdScript() liefert denselben <script>-Block wie die
            // echte Ausgabe - die Vorschau extrahiert daraus nur den
            // JSON-Teil, statt dessen Transformationen (Leerfilter, Place-/
            // PostalAddress-Verschachtelung, id_reference(_or_literal)-
            // Auflösung) hier ein zweites Mal nachzubilden.
            $script = $jsonLdBuilder->buildJsonLdScript(
                $schemaRepository, $urlHelper, $pluginSelfDir, $type, $data, $nodeId, $suppressedIdTargets,
                $openingHoursHelper
            );
            preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $scriptMatches);

            $blockData[] = [
                'scope' => $scope,
                'type'  => $type,
                'json'  => $scriptMatches[1] ?? '',
            ];
        }

        $payload = ['label' => $count.' JSON-LD-'.$plural, 'blocks' => $blockData];

        // JSON_HEX_TAG kodiert Winkelklammern als Unicode-Escapes und
        // verhindert ein Breakout aus dem JSON-Datenblock: Der Browser
        // führt dessen Inhalt nicht aus, liest ihn aber als Zeichenstrom
        // bis zum ersten </script>. Ohne die Flagge könnte ein
        // Winkelklammer-Paar in einem Feldwert den Block vorzeitig
        // beenden und den Rest als Markup in die Seite hängen (analoges
        // Härtungsmuster zu buildJsonLdScript(), siehe README.md,
        // Abschnitt "Sicherheit").
        // Der "json"-Wert je Block ist durch buildJsonLdScript() intern
        // bereits so kodiert - diese zweite Kodierschicht escaped dabei
        // lediglich den darin enthaltenen Backslash der Escape-Sequenz
        // erneut (\uXXXX -> \\uXXXX im Datenblock); JSON.parse() löst das
        // beim Einlesen wieder zur ursprünglichen Zeichenkette auf, das
        // Laufzeitergebnis bleibt byte-identisch.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG);

        $cacheBuster = $urlHelper->resolveAssetCacheBuster($pluginSelfDir, 'js/debug-widget.js');

        $html  = '<script type="application/json" id="schemaOrgData-debug-data">'."\n";
        $html .= $json."\n";
        $html .= '</script>'."\n";
        $html .= '<script src="'.$pluginSelfUrl.'js/debug-widget.js?v='.$cacheBuster.'"></script>'."\n";

        return $html;
    }
}
