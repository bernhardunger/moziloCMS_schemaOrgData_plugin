<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_FrontendRenderer:
* renderFrontend() (Frontend-Ausgabepipeline von getContent()) und
* buildDebugWidget(). Echte, zustandslose
* SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_JsonLdBuilder-/SchemaOrgData_IdReferenceService-/
* SchemaOrgData_CollisionDetector-/SchemaOrgData_UrlHelper-Instanzen,
* $pluginSelfDir zeigt auf ein temporäres Verzeichnis (Kopie von
* schemas/), $settings ist ein isolierter InMemorySettings-Stub. Der
* Facade-Delegator-Vertrag (getContent()) ist bereits durch
* JsonLdOutputTest.php vollständig end-to-end abgedeckt - hier wird
* nur die Komponente selbst ohne Fassaden-Overhead geprüft.
*
* Hinweis zu CAT_REQUEST/PAGE_REQUEST: In tests/bootstrap.php sind
* beide Konstanten fest auf "false" gesetzt (siehe JsonLdOutputTest.php).
* Testfälle, die eine aktive Kategorie/Seite voraussetzen (excluded_cats-
* Unterdrückung, Scope-Beschränkung von org_relations), sind daher analog
* JsonLdOutputTest.php als markTestSkipped() dokumentiert.
*
***************************************************************/
final class FrontendRendererTest extends TestCase {

    // Muster des JSON-Datenblocks, den buildDebugWidget() ausgibt und
    // js/debug-widget.js liest.
    private const DEBUG_DATA_PATTERN = '#<script type="application/json" id="schemaOrgData-debug-data">\n(\{.*\})\n</script>#s';

    private string $pluginDir;

    protected function setUp(): void {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_test_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->pluginDir);
    }

    private function copyDirectory(string $from, string $to): void {
        mkdir($to, 0777, true);
        foreach(glob($from.'/*') as $file) {
            copy($file, $to.'/'.basename($file));
        }
    }

    private function removeDirectory(string $dir): void {
        foreach(glob(rtrim($dir, '/').'/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    private function renderer(): \SchemaOrgData_FrontendRenderer {
        return new \SchemaOrgData_FrontendRenderer();
    }

    private function scopeResolver(): \SchemaOrgData_ScopeResolver {
        return new \SchemaOrgData_ScopeResolver();
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function jsonLdBuilder(): \SchemaOrgData_JsonLdBuilder {
        return new \SchemaOrgData_JsonLdBuilder();
    }

    private function idReferenceService(): \SchemaOrgData_IdReferenceService {
        return new \SchemaOrgData_IdReferenceService();
    }

    private function collisionDetector(): \SchemaOrgData_CollisionDetector {
        return new \SchemaOrgData_CollisionDetector();
    }

    private function urlHelper(): \SchemaOrgData_UrlHelper {
        return new \SchemaOrgData_UrlHelper();
    }

    private function personsRegistryService(): \SchemaOrgData_PersonsRegistryService {
        return new \SchemaOrgData_PersonsRegistryService();
    }

    private function orgRelationsService(): \SchemaOrgData_OrgRelationsService {
        return new \SchemaOrgData_OrgRelationsService();
    }

    private function callRenderFrontend($value, \InMemorySettings $settings): string {
        return $this->renderer()->renderFrontend(
            $value,
            new \SchemaOrgData_FrontendRequestContext(
                $settings, $this->pluginDir, $this->scopeResolver(),
                $this->schemaRepository(), $this->jsonLdBuilder(), $this->idReferenceService(),
                $this->collisionDetector(), $this->urlHelper(), $this->personsRegistryService(),
                $this->orgRelationsService()
            )
        );
    }

    private function validLocalBusinessConfig(string $name = 'Beispiel GmbH'): array {
        return [
            'name' => $name,
            'url' => 'https://www.beispiel-domain.example',
        ];
    }

    /***************************************************************
    *
    * Führt $body mit einer temporären Layout-Template-Datei des
    * übergebenen Inhalts als $GLOBALS['TEMPLATE_FILE'] aus und setzt
    * den vorherigen Wert danach zurück. renderFrontend() liest das
    * Template über `global $TEMPLATE_FILE` - die Kollisionserkennung
    * des Global-Scopes hängt damit an dieser Datei, nicht an einem
    * gespeicherten Meta-Wert.
    *
    ***************************************************************/
    private function withTemplateFile(string $html, callable $body): void {
        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, $html);
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $body();
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    private function templateWithJsonLd(): string {
        return '<script type="application/ld+json">{"@context":"https://schema.org"}</script>';
    }

    // -----------------------------------------------------------
    // Grundausgabe
    // -----------------------------------------------------------

    function testRenderFrontendGlobalConfigProducesScriptBlock(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);

        $output = $this->callRenderFrontend('', $settings);

        $this->assertMatchesRegularExpression(
            '#^<script type="application/ld\+json">\n.*\n</script>\n$#s',
            $output
        );
        $this->assertStringContainsString('"@type": "LocalBusiness"', $output);
    }

    function testRenderFrontendOhneKonfigurationLiefertLeerenString(): void {
        $settings = new \InMemorySettings();

        $this->assertSame('', $this->callRenderFrontend('', $settings));
    }

    // -----------------------------------------------------------
    // Galerie-Vollansicht (galtemplate-Request-Parameter)
    // -----------------------------------------------------------

    function testRenderFrontendGalerieVollansichtUnterdrueckOutput(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);

        $prevGet = $_GET;
        $_GET['galtemplate'] = 'true';

        try {
            $this->assertSame('', $this->callRenderFrontend('', $settings));
        } finally {
            $_GET = $prevGet;
        }
    }

    function testRenderFrontendOhneGalerieVollansichtBleibtUnveraendert(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);

        $prevGet = $_GET;
        unset($_GET['galtemplate']);

        try {
            $this->assertStringContainsString('"@type": "LocalBusiness"', $this->callRenderFrontend('', $settings));
        } finally {
            $_GET = $prevGet;
        }
    }

    function testRenderFrontendExcludedCatsNichtTestbarOhneAktiveKategorie(): void {
        $this->markTestSkipped(
            'CAT_REQUEST ist in tests/bootstrap.php fest auf "false" gesetzt '
            .'(keine aktive Kategorie) und kann daher nie in excluded_cats '
            .'enthalten sein - identische Einschränkung wie '
            .'JsonLdOutputTest::testCategoryInExcludedCatsSuppressesGlobalBlock().'
        );
    }

    // -----------------------------------------------------------
    // jsonld_mode
    // -----------------------------------------------------------

    function testRenderFrontendJsonldModeKeepUnterdrueckOutput(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            '_meta' => ['jsonld_mode' => 'keep'],
        ]);

        $this->withTemplateFile($this->templateWithJsonLd(), function() use ($settings): void {
            $this->assertSame('', $this->callRenderFrontend('', $settings));
        });
    }

    function testRenderFrontendJsonldModeOverrideProduziertOutput(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            '_meta' => ['jsonld_mode' => 'override'],
        ]);

        $this->withTemplateFile($this->templateWithJsonLd(), function() use ($settings): void {
            $this->assertStringContainsString('"@type": "LocalBusiness"', $this->callRenderFrontend('', $settings));
        });
    }

    function testRenderFrontendOverrideOhneTypeSchaltetNichtsFrei(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['_meta' => ['jsonld_mode' => 'override']]);

        $this->withTemplateFile($this->templateWithJsonLd(), function() use ($settings): void {
            $this->assertSame('', $this->callRenderFrontend('', $settings));
        });
    }

    // -----------------------------------------------------------
    // org_relations: Scope-Beschränkung auf den globalen Organisations-
    // Knoten (org_relations wird ausschließlich unter config_global
    // gespeichert, siehe SchemaOrgData_OrgRelationsService)
    //
    // Beide folgenden Szenarien setzen einen aktiven Kategorie-Scope
    // voraus (ein zweiter, von Global verschiedener Type mit
    // "ui:idFragment": "organization" auf Kategorie-Ebene). CAT_REQUEST
    // ist in tests/bootstrap.php fest auf "false" gesetzt, wodurch
    // renderFrontend() $scopeConfigs['category'] nie befüllt - identische
    // strukturelle Einschränkung wie bei den übrigen CAT_REQUEST-Skips in
    // dieser Datei.
    // -----------------------------------------------------------

    function testRenderFrontendOrgRelationsNurAufGlobalemKnotenNichtTestbarOhneAktiveKategorie(): void {
        $this->markTestSkipped(
            'CAT_REQUEST ist in tests/bootstrap.php fest auf "false" gesetzt '
            .'(keine aktive Kategorie) - ein zweiter, von Global verschiedener '
            .'Type mit "ui:idFragment": "organization" auf Kategorie-Ebene kann '
            .'daher nie gleichzeitig mit dem globalen Organisations-Knoten aktiv '
            .'sein. Der Scope-Beschränkungs-Fix selbst ist über die Bedingung '
            .'$scope === \'global\' im Merge-Loop von renderFrontend() sowie über '
            .'den auf $scopeConfigs[\'global\'] beschränkten $orgNodePresent-Vor-Check '
            .'abgesichert.'
        );
    }

    function testRenderFrontendOrgRelationsAufReinemKategorieKnotenNichtTestbarOhneAktiveKategorie(): void {
        $this->markTestSkipped(
            'CAT_REQUEST ist in tests/bootstrap.php fest auf "false" gesetzt '
            .'(keine aktive Kategorie) - ein reiner Kategorie-Type mit '
            .'"ui:idFragment": "organization" ohne aktiven globalen '
            .'Organisations-Type kann daher nicht über renderFrontend() erreicht '
            .'werden. Identische strukturelle Einschränkung wie '
            .'testRenderFrontendOrgRelationsNurAufGlobalemKnotenNichtTestbarOhneAktiveKategorie().'
        );
    }

    // -----------------------------------------------------------
    // Debug-Modus
    // -----------------------------------------------------------

    function testRenderFrontendDebugOutputHaengtDebugWidgetAn(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            'debug_output' => '1',
        ]);

        $output = $this->callRenderFrontend('', $settings);

        $this->assertStringContainsString('id="schemaOrgData-debug-data"', $output);
        $this->assertStringContainsString('js/debug-widget.js?v=', $output);
    }

    function testRenderFrontendOhneDebugOutputKeinDebugWidget(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);

        $this->assertStringNotContainsString('schemaOrgData-debug-data', $this->callRenderFrontend('', $settings));
    }

    // -----------------------------------------------------------
    // Kollisionserkennung (existing_jsonld-Meta)
    // -----------------------------------------------------------

    /***************************************************************
    *
    * Ein gespeicherter Bestand, der nach Abzug der Verwaltungsdaten
    * (_meta, excluded_cats, debug_output, org_relations) keinen
    * Schema-Type mehr trägt, gilt der Emission als nicht konfiguriert.
    * Ein solcher Bestand entsteht real durch den Meta-Write des
    * Admin-Renderpfads und durch ein Speichern mit "- kein Schema -".
    *
    * Das Template trägt hier bewusst keinen JSON-LD-Block: Bei einem
    * Treffer entfernte der keep-Zweig den Scope schon vor der
    * Typ-Auflösung, und der Test wäre aus dem falschen Grund grün.
    *
    ***************************************************************/
    function testRenderFrontendMetaOhneTypeEmittiertNichts(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => ['existing_jsonld' => false, 'jsonld_mode' => 'keep'],
        ]);

        $this->withTemplateFile('<html><head></head></html>', function() use ($settings): void {
            $this->assertSame('', $this->callRenderFrontend('', $settings));
        });
    }

    function testRenderFrontendMetaMitTypeEmittiertBlock(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => ['existing_jsonld' => false, 'jsonld_mode' => 'keep'],
            'LocalBusiness' => $this->validLocalBusinessConfig(),
        ]);

        $this->withTemplateFile('<html><head></head></html>', function() use ($settings): void {
            $this->assertStringContainsString('"@type": "LocalBusiness"', $this->callRenderFrontend('', $settings));
        });
    }

    /***************************************************************
    *
    * Der Global-Scope entscheidet gegen den Live-Zustand des
    * Layout-Templates, nicht gegen das gespeicherte Erkennungsflag:
    * Ein gespeichertes existing_jsonld=true unterdrückt die eigene
    * Ausgabe nicht mehr, sobald der fremde Block aus dem Layout
    * entfernt wurde.
    *
    ***************************************************************/
    function testRenderFrontendGlobalEntscheidetGegenLiveTemplateStattGespeichertemFlag(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep'],
        ]);

        $this->withTemplateFile('<html><head></head></html>', function() use ($settings): void {
            $this->assertStringContainsString('"@type": "LocalBusiness"', $this->callRenderFrontend('', $settings));
        });
    }

    /***************************************************************
    *
    * Gegenrichtung: Ein Block, der erst nach dem letzten Öffnen der
    * Plugin-Verwaltung ins Layout kam, wirkt sofort - obwohl das
    * gespeicherte Flag noch false ist.
    *
    ***************************************************************/
    function testRenderFrontendLiveTemplateTrefferUnterdruecktTrotzGespeichertemFalse(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            '_meta' => ['existing_jsonld' => false, 'jsonld_mode' => 'keep'],
        ]);

        $this->withTemplateFile($this->templateWithJsonLd(), function() use ($settings): void {
            $this->assertSame('', $this->callRenderFrontend('', $settings));
        });
    }

    /***************************************************************
    *
    * renderFrontend() schreibt im Frontend nichts mehr in die
    * Settings: Properties::set() ist außerhalb von IS_ADMIN ohnehin
    * ein No-Op, der Wert für den Admin-Hinweis stammt allein aus dem
    * Admin-Pfad.
    *
    ***************************************************************/
    function testRenderFrontendSchreibtKeinMetaInDieSettings(): void {
        $settings = new \InMemorySettings();

        $this->withTemplateFile($this->templateWithJsonLd(), function() use ($settings): void {
            $this->callRenderFrontend('', $settings);

            $this->assertFalse($settings->keyExists('config_global'));
        });
    }

    // -----------------------------------------------------------
    // buildDebugWidget()
    //
    // Das Widget-Markup (Trigger-Button, <dialog>, Vorschau-Blöcke) wird
    // nicht als statisches HTML zurückgegeben, sondern als JSON-Nutzlast
    // in einen <script type="application/json">-Block gelegt und von
    // js/debug-widget.js zur Laufzeit aufgebaut (siehe README.md,
    // Abschnitt "JSON-LD-Ausgabe"). Die folgenden Tests extrahieren die
    // Nutzlast aus diesem Datenblock und prüfen sie strukturiert per
    // json_decode(), statt <button>/<dialog>/<pre>-Tags direkt im
    // Rückgabewert zu suchen.
    // -----------------------------------------------------------

    private function debugBlock(string $scope, string $type, array $data, string $id = ''): array {
        return ['scope' => $scope, 'type' => $type, 'data' => $data, 'id' => $id];
    }

    private function buildDebugWidget(array $blocks, array $suppressedIdTargets = []): string {
        return $this->renderer()->buildDebugWidget(
            $blocks, $this->jsonLdBuilder(), $this->schemaRepository(),
            $this->urlHelper(), $this->pluginDir, $suppressedIdTargets,
            null, 'http://localhost/plugins/schemaOrgData/'
        );
    }

    private function extractDebugPayload(string $html): array {
        $this->assertMatchesRegularExpression(
            self::DEBUG_DATA_PATTERN, $html,
            'Erwarteten JSON-Datenblock des Debug-Widgets nicht gefunden.'
        );
        preg_match(self::DEBUG_DATA_PATTERN, $html, $matches);

        return json_decode($matches[1], true);
    }

    /***************************************************************
    *
    * Regressionstest gegen invalides HTML an der {schemaOrgData}-
    * Platzhalterstelle im <head>: <button>/<dialog> sind dort keine
    * gültigen Metadaten-Elemente. Der Rückgabewert darf daher außerhalb
    * eines <script>-Blocks kein <button- oder <dialog-Tag mehr enthalten.
    *
    ***************************************************************/
    function testBuildDebugWidgetEnthaeltKeinButtonOderDialogAusserhalbDesScriptBlocks(): void {
        $html = $this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        );

        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html);

        $this->assertStringNotContainsString('<button', $withoutScripts);
        $this->assertStringNotContainsString('<dialog', $withoutScripts);
    }

    function testBuildDebugWidgetGibtGenauZweiScriptBloeckeZurueck(): void {
        $html = trim($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        ));

        // Kein nackter <script>-Block mehr: der erste trägt die Nutzlast
        // als application/json, der zweite bindet das Widget-Skript ein.
        $this->assertSame(0, substr_count($html, '<script>'));
        $this->assertSame(1, substr_count($html, '<script type="application/json" id="schemaOrgData-debug-data">'));
        $this->assertSame(1, substr_count($html, '<script src="'));
        $this->assertSame(2, substr_count($html, '</script>'));
        $this->assertMatchesRegularExpression('#^<script type="application/json".*</script>$#s', $html);
    }

    function testBuildDebugWidgetSingularBeiEinemBlock(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        ));

        $this->assertSame('1 JSON-LD-Block', $payload['label']);
        $this->assertCount(1, $payload['blocks']);
    }

    function testBuildDebugWidgetPluralUndEinPreBlockJeEintrag(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [
                $this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH']),
                $this->debugBlock('global', 'WebSite', ['name' => 'Beispiel-Website']),
            ]
        ));

        $this->assertSame('2 JSON-LD-Blöcke', $payload['label']);
        $this->assertCount(2, $payload['blocks']);
        $this->assertSame('LocalBusiness', $payload['blocks'][0]['type']);
        $this->assertSame('WebSite', $payload['blocks'][1]['type']);
    }

    /***************************************************************
    *
    * PP4: die ProfilePage + zugehöriger Person-Knoten sind über den
    * generischen Mechanismus oben (beliebige Blöcke landen als eigene
    * Vorschau-Einträge) bereits abgedeckt - dieser Test hält die konkrete
    * Type-Paarung trotzdem explizit fest, damit sie nicht nur beiläufig
    * über den generischen Fall mitläuft.
    *
    ***************************************************************/
    function testBuildDebugWidgetZeigtProfilePageUndZugehoerigenPersonBlock(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [
                $this->debugBlock('page_ueber-uns_anna-muster', 'ProfilePage', [
                    'mainEntity' => ['_mode' => 'reference', '_fragment' => 'person-anna-muster'],
                ]),
                $this->debugBlock('person_anna-muster', 'Person', ['name' => 'Anna Muster']),
            ]
        ));

        $this->assertSame('2 JSON-LD-Blöcke', $payload['label']);
        $this->assertSame('ProfilePage', $payload['blocks'][0]['type']);
        $this->assertSame('Person', $payload['blocks'][1]['type']);
    }

    function testBuildDebugWidgetJsonInhaltEntsprichtBuildJsonLdScriptTransformation(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Müller &amp; Partner', 'description' => ''])]
        ));

        // removeEmptyJsonLdProperties() entfernt die leere Property
        // "description", Feldwerte bleiben unverändert - dieselben
        // Transformationen wie in buildJsonLdScript(). Die Vorschau wird zur
        // Laufzeit per textContent gesetzt (kein HTML-Escaping mehr
        // nötig/vorhanden).
        $this->assertStringContainsString('"name": "Müller &amp; Partner"', $payload['blocks'][0]['json']);
        $this->assertStringNotContainsString('"description"', $payload['blocks'][0]['json']);
    }

    function testBuildDebugWidgetJsonHexTagBleibtByteIdentischZuBuildJsonLdScript(): void {
        $payload = '</script><script>alert(1)</script>';
        $data = ['name' => $payload];

        $html = $this->buildDebugWidget(
            [$this->debugBlock('global', 'Organization', $data)]
        );

        // Kein literales </script> irgendwo im Rückgabewert - würde sonst
        // den JSON-Datenblock der Seite aufbrechen. JSON_HEX_TAG
        // greift hier identisch wie in buildJsonLdScript().
        $this->assertStringNotContainsString('</script><script>alert', $html);

        $decoded = $this->extractDebugPayload($html);
        $actualJson = $decoded['blocks'][0]['json'];

        $script = $this->jsonLdBuilder()->buildJsonLdScript(
            $this->schemaRepository(), $this->urlHelper(), $this->pluginDir,
            'Organization', $data
        );
        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>\n$#s', $script, $scriptMatches);
        $expectedJson = $scriptMatches[1];

        $this->assertSame($expectedJson, $actualJson);
    }

    /***************************************************************
    *
    * buildDebugWidget() zeigte location als schema-typloses Objekt
    * und id_reference_or_literal-Werte (z. B.
    * Event.organizer) als rohe {"_mode": ...}-Repräsentation, statt
    * denselben Transformationen wie buildJsonLdScript() zu folgen.
    *
    ***************************************************************/
    function testBuildDebugWidgetZeigtLocationAlsPlaceMitVerschachtelterPostalAddress(): void {
        $data = [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'location' => [
                'name' => 'Stadtpark',
                'address' => ['addressLocality' => 'Musterstadt', 'addressCountry' => 'DE'],
            ],
        ];

        $json = $this->extractDebugPayload($this->buildDebugWidget([$this->debugBlock('page_x_y', 'Event', $data)]))['blocks'][0]['json'];

        $this->assertStringContainsString('"@type": "Place"', $json);
        $this->assertStringContainsString('"@type": "PostalAddress"', $json);
    }

    function testBuildDebugWidgetLoestIdReferenceOrLiteralAufStattRohemModeZuZeigen(): void {
        $serverBackup = $_SERVER;
        try {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_HOST'] = 'www.example.org';
            $_SERVER['SCRIPT_NAME'] = '/index.php';

            $data = [
                'name' => 'Sommerfest',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'organizer' => ['_mode' => 'reference', '_fragment' => 'organization'],
            ];

            $json = $this->extractDebugPayload($this->buildDebugWidget([$this->debugBlock('page_x_y', 'Event', $data)]))['blocks'][0]['json'];

            $this->assertStringNotContainsString('_mode', $json);
            $this->assertStringContainsString('"@id": "https://www.example.org/#organization"', $json);
        } finally {
            $_SERVER = $serverBackup;
        }
    }

    /***************************************************************
    *
    * Ungültiges UTF-8 in einem Feldwert lässt json_encode() in
    * buildJsonLdScript() scheitern; der Block kommt von dort als
    * Leerstring zurück. Die Vorschau zeigt dann keine wortlos leere Box
    * unter einem Titel, sondern benennt den Grund.
    *
    ***************************************************************/
    function testBuildDebugWidgetBenenntNichtKodierbarenBlockStattLeererVorschau(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => "Ungültig \xB1\x31"])]
        ));

        $this->assertCount(1, $payload['blocks']);
        $this->assertSame('LocalBusiness', $payload['blocks'][0]['type']);
        $this->assertStringContainsString('Vorschau nicht darstellbar', $payload['blocks'][0]['json']);
    }

    /***************************************************************
    *
    * Ungültiges UTF-8 im Type erreicht die äußere Kodierung der Nutzlast:
    * Der Type steht dort unabhängig von der Block-Vorschau. Ohne
    * Ersatz-Nutzlast käme der Datenblock leer heraus, und
    * js/debug-widget.js baute stillschweigend nichts auf.
    *
    ***************************************************************/
    function testBuildDebugWidgetLiefertErsatzNutzlastWennDieNutzlastNichtKodierbarIst(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [$this->debugBlock('global', "Ungültig \xB1\x31", ['name' => 'Beispiel GmbH'])]
        ));

        $this->assertSame('Vorschau nicht darstellbar', $payload['label']);
        $this->assertCount(1, $payload['blocks']);
        $this->assertSame('Fehler', $payload['blocks'][0]['type']);
    }
}
