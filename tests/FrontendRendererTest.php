<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_FrontendRenderer
* (Refactoring-Schritt 13, siehe doc/adr_komponenten_refactoring.md):
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

    private function callRenderFrontend($value, \InMemorySettings $settings): string {
        return $this->renderer()->renderFrontend(
            $value, $settings, $this->pluginDir,
            $this->scopeResolver(), $this->schemaRepository(), $this->jsonLdBuilder(),
            $this->idReferenceService(), $this->collisionDetector(), $this->urlHelper()
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
    // -----------------------------------------------------------

    private function debugBlock(string $scope, string $type, array $data, string $id = ''): array {
        return ['scope' => $scope, 'type' => $type, 'data' => $data, 'id' => $id];
    }

    function testBuildDebugWidgetSingularBeiEinemBlock(): void {
        $html = $this->renderer()->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])],
            $this->jsonLdBuilder()
        );

        $this->assertStringContainsString('1 JSON-LD-Block<', $html);
    }

    function testBuildDebugWidgetPluralUndEinPreBlockJeEintrag(): void {
        $html = $this->renderer()->buildDebugWidget(
            [
                $this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH']),
                $this->debugBlock('global', 'WebSite', ['name' => 'Beispiel-Website']),
            ],
            $this->jsonLdBuilder()
        );

        $this->assertStringContainsString('2 JSON-LD-Blöcke<', $html);
        $this->assertSame(2, substr_count($html, '<pre id="schemaOrgData-debug-pre-'));
    }

    function testBuildDebugWidgetJsonInhaltEntsprichtBuildJsonLdScriptTransformation(): void {
        $html = $this->renderer()->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Müller &amp; Partner', 'description' => ''])],
            $this->jsonLdBuilder()
        );

        // decodeJsonLdValues() dekodiert "&amp;" zu "&" (anschließend erneut
        // per htmlspecialchars() im <pre> escaped), removeEmptyJsonLdProperties()
        // entfernt die leere Property "description" - dieselben Transformationen
        // wie in buildJsonLdScript().
        $this->assertStringContainsString('&quot;name&quot;: &quot;Müller &amp; Partner&quot;', $html);
        $this->assertStringNotContainsString('&quot;description&quot;', $html);
    }

    function testBuildDebugWidgetEnthaeltValidatorLink(): void {
        $html = $this->renderer()->buildDebugWidget(
            [$this->debugBlock('global', 'LocalBusiness', ['name' => 'Beispiel GmbH'])],
            $this->jsonLdBuilder()
        );

        $this->assertStringContainsString('href="https://validator.schema.org"', $html);
    }
}
