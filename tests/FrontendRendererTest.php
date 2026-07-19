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
* Unterdrückung, Seiten-Scope-Kollisionserkennung), sind daher analog
* JsonLdOutputTest.php als markTestSkipped() dokumentiert.
*
***************************************************************/
final class FrontendRendererTest extends TestCase {

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
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep'],
        ]);

        $this->assertSame('', $this->callRenderFrontend('', $settings));
    }

    function testRenderFrontendJsonldModeOverrideProduziertOutput(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            'LocalBusiness' => $this->validLocalBusinessConfig(),
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'override'],
        ]);

        $this->assertStringContainsString('"@type": "LocalBusiness"', $this->callRenderFrontend('', $settings));
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

        $this->assertStringContainsString('schemaOrgData-debug-trigger', $output);
        $this->assertStringContainsString('schemaOrgData-debug-dialog', $output);
    }

    function testRenderFrontendOhneDebugOutputKeinDebugWidget(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => $this->validLocalBusinessConfig()]);

        $this->assertStringNotContainsString('schemaOrgData-debug-trigger', $this->callRenderFrontend('', $settings));
    }

    // -----------------------------------------------------------
    // Kollisionserkennung (existing_jsonld-Meta)
    // -----------------------------------------------------------

    function testRenderFrontendTemplateJsonLdSchreibtGlobalMeta(): void {
        $settings = new \InMemorySettings();

        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents($templateFile, '<script type="application/ld+json">{"@context":"https://schema.org"}</script>');
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $this->callRenderFrontend('', $settings);

            $metaGlobal = $this->scopeResolver()->loadScopeMeta($settings, 'global');
            $this->assertTrue($metaGlobal['existing_jsonld']);
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    /***************************************************************
    *
    * existing_jsonld_blocks wird als Einzelblock-Array persistiert -
    * Grundlage des serverseitigen Pro-Block-Imports (siehe
    * SchemaOrgData_ScopeResolver::loadScopeMeta()).
    *
    ***************************************************************/
    function testRenderFrontendTemplateJsonLdPersistiertBlocksArray(): void {
        $settings = new \InMemorySettings();

        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents(
            $templateFile,
            '<script type="application/ld+json">{"@type":"LocalBusiness"}</script>'
            .'<script type="application/ld+json">{"@type":"WebSite"}</script>'
        );
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $this->callRenderFrontend('', $settings);

            $metaGlobal = $this->scopeResolver()->loadScopeMeta($settings, 'global');
            $this->assertSame(
                ['{"@type":"LocalBusiness"}', '{"@type":"WebSite"}'],
                $metaGlobal['existing_jsonld_blocks']
            );
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    /***************************************************************
    *
    * Der Schreib-Guard greift auch bei reiner Reihenfolge-Änderung
    * der Blöcke, bei der Flag und implodierter Content-String gleich
    * blieben (implode() ist reihenfolge-sensitiv, der reine
    * String-Vergleich griffe hier zwar bereits - dieser Test
    * dokumentiert den Fall explizit als Regressionsanker für den
    * neuen Blocks-Vergleich).
    *
    ***************************************************************/
    function testRenderFrontendTemplateJsonLdSchreibtBeiBlockReihenfolgeAenderung(): void {
        $settings = new \InMemorySettings();
        $this->scopeResolver()->saveScopeMeta($settings, 'global', [
            'existing_jsonld' => true,
            'existing_jsonld_content' => "{\"@type\":\"WebSite\"}\n\n{\"@type\":\"LocalBusiness\"}",
            'existing_jsonld_blocks' => ['{"@type":"WebSite"}', '{"@type":"LocalBusiness"}'],
        ]);

        $templateFile = sys_get_temp_dir().'/schemaOrgData_tpl_'.uniqid().'.html';
        file_put_contents(
            $templateFile,
            '<script type="application/ld+json">{"@type":"LocalBusiness"}</script>'
            .'<script type="application/ld+json">{"@type":"WebSite"}</script>'
        );
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = $templateFile;

        try {
            $this->callRenderFrontend('', $settings);

            $metaGlobal = $this->scopeResolver()->loadScopeMeta($settings, 'global');
            $this->assertSame(
                ['{"@type":"LocalBusiness"}', '{"@type":"WebSite"}'],
                $metaGlobal['existing_jsonld_blocks']
            );
        } finally {
            unlink($templateFile);
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testRenderFrontendOhneTemplateTrefferGlobalMetaBleibtFalse(): void {
        $settings = new \InMemorySettings();
        $prevTemplate = $GLOBALS['TEMPLATE_FILE'] ?? null;
        $GLOBALS['TEMPLATE_FILE'] = '';

        try {
            $this->callRenderFrontend('', $settings);

            $metaGlobal = $this->scopeResolver()->loadScopeMeta($settings, 'global');
            $this->assertFalse($metaGlobal['existing_jsonld']);
        } finally {
            $GLOBALS['TEMPLATE_FILE'] = $prevTemplate;
        }
    }

    function testRenderFrontendSeitenScopeMetaNichtTestbarOhneAktiveSeite(): void {
        $this->markTestSkipped(
            'CAT_REQUEST/PAGE_REQUEST sind in tests/bootstrap.php fest auf "false" '
            .'gesetzt. Der Schreib-Guard in renderFrontend() verhindert den Write '
            .'auf den Seiten-Scope ohne eine aktiv gerenderte Seite - identische '
            .'Einschränkung wie JsonLdOutputTest::testSeiteJsonLdOhnePageRequestNichtTestbar().'
        );
    }

    // -----------------------------------------------------------
    // buildDebugWidget()
    //
    // Das Widget-Markup (Trigger-Button, <dialog>, Vorschau-Blöcke) wird
    // seit dem <head>-Validitäts-Fix nicht mehr als statisches HTML
    // zurückgegeben, sondern als JSON-Nutzlast in den einzigen
    // <script>-Block eingebettet und erst zur Laufzeit per JS aufgebaut
    // (siehe README.md, Abschnitt "JSON-LD-Ausgabe"). Die folgenden Tests
    // extrahieren die Nutzlast (schemaOrgDataDebugData = {...};) und
    // prüfen sie strukturiert per json_decode(), statt <button>/<dialog>/
    // <pre>-Tags direkt im Rückgabewert zu suchen.
    // -----------------------------------------------------------

    private function debugBlock(string $scope, string $type, array $data, string $id = ''): array {
        return ['scope' => $scope, 'type' => $type, 'data' => $data, 'id' => $id];
    }

    private function buildDebugWidget(array $blocks, array $suppressedIdTargets = []): string {
        return $this->renderer()->buildDebugWidget(
            $blocks, $this->jsonLdBuilder(), $this->schemaRepository(),
            $this->urlHelper(), $this->pluginDir, $suppressedIdTargets
        );
    }

    private function extractDebugPayload(string $html): array {
        $this->assertMatchesRegularExpression(
            '#var schemaOrgDataDebugData = (\{.*\});\n#s', $html,
            'Erwartete schemaOrgDataDebugData-Zuweisung nicht im <script>-Block gefunden.'
        );
        preg_match('#var schemaOrgDataDebugData = (\{.*\});\n#s', $html, $matches);

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

    function testBuildDebugWidgetGibtAusschliesslichEinenScriptBlockZurueck(): void {
        $html = trim($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        ));

        $this->assertMatchesRegularExpression('#^<script>.*</script>$#s', $html);
        $this->assertSame(1, substr_count($html, '<script>'));
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

    function testBuildDebugWidgetJsonInhaltEntsprichtBuildJsonLdScriptTransformation(): void {
        $payload = $this->extractDebugPayload($this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Müller &amp; Partner', 'description' => ''])]
        ));

        // decodeJsonLdValues() dekodiert "&amp;" zu "&", removeEmptyJsonLdProperties()
        // entfernt die leere Property "description" - dieselben Transformationen
        // wie in buildJsonLdScript(). Die Vorschau wird zur Laufzeit per
        // textContent gesetzt (kein HTML-Escaping mehr nötig/vorhanden).
        $this->assertStringContainsString('"name": "Müller & Partner"', $payload['blocks'][0]['json']);
        $this->assertStringNotContainsString('"description"', $payload['blocks'][0]['json']);
    }

    function testBuildDebugWidgetEnthaeltValidatorLink(): void {
        $html = $this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        );

        $this->assertStringContainsString('validatorLink.href = "https://validator.schema.org"', $html);
    }

    /***************************************************************
    *
    * Regressionstest gegen einen False-Success beim "JSON kopieren"-
    * Button: fallbackCopy() muss den tatsächlichen execCommand("copy")-
    * Erfolg zurückgeben, der Click-Handler darf "Kopiert!" nur bei
    * echtem Erfolg anzeigen statt unconditional.
    *
    ***************************************************************/
    function testBuildDebugWidgetCopyButtonPrueftFallbackCopyErfolg(): void {
        $html = $this->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])]
        );

        $this->assertStringContainsString('return success;', $html);
        $this->assertStringContainsString('function fail(){', $html);
        $this->assertStringContainsString(
            'if(fallbackCopy(text)){ ok(); }else{ fail(); }', $html
        );
    }

    function testBuildDebugWidgetJsonHexTagBleibtByteIdentischZuBuildJsonLdScript(): void {
        $payload = '</script><script>alert(1)</script>';
        $data = ['name' => $payload];

        $html = $this->buildDebugWidget(
            [$this->debugBlock('global', 'Organization', $data)]
        );

        // Kein literales </script> irgendwo im Rückgabewert - würde sonst
        // den umgebenden <script>-Block der Seite aufbrechen. JSON_HEX_TAG
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
}
