<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_FrontendRenderer
*
* Orchestriert die Frontend-Ausgabepipeline von getContent():
* Scope-Konfiguration laden, excluded_cats- und jsonld_mode='keep'-
* Filter anwenden, feldweise Vererbung und Dangling-Reference-Guard
* durchlaufen, JSON-LD-Blöcke je Type ausgeben, optional das
* Debug-Widget anhängen sowie die scope-genaue Kollisionserkennung
* (existing_jsonld-Meta) persistieren.
*
* Zustandslos: keine Konstruktor-Injection, alle Kollaboratoren
* werden je Aufruf als Parameter durchgereicht (siehe README.md,
* Abschnitt "Architektur").
*
***************************************************************/
class SchemaOrgData_FrontendRenderer {

    /***************************************************************
    *
    * Rendert die JSON-LD-<script>-Blöcke für die aktuelle Frontend-
    * Seite (1:1 übernommener Rumpf des Frontend-Zweigs von
    * getContent(), siehe README.md, Abschnitt "JSON-LD-Ausgabe").
    *
    * @param mixed $value Seiteninhalt (Platzhalterinhalt), für die
    *              Kollisionserkennung im Seiten-Scope
    * @param SchemaOrgData_FrontendRequestContext $context bündelt Settings
    *              und Kollaboratoren (siehe SchemaOrgData_FrontendRequestContext)
    * @return string fertige <script>-Blöcke (ggf. inkl. Debug-Widget)
    *
    ***************************************************************/
    public function renderFrontend(mixed $value, SchemaOrgData_FrontendRequestContext $context): string {
        global $TEMPLATE_FILE;

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
        // (siehe README.md, Abschnitt "Ausschlussliste").
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

        // Verwaltungsdaten (_meta, excluded_cats, debug_output) entfernen -
        // übrig bleiben je Ebene nur noch die Schema-Type-Konfigurationen.
        foreach($scopeConfigs as $scope => $config) {
            unset($scopeConfigs[$scope]['_meta'], $scopeConfigs[$scope]['excluded_cats'], $scopeConfigs[$scope]['debug_output']);
        }

        // jsonld_mode prüfen: wurde bereits vorhandenes JSON-LD erkannt
        // und für diese Ebene "Vorhandenes beibehalten" gewählt (Standard,
        // solange der Admin keine Wahl getroffen hat), wird die eigene
        // Ausgabe komplett unterdrückt (siehe loadScopeMeta/
        // renderExistingJsonLdNotice sowie README.md, Abschnitt
        // "Verhalten bei vorhandenem JSON-LD").
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
            if($meta['existing_jsonld'] and ($meta['jsonld_mode'] ?? 'override') === 'keep') {
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

        // Dangling-Reference-Guard: prüft, ob eine id_reference auf einen
        // @id-Knoten verweist, der auf dieser Seite nicht ausgegeben wird,
        // und erzwingt ggf. einen Minimal-Stub (nur bei excluded_cats-
        // Unterdrückung). Bei keep-Modus wird die id_reference stattdessen
        // unterdrückt (siehe applyDanglingReferenceGuard(), README.md,
        // "@id-Anker").
        [$scopeConfigs, $suppressedIdTargets] = $context->idReferenceService->applyDanglingReferenceGuard(
            $context->scopeResolver, $context->schemaRepository, $context->settings, $context->pluginSelfDir, $scopeConfigs, $globalSuppressedByKeep
        );

        // JSON-LD-Blöcke der verbleibenden Types ausgeben; bei aktivem
        // Debug-Modus Metadaten je Block für buildDebugWidget() sammeln.
        // Pro Seite vergebene @id-Fragmente, für den De-Dup-Guard in
        // resolveNodeId() (siehe README.md, "@id-Anker").
        $assignedFragments = [];

        $debugBlocks = [];
        foreach($scopeConfigs as $scope => $config) {
            foreach($config as $type => $data) {
                $nodeId = $context->jsonLdBuilder->resolveNodeId($context->schemaRepository, $context->urlHelper, $context->pluginSelfDir, $type, $assignedFragments);
                $output .= $context->jsonLdBuilder->buildJsonLdScript($context->schemaRepository, $context->urlHelper, $context->pluginSelfDir, $type, $data, $nodeId, $suppressedIdTargets);
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

        if($debugOutput and $debugBlocks !== []) {
            $output .= $this->buildDebugWidget($debugBlocks, $context->jsonLdBuilder);
        }

        // Kollisionserkennung: vorhandenes JSON-LD scope-genau persistieren
        // (Flag + Inhalt für den Autofill-Button, siehe ADR autofill).
        // Ein im Layout-Template gefundener Block ist layoutweit — er wird
        // ausschließlich dem Global-Scope zugeordnet (analog Admin-Pfad,
        // siehe extractExistingJsonLdBlocksFromTemplateAdmin() / renderAdminPage()).
        // Ein im Seiteninhalt ($value) gefundener Block ist seitenspezifisch —
        // er wird ausschließlich dem Seiten-Scope der aktuell gerenderten
        // Seite zugeordnet, sofern CAT_REQUEST und PAGE_REQUEST gesetzt sind.
        // Kategorie-Scope erhält über diesen Mechanismus keinen Eintrag.
        $templateBlocks = $context->collisionDetector->extractExistingJsonLdBlocksFromTemplate((string) ($TEMPLATE_FILE ?? ''));
        $hasJsonLdInTemplate = !empty($templateBlocks);
        $templateContent = implode("\n\n", array_map('trim', $templateBlocks));
        $metaGlobal = $context->scopeResolver->loadScopeMeta($context->settings, 'global');
        if($metaGlobal['existing_jsonld'] !== $hasJsonLdInTemplate
            || $metaGlobal['existing_jsonld_content'] !== $templateContent) {
            $context->scopeResolver->saveScopeMeta($context->settings, 'global', [
                'existing_jsonld' => $hasJsonLdInTemplate,
                'existing_jsonld_content' => $templateContent,
            ]);
        }

        $contentBlocks = $context->collisionDetector->extractExistingJsonLdBlocks((string) $value);
        $hasJsonLdInContent = !empty($contentBlocks);
        $pageContent = implode("\n\n", array_map('trim', $contentBlocks));
        if($cat !== null and $page !== null) {
            $metaPage = $context->scopeResolver->loadScopeMeta($context->settings, 'page', $cat, $page);
            if($metaPage['existing_jsonld'] !== $hasJsonLdInContent
                || $metaPage['existing_jsonld_content'] !== $pageContent) {
                $context->scopeResolver->saveScopeMeta($context->settings, 'page', [
                    'existing_jsonld' => $hasJsonLdInContent,
                    'existing_jsonld_content' => $pageContent,
                ], $cat, $page);
            }
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
    * Wird von renderFrontend() angehängt, wenn debug_output aktiv ist
    * und mindestens ein Block ausgegeben wurde. Alle Styles sind
    * inline, alle IDs mit "schemaOrgData-debug-" präfixiert — keine
    * globalen CSS-Klassen, da dies auf der echten Frontend-Seite
    * landet.
    *
    * @param array<int, array{scope: string, type: string, data: array<string, mixed>, id: string}> $blocks
    *              je Block Scope ('global'|'cat_x'|'page_x_y'), Type, Properties und @id-Anker
    * @return string HTML-Snippet inkl. <script>
    *
    ***************************************************************/
    public function buildDebugWidget(array $blocks, SchemaOrgData_JsonLdBuilder $jsonLdBuilder): string {
        $count = count($blocks);
        $plural = $count !== 1 ? 'Blöcke' : 'Block';

        $html  = '<button id="schemaOrgData-debug-trigger" type="button" '
            .'style="position:fixed;bottom:1em;right:1em;z-index:9999;background:#1a73e8;color:#fff;'
            .'border:none;border-radius:4px;padding:.5em 1em;font-size:14px;cursor:pointer;'
            .'box-shadow:0 2px 8px rgba(0,0,0,.3);">'
            .'🔧 Debug: '.$count.' JSON-LD-'.$plural.'</button>'."\n";

        $html .= '<dialog id="schemaOrgData-debug-dialog" '
            .'style="max-width:800px;width:90vw;max-height:85vh;overflow:auto;border-radius:6px;'
            .'border:1px solid #ccc;box-shadow:0 4px 24px rgba(0,0,0,.2);padding:1.5em;">'."\n";

        // Kopfzeile mit Validator-Link und Schließen-Button
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;'
            .'margin-bottom:1em;border-bottom:1px solid #eee;padding-bottom:.75em;">'."\n";
        $html .= '<strong style="font-size:1.1em;">🔧 Schema.org JSON-LD Debug</strong>'."\n";
        $html .= '<div style="display:flex;gap:.5em;align-items:center;">'."\n";
        $html .= '<a href="https://validator.schema.org" target="_blank" rel="noopener" '
            .'style="font-size:.85em;color:#1a73e8;text-decoration:none;border:1px solid #1a73e8;'
            .'border-radius:3px;padding:.2em .6em;">validator.schema.org öffnen ↗</a>'."\n";
        $html .= '<button id="schemaOrgData-debug-close" type="button" '
            .'style="background:none;border:none;font-size:1.3em;cursor:pointer;color:#666;'
            .'padding:.1em .4em;" aria-label="Schlie&szlig;en">&#x2715;</button>'."\n";
        $html .= '</div></div>'."\n";

        foreach($blocks as $i => $block) {
            $type   = $block['type'];
            $scope  = $block['scope'];
            $data   = $block['data'];
            $nodeId = (string) ($block['id'] ?? '');
            $preId  = 'schemaOrgData-debug-pre-'.$i;
            $copyId = 'schemaOrgData-debug-copy-'.$i;

            // Dieselben Transformationen wie buildJsonLdScript(), damit die
            // Vorschau byte-identisch mit dem echten <script>-Block ist.
            $data = $jsonLdBuilder->decodeJsonLdValues($data);
            $data = $jsonLdBuilder->removeEmptyJsonLdProperties($data);
            foreach(['address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'employee' => 'Person'] as $property => $nestedType) {
                if(isset($data[$property]) and is_array($data[$property])) {
                    $data[$property] = array_merge(['@type' => $nestedType], $data[$property]);
                }
            }
            $head = ['@context' => 'https://schema.org', '@type' => $type];
            if($nodeId !== '') {
                $head['@id'] = $nodeId;
            }
            $jsonLd = array_merge($head, $data);
            // JSON_HEX_TAG ergänzt, damit die Vorschau byte-identisch mit dem
            // echten <script>-Block aus buildJsonLdScript() bleibt.
            $prettyJson = json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

            $html .= '<div style="margin-bottom:1.5em;">'."\n";
            $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4em;">'."\n";
            $html .= '<h3 style="margin:0;font-size:.95em;color:#333;">'
                .htmlspecialchars($scope, ENT_QUOTES, CHARSET).' &mdash; '
                .htmlspecialchars($type, ENT_QUOTES, CHARSET).'</h3>'."\n";
            $html .= '<button id="'.$copyId.'" type="button" data-pre="'.$preId.'" '
                .'style="font-size:.8em;background:#f5f5f5;border:1px solid #ccc;border-radius:3px;'
                .'padding:.2em .6em;cursor:pointer;">JSON kopieren</button>'."\n";
            $html .= '</div>'."\n";
            $html .= '<pre id="'.$preId.'" '
                .'style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:.75em;'
                .'overflow:auto;font-size:.8em;white-space:pre-wrap;margin:0;">'
                .htmlspecialchars($prettyJson !== false ? $prettyJson : '', ENT_QUOTES, CHARSET)
                .'</pre>'."\n";
            $html .= '</div>'."\n";
        }

        $html .= '</dialog>'."\n";

        $html .= '<script>(function(){'."\n";
        $html .= 'var trigger=document.getElementById("schemaOrgData-debug-trigger");'."\n";
        $html .= 'var dialog=document.getElementById("schemaOrgData-debug-dialog");'."\n";
        $html .= 'var closeBtn=document.getElementById("schemaOrgData-debug-close");'."\n";
        $html .= 'if(trigger&&dialog&&dialog.showModal){'."\n";
        $html .= '  trigger.addEventListener("click",function(){dialog.showModal();});'."\n";
        $html .= '}'."\n";
        $html .= 'if(closeBtn&&dialog){'."\n";
        $html .= '  closeBtn.addEventListener("click",function(){dialog.close();});'."\n";
        $html .= '}'."\n";
        $html .= 'function fallbackCopy(text){'."\n";
        $html .= '  var ta=document.createElement("textarea");'."\n";
        $html .= '  ta.value=text;ta.style.position="fixed";ta.style.opacity="0";'."\n";
        $html .= '  document.body.appendChild(ta);ta.focus();ta.select();'."\n";
        $html .= '  try{document.execCommand("copy");}catch(e){}'."\n";
        $html .= '  document.body.removeChild(ta);'."\n";
        $html .= '}'."\n";
        $html .= 'var copyBtns=document.querySelectorAll("[id^=\'schemaOrgData-debug-copy-\']");'."\n";
        $html .= 'copyBtns.forEach(function(btn){'."\n";
        $html .= '  btn.addEventListener("click",function(){'."\n";
        $html .= '    var pre=document.getElementById(btn.getAttribute("data-pre"));'."\n";
        $html .= '    if(!pre)return;'."\n";
        $html .= '    var text=pre.textContent||pre.innerText;'."\n";
        $html .= '    var orig=btn.textContent;'."\n";
        $html .= '    function ok(){btn.textContent="Kopiert!";setTimeout(function(){btn.textContent=orig;},1500);}'."\n";
        $html .= '    if(navigator.clipboard&&window.isSecureContext){'."\n";
        $html .= '      navigator.clipboard.writeText(text).then(ok).catch(function(){fallbackCopy(text);ok();});'."\n";
        $html .= '    }else{fallbackCopy(text);ok();}'."\n";
        $html .= '  });'."\n";
        $html .= '});'."\n";
        $html .= '})();</script>'."\n";

        return $html;
    }
}
