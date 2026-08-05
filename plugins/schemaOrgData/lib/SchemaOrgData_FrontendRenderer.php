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

        // Verwaltungsdaten (_meta, excluded_cats, debug_output, org_relations)
        // entfernen - übrig bleiben je Ebene nur noch die Schema-Type-Konfigurationen.
        foreach($scopeConfigs as $scope => $config) {
            unset($scopeConfigs[$scope]['_meta'], $scopeConfigs[$scope]['excluded_cats'], $scopeConfigs[$scope]['debug_output'], $scopeConfigs[$scope]['org_relations']);
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
                $context->openingHoursHelper
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
    * Der Rückgabewert enthält ausschließlich einen einzigen
    * <script>-Block, keinerlei <button>/<dialog>-Markup direkt an der
    * Einfügestelle: Der Platzhalter {schemaOrgData} steht laut
    * Vorgabe im <head>, wo <button>/<dialog> keine gültigen
    * Metadaten-Elemente sind (siehe README.md, Abschnitt
    * "JSON-LD-Ausgabe"). Das Skript trägt die Vorschau-Daten (Scope,
    * Type, formatiertes JSON je Block) als JSON-Literal in sich und
    * baut Trigger-Button, Dialog und alle Kindelemente erst zur
    * Laufzeit per document.createElement()/textContent (kein
    * innerHTML), sobald DOMContentLoaded gefeuert hat, und hängt sie
    * an document.body an. Alle IDs bleiben mit
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
    * @param array<int, array{scope: string, type: string, data: array<string, mixed>, id: string}> $blocks
    *              je Block Scope ('global'|'cat_x'|'page_x_y'), Type, Properties und @id-Anker
    * @param string[] $suppressedIdTargets siehe buildJsonLdScript()
    * @param SchemaOrgData_OpeningHoursHelper|null $openingHoursHelper wird an
    *              buildJsonLdScript() durchgereicht, damit die Vorschau auch die
    *              ui:emitAs-Umlenkung byte-identisch zur echten Ausgabe zeigt
    * @return string ein einzelner <script>-Block, der das Widget zur Laufzeit aufbaut
    *
    ***************************************************************/
    public function buildDebugWidget(
        array $blocks,
        SchemaOrgData_JsonLdBuilder $jsonLdBuilder,
        SchemaOrgData_SchemaRepository $schemaRepository,
        SchemaOrgData_UrlHelper $urlHelper,
        string $pluginSelfDir,
        array $suppressedIdTargets = [],
        ?SchemaOrgData_OpeningHoursHelper $openingHoursHelper = null
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
        // verhindert ein Script-Breakout (analoges Härtungsmuster zu
        // buildJsonLdScript(), siehe README.md, Abschnitt "Sicherheit").
        // Der "json"-Wert je Block ist durch buildJsonLdScript() intern
        // bereits so kodiert - diese zweite Kodierschicht escaped dabei
        // lediglich den darin enthaltenen Backslash der Escape-Sequenz
        // erneut (\uXXXX -> \\uXXXX im Skript-Text); JSON.parse() löst das
        // beim Einlesen wieder zur ursprünglichen Zeichenkette auf, das
        // Laufzeitergebnis bleibt byte-identisch.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG);

        $html  = '<script>(function(){'."\n";
        $html .= 'var schemaOrgDataDebugData = '.$json.';'."\n";
        $html .= 'function schemaOrgDataInitDebugWidget(){'."\n";
        $html .= '  var trigger = document.createElement("button");'."\n";
        $html .= '  trigger.id = "schemaOrgData-debug-trigger";'."\n";
        $html .= '  trigger.type = "button";'."\n";
        $html .= '  trigger.style.cssText = "position:fixed;bottom:1em;right:1em;z-index:9999;background:#1a73e8;'
            .'color:#fff;border:none;border-radius:4px;padding:.5em 1em;font-size:14px;cursor:pointer;'
            .'box-shadow:0 2px 8px rgba(0,0,0,.3);";'."\n";
        $html .= '  trigger.textContent = "🔧 Debug: " + schemaOrgDataDebugData.label;'."\n";

        $html .= '  var dialog = document.createElement("dialog");'."\n";
        $html .= '  dialog.id = "schemaOrgData-debug-dialog";'."\n";
        $html .= '  dialog.style.cssText = "max-width:800px;width:90vw;max-height:85vh;overflow:auto;'
            .'border-radius:6px;border:1px solid #ccc;box-shadow:0 4px 24px rgba(0,0,0,.2);padding:1.5em;";'."\n";

        $html .= '  var header = document.createElement("div");'."\n";
        $html .= '  header.style.cssText = "display:flex;justify-content:space-between;align-items:center;'
            .'margin-bottom:1em;border-bottom:1px solid #eee;padding-bottom:.75em;";'."\n";
        $html .= '  var title = document.createElement("strong");'."\n";
        $html .= '  title.style.fontSize = "1.1em";'."\n";
        $html .= '  title.textContent = "🔧 Schema.org JSON-LD Debug";'."\n";
        $html .= '  var actions = document.createElement("div");'."\n";
        $html .= '  actions.style.cssText = "display:flex;gap:.5em;align-items:center;";'."\n";
        $html .= '  var validatorLink = document.createElement("a");'."\n";
        $html .= '  validatorLink.href = "https://validator.schema.org";'."\n";
        $html .= '  validatorLink.target = "_blank";'."\n";
        $html .= '  validatorLink.rel = "noopener";'."\n";
        $html .= '  validatorLink.style.cssText = "font-size:.85em;color:#1a73e8;text-decoration:none;'
            .'border:1px solid #1a73e8;border-radius:3px;padding:.2em .6em;";'."\n";
        $html .= '  validatorLink.textContent = "validator.schema.org öffnen ↗";'."\n";
        $html .= '  var closeBtn = document.createElement("button");'."\n";
        $html .= '  closeBtn.id = "schemaOrgData-debug-close";'."\n";
        $html .= '  closeBtn.type = "button";'."\n";
        $html .= '  closeBtn.style.cssText = "background:none;border:none;font-size:1.3em;cursor:pointer;'
            .'color:#666;padding:.1em .4em;";'."\n";
        $html .= '  closeBtn.setAttribute("aria-label", "Schließen");'."\n";
        $html .= '  closeBtn.textContent = "✕";'."\n";
        $html .= '  actions.appendChild(validatorLink);'."\n";
        $html .= '  actions.appendChild(closeBtn);'."\n";
        $html .= '  header.appendChild(title);'."\n";
        $html .= '  header.appendChild(actions);'."\n";
        $html .= '  dialog.appendChild(header);'."\n";

        $html .= '  function fallbackCopy(text){'."\n";
        $html .= '    var ta = document.createElement("textarea");'."\n";
        $html .= '    ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0";'."\n";
        // dialog.showModal() macht alles außerhalb des Dialogs inert - eine an
        // document.body gehängte Hilfs-Textarea liegt dann im inerten Teilbaum,
        // ta.focus() schlägt still fehl und die Selection bleibt leer, während
        // execCommand("copy") trotzdem true zurückliefert (kopiert die leere
        // Selection statt text). Innerhalb von dialog ist nichts inert.
        $html .= '    dialog.appendChild(ta); ta.focus(); ta.select();'."\n";
        $html .= '    var success = false;'."\n";
        $html .= '    try{ success = document.execCommand("copy"); }catch(e){ success = false; }'."\n";
        $html .= '    dialog.removeChild(ta);'."\n";
        $html .= '    return success;'."\n";
        $html .= '  }'."\n";

        $html .= '  schemaOrgDataDebugData.blocks.forEach(function(block, i){'."\n";
        $html .= '    var section = document.createElement("div");'."\n";
        $html .= '    section.style.marginBottom = "1.5em";'."\n";
        $html .= '    var blockHeader = document.createElement("div");'."\n";
        $html .= '    blockHeader.style.cssText = "display:flex;justify-content:space-between;'
            .'align-items:center;margin-bottom:.4em;";'."\n";
        $html .= '    var h3 = document.createElement("h3");'."\n";
        $html .= '    h3.style.cssText = "margin:0;font-size:.95em;color:#333;";'."\n";
        $html .= '    h3.textContent = block.scope + " — " + block.type;'."\n";
        $html .= '    var copyBtn = document.createElement("button");'."\n";
        $html .= '    copyBtn.id = "schemaOrgData-debug-copy-" + i;'."\n";
        $html .= '    copyBtn.type = "button";'."\n";
        $html .= '    copyBtn.style.cssText = "font-size:.8em;background:#f5f5f5;border:1px solid #ccc;'
            .'border-radius:3px;padding:.2em .6em;cursor:pointer;";'."\n";
        $html .= '    copyBtn.textContent = "JSON kopieren";'."\n";
        $html .= '    blockHeader.appendChild(h3);'."\n";
        $html .= '    blockHeader.appendChild(copyBtn);'."\n";
        $html .= '    var pre = document.createElement("pre");'."\n";
        $html .= '    pre.id = "schemaOrgData-debug-pre-" + i;'."\n";
        $html .= '    pre.style.cssText = "background:#f8f8f8;border:1px solid #ddd;border-radius:4px;'
            .'padding:.75em;overflow:auto;font-size:.8em;white-space:pre-wrap;margin:0;";'."\n";
        $html .= '    pre.textContent = block.json;'."\n";
        $html .= '    section.appendChild(blockHeader);'."\n";
        $html .= '    section.appendChild(pre);'."\n";
        $html .= '    dialog.appendChild(section);'."\n";
        $html .= '    copyBtn.addEventListener("click", function(){'."\n";
        $html .= '      var text = pre.textContent || pre.innerText;'."\n";
        $html .= '      var orig = copyBtn.textContent;'."\n";
        $html .= '      function ok(){ copyBtn.textContent = "Kopiert!"; setTimeout(function(){ copyBtn.textContent = orig; }, 1500); }'."\n";
        $html .= '      function fail(){ copyBtn.textContent = "Fehler beim Kopieren"; setTimeout(function(){ copyBtn.textContent = orig; }, 1500); }'."\n";
        $html .= '      if(navigator.clipboard && window.isSecureContext){'."\n";
        $html .= '        navigator.clipboard.writeText(text).then(ok).catch(function(){ if(fallbackCopy(text)){ ok(); }else{ fail(); } });'."\n";
        $html .= '      }else{ if(fallbackCopy(text)){ ok(); }else{ fail(); } }'."\n";
        $html .= '    });'."\n";
        $html .= '  });'."\n";

        $html .= '  document.body.appendChild(trigger);'."\n";
        $html .= '  document.body.appendChild(dialog);'."\n";
        $html .= '  if(trigger && dialog && dialog.showModal){'."\n";
        $html .= '    trigger.addEventListener("click", function(){ dialog.showModal(); });'."\n";
        $html .= '  }'."\n";
        $html .= '  closeBtn.addEventListener("click", function(){ dialog.close(); });'."\n";
        $html .= '}'."\n";

        // Der Platzhalter {schemaOrgData} steht im <head> - dort existiert
        // document.body zum Ausführungszeitpunkt dieses Skripts noch nicht,
        // daher der DOMContentLoaded-Umweg (mit Sofort-Ausführung als
        // Fallback, falls das Skript ausnahmsweise erst nach DOMContentLoaded
        // eingefügt wird).
        $html .= 'if(document.readyState === "loading"){'."\n";
        $html .= '  document.addEventListener("DOMContentLoaded", schemaOrgDataInitDebugWidget);'."\n";
        $html .= '}else{'."\n";
        $html .= '  schemaOrgDataInitDebugWidget();'."\n";
        $html .= '}'."\n";
        $html .= '})();</script>'."\n";

        return $html;
    }
}
