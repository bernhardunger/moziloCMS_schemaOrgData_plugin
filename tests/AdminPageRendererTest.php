<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/FakeCatPage.php';

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminPageRenderer:
* reine Anzeige-Bausteine der Admin-Seite (Info-Block, Scope-Label/
* Selektor, Speichern-Button-Beschriftung, Speicher-Ergebnis-Hinweis,
* Hinweis auf vorhandenes/kollidierendes JSON-LD, Ausschlussliste,
* Admin-CSS, Schema-Type-Auswahl) - aus SchemaOrgData_AdminController
* bzw. SchemaOrgData_FormRenderer ausgelagert. Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-Instanzen, $pluginSelfDir
* zeigt auf die realen Schema-/Sprach-Fixtures des Plugins, $settings
* ist ein isolierter InMemorySettings-Stub.
*
***************************************************************/
final class AdminPageRendererTest extends TestCase {

    protected function setUp(): void {
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
    }

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function scopeResolver(): \SchemaOrgData_ScopeResolver {
        return new \SchemaOrgData_ScopeResolver();
    }

    private function schemaRepository(): \SchemaOrgData_SchemaRepository {
        return new \SchemaOrgData_SchemaRepository();
    }

    private function validator(): \SchemaOrgData_Validator {
        return new \SchemaOrgData_Validator();
    }

    private function openingHoursHelper(): \SchemaOrgData_OpeningHoursHelper {
        return new \SchemaOrgData_OpeningHoursHelper();
    }

    private function configSaveService(): \SchemaOrgData_ConfigSaveService {
        return new \SchemaOrgData_ConfigSaveService();
    }

    private function renderer(): \SchemaOrgData_AdminPageRenderer {
        return new \SchemaOrgData_AdminPageRenderer();
    }

    private function localBusinessSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'LocalBusiness');
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "FAQPage" (analog
    * AdminControllerTest::validFaqPageData()).
    *
    ***************************************************************/
    private function validFaqPageData(): array {
        return [
            'type' => 'FAQPage',
            'data' => [
                'mainEntity' => [
                    ['name' => 'Wie erreiche ich euch?', 'acceptedAnswer' => ['text' => 'Per Telefon oder E-Mail.']],
                ],
            ],
            'extension' => ['FAQPage' => ''],
        ];
    }

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->configSaveService()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->renderer(), new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_OrgRelationsService()
        );
    }

    // -----------------------------------------------------------
    // getAdminCss()
    // -----------------------------------------------------------

    function testGetAdminCssEnthaeltAdminSelektor(): void {
        $css = $this->renderer()->getAdminCss();

        $this->assertStringContainsString('.schemaOrgData-admin', $css);
        $this->assertStringContainsString('.schemaOrgData-required', $css);
    }

    /***************************************************************
    *
    * UX-Trio Admin-Formular: max-width für den
    * Formularbereich, top-bündiges Label bei Textarea-Zeilen
    * (":has(textarea)") und CSS-Klasse der Pflichtfeld-Legende.
    *
    ***************************************************************/
    function testGetAdminCssEnthaeltUxTrioRegeln(): void {
        $css = $this->renderer()->getAdminCss();

        $this->assertStringContainsString('.schemaOrgData-admin { max-width: 900px; }', $css);
        $this->assertStringContainsString(':has(textarea)', $css);
        $this->assertStringContainsString('.schemaOrgData-required-legend', $css);
    }

    /***************************************************************
    *
    * Der moziloCMS-Core setzt "details summary { display: flex }" mit
    * einem generierten Pfeil-Pseudoelement voraus, dass der Text in
    * einem eigenen Kind-Element mit "align-items: center" steckt
    * (Core-Konvention: <summary><span class="flex">...</span></summary>).
    * Dieses Plugin rendert stattdessen reinen Text direkt in <summary>
    * (renderInfoBlock(), Import-Bereich) - ohne eigenes
    * "align-items: center" fällt der Flex-Container auf den Default
    * "stretch" zurück und der Pfeil erscheint sichtbar versetzt zum
    * Text statt sauber davor. Ein reiner "Klasse vorhanden"-Check würde
    * das nicht abbilden, deshalb wird hier gezielt die deklarierte
    * CSS-Eigenschaft geprüft, die den Bugmechanismus behebt.
    *
    ***************************************************************/
    function testGetAdminCssRichtetDetailsSummaryMarkerVertikalAus(): void {
        $css = $this->renderer()->getAdminCss();

        $this->assertMatchesRegularExpression(
            '/\.schemaOrgData-admin\s+details\s+summary\s*\{[^}]*align-items:\s*center;/',
            $css,
            'CSS-Regel fuer die vertikale Marker-Ausrichtung von <summary> nicht gefunden'
        );
    }

    /***************************************************************
    *
    * Regressionstest 8.14 (Playwright-Testlauf 2): Freitext-Textareas
    * (z. B. description) stecken in keinem umschließenden Element mit
    * eigenem Padding, das Erweiterungsfeld-Textarea aber in einem
    * schemaOrgData-fieldset (Padding + Rahmen). Die bloße gemeinsame
    * CSS-Klasse "schemaOrgData-wide-textarea" (bereits durch
    * testRenderTextareaWidgetUndExtensionFieldTeilenSichBreitenKlasse()
    * in FormRendererComponentTest.php abgedeckt) reicht daher NICHT als
    * Breitengleichheits-Nachweis - "width: 100%" ergibt für das
    * Erweiterungsfeld einen kleineren absoluten Wert, weil der
    * verfügbare Innenraum des Fieldsets bereits um dessen Padding/Rahmen
    * verengt ist. Dieser Test koppelt algebraisch die tatsächlichen
    * Fieldset-Werte (Padding, Rahmenbreite) an die kompensierenden
    * negativen Margins/die width-calc() des Erweiterungsfeld-Textarea -
    * ändert sich künftig eines der beiden CSS-Regelsets ohne das andere
    * nachzuziehen, schlägt dieser Test fehl (anders als ein reiner
    * Klassen-Vorhandensein-Check).
    *
    ***************************************************************/
    function testExtensionFieldTextareaKompensiertFieldsetPaddingUndRahmenFuerGleicheBreite(): void {
        $css = $this->renderer()->getAdminCss();

        $this->assertMatchesRegularExpression(
            '/\.schemaOrgData-fieldset\s*\{[^}]*padding:\s*([0-9.]+)(em|px);/',
            $css,
            'Fieldset-Padding-Deklaration nicht gefunden - Testannahme ungültig geworden'
        );
        preg_match('/\.schemaOrgData-fieldset\s*\{[^}]*padding:\s*([0-9.]+)(em|px);/', $css, $paddingMatch);
        $paddingNumber = (float) $paddingMatch[1];
        $paddingUnit = $paddingMatch[2];
        $padding = $paddingMatch[1].$paddingUnit;
        $doubledPadding = self::formatCssNumber($paddingNumber * 2).$paddingUnit;

        $this->assertMatchesRegularExpression(
            '/\.schemaOrgData-fieldset\s*\{[^}]*border:\s*([0-9.]+)px\s+solid/',
            $css,
            'Fieldset-Rahmenbreiten-Deklaration nicht gefunden - Testannahme ungültig geworden'
        );
        preg_match('/\.schemaOrgData-fieldset\s*\{[^}]*border:\s*([0-9.]+)px\s+solid/', $css, $borderMatch);
        $borderNumber = (float) $borderMatch[1];
        $borderWidth = $borderMatch[1].'px';
        $doubledBorderWidth = self::formatCssNumber($borderNumber * 2).'px';

        $this->assertStringContainsString(
            '.schemaOrgData-extension-field { font-family: monospace; resize: vertical; '
            .'width: calc(100% + '.$doubledPadding.' + '.$doubledBorderWidth.'); '
            .'margin-left: calc(-'.$padding.' - '.$borderWidth.'); '
            .'margin-right: calc(-'.$padding.' - '.$borderWidth.'); }',
            $css
        );
    }

    /***************************************************************
    *
    * Formatiert eine CSS-Zahl ohne unnötige Nachkommastellen (z. B.
    * 2.0 -> "2" statt "2.0"), analog dazu, wie CSS-Werte üblicherweise
    * von Hand geschrieben werden - siehe
    * testExtensionFieldTextareaKompensiertFieldsetPaddingUndRahmenFuerGleicheBreite().
    *
    ***************************************************************/
    private static function formatCssNumber(float $value): string {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    // -----------------------------------------------------------
    // renderInfoBlock()
    // -----------------------------------------------------------

    function testRenderInfoBlockGlobalEnthaeltTemplateHinweis(): void {
        $html = $this->renderer()->renderInfoBlock('global', $this->adminLang());

        $this->assertStringContainsString('schemaOrgData-info', $html);
    }

    function testRenderInfoBlockUngueltigerScopeLiefertLeerenString(): void {
        $this->assertSame('', $this->renderer()->renderInfoBlock('nicht-existent', $this->adminLang()));
    }

    /***************************************************************
    *
    * UX-Dreier: der allgemeine Absatz
    * (info_text_general) steckt hinter einem <details>-Element, der
    * scope-spezifische Absatz bleibt außerhalb sofort sichtbar.
    *
    ***************************************************************/
    function testRenderInfoBlockEnthaeltDetailsMitAllgemeinemAbsatz(): void {
        $html = $this->renderer()->renderInfoBlock('category', $this->adminLang());

        $this->assertStringContainsString('<details>', $html);
        $lang = $this->adminLang();
        $this->assertStringContainsString($lang->getLanguageHtml('label_info_more_details'), $html);
        $this->assertStringContainsString($lang->getLanguageHtml('info_text_general'), $html);

        $detailsPos = strpos($html, '<details>');
        $generalPos = strpos($html, $lang->getLanguageHtml('info_text_general'));
        $categoryPos = strpos($html, $lang->getLanguageHtml('info_text_category'));

        $this->assertLessThan($detailsPos, $categoryPos);
        $this->assertGreaterThan($detailsPos, $generalPos);
    }

    function testRenderInfoBlockGlobalTemplateHinweisSteckInDetails(): void {
        $lang = $this->adminLang();
        $html = $this->renderer()->renderInfoBlock('global', $lang);

        $detailsPos = strpos($html, '<details>');
        $templatePos = strpos($html, $lang->getLanguageHtml('info_text_template_global'));

        $this->assertGreaterThan($detailsPos, $templatePos);
    }

    // -----------------------------------------------------------
    // buildScopeLabel()
    // -----------------------------------------------------------

    function testBuildScopeLabelGlobal(): void {
        $label = $this->renderer()->buildScopeLabel('global', null, null, $this->adminLang());

        $this->assertNotSame('', $label);
    }

    function testBuildScopeLabelCategoryEnthaeltKategorieBezeichner(): void {
        $label = $this->renderer()->buildScopeLabel('category', 'ueber-uns', null, $this->adminLang());

        $this->assertStringContainsString('ueber-uns', $label);
    }

    function testBuildScopeLabelPageEnthaeltSeitenBezeichner(): void {
        $label = $this->renderer()->buildScopeLabel('page', 'ueber-uns', 'kontakt', $this->adminLang());

        $this->assertStringContainsString('kontakt', $label);
    }

    // -----------------------------------------------------------
    // buildSaveButtonLabel()
    // -----------------------------------------------------------

    function testBuildSaveButtonLabelGlobalOhneKategorie(): void {
        $label = $this->renderer()->buildSaveButtonLabel(null, null, $this->adminLang());

        $this->assertNotSame('', $label);
    }

    function testBuildSaveButtonLabelCategoryEnthaeltKategorieBezeichner(): void {
        $label = $this->renderer()->buildSaveButtonLabel('impressum', null, $this->adminLang());

        $this->assertStringContainsString('impressum', $label);
    }

    function testBuildSaveButtonLabelPageEnthaeltSeitenBezeichner(): void {
        $label = $this->renderer()->buildSaveButtonLabel('impressum', 'kontakt', $this->adminLang());

        $this->assertStringContainsString('kontakt', $label);
    }

    /***************************************************************
    *
    * buildSaveButtonLabel() liefert über getLanguageHtml() bereits
    * einfach HTML-escapten Text (Umlaute als "&auml;"-Entity-Syntax) -
    * das Ergebnis ist bereits attributsicher und darf vom Aufrufer
    * kein zweites Mal mit htmlspecialchars() escaped werden, sonst
    * entsteht "&amp;auml;" (doppelt kodiert).
    *
    ***************************************************************/
    function testBuildSaveButtonLabelPageMitUmlautIstEinfachKodiert(): void {
        $label = $this->renderer()->buildSaveButtonLabel('impressum', rawurlencode('Steuererklärung'), $this->adminLang());

        $this->assertStringContainsString('Steuererkl&auml;rung', $label);
        $this->assertStringNotContainsString('&amp;auml;', $label);
    }

    // -----------------------------------------------------------
    // renderSaveResultNotice()
    // -----------------------------------------------------------

    function testRenderSaveResultNoticeErfolg(): void {
        $html = $this->renderer()->renderSaveResultNotice(['success' => true, 'errors' => []], $this->adminLang());

        $this->assertStringContainsString('schemaOrgData-notice--success', $html);
    }

    function testRenderSaveResultNoticeFehlerListetFehlerAuf(): void {
        $html = $this->renderer()->renderSaveResultNotice(
            ['success' => false, 'errors' => ['Feld X ist ungültig']], $this->adminLang()
        );

        $this->assertStringContainsString('schemaOrgData-notice--error', $html);
        $this->assertStringContainsString('Feld X ist ungültig', $html);
    }

    /***************************************************************
    *
    * Bug 2 (0.3.6-beta): Pflichtfeld-Fehlermeldungen mit Sonderzeichen
    * im Label (FAQPage-Label "Fragen & Antworten", siehe
    * admin_language_deDE.txt: label_faq_entries) dürfen nicht doppelt
    * HTML-kodiert werden. renderSaveResultNotice() kodiert die Meldung
    * einmal via htmlspecialchars(); der Label-Wert selbst muss daher
    * unkodiert aus der Sprachdatei kommen, sodass exakt "&amp;" (statt
    * "&amp;amp;") im gerenderten Hinweisblock erscheint. Hier über den
    * echten Pfad (saveConfig() mit leerem mainEntity) statt mit einer
    * synthetischen Fehlerstring-Eingabe geprüft.
    *
    ***************************************************************/
    function testRequiredFieldErrorWithAmpersandLabelIsSingleEncoded(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'faq';
        $_POST['schemaOrgData_page'] = 'allgemein';

        $postData = $this->validFaqPageData();
        $postData['data']['mainEntity'] = [];

        $result = $this->callSaveConfig('page', $postData, $settings);
        $this->assertFalse($result['success']);

        $html = $this->renderer()->renderSaveResultNotice($result, $this->adminLang());

        $this->assertStringContainsString('Fragen &amp; Antworten', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    // -----------------------------------------------------------
    // renderExistingJsonLdNotice()
    // -----------------------------------------------------------

    private function seedBlocks($settings, string $scope, array $blocks, ?string $cat = null, ?string $page = null): void {
        $this->scopeResolver()->saveScopeMeta($settings, $scope, [
            'existing_jsonld' => true,
            'jsonld_mode' => 'keep',
            'existing_jsonld_content' => implode("\n\n", $blocks),
            'existing_jsonld_blocks' => $blocks,
        ], $cat, $page);
    }

    function testRenderExistingJsonLdNoticeLeerOhneVorhandenesJsonLd(): void {
        $settings = new \InMemorySettings();

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame('', $html);
    }

    function testRenderExistingJsonLdNoticeMitVorhandenemJsonLd(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('schemaOrgData-jsonld-notice', $html);
        $this->assertStringContainsString('name="schemaOrgData_import_action" value="global:0"', $html);
    }

    /***************************************************************
    *
    * Redisplay nach einem Validierungsfehler: die persistierte Meta
    * trägt noch den alten Modus, die gerade getroffene Auswahl liegt
    * nur im POST.
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeUebernimmtWhitelistetenPostModusVorDerMeta(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);
        $_POST['schemaOrgData_jsonld_mode_global'] = 'override';

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('value="override" checked="checked"', $html);
        $this->assertStringNotContainsString('value="keep" checked="checked"', $html);
    }

    function testRenderExistingJsonLdNoticeFolgtDerMetaOhnePostModus(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('value="keep" checked="checked"', $html);
        $this->assertStringNotContainsString('value="override" checked="checked"', $html);
    }

    function testRenderExistingJsonLdNoticeIgnoriertNichtWhitelistetenPostModus(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);
        $_POST['schemaOrgData_jsonld_mode_global'] = 'manipuliert';

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('value="keep" checked="checked"', $html);
        $this->assertStringNotContainsString('value="override" checked="checked"', $html);
    }

    /***************************************************************
    *
    * Kein automatischer Import-Client-Roundtrip mehr: kein Textarea
    * und kein POST-Feld schemaOrgData_import_{scope} im Output. Der
    * Import ist ein normaler Submit (schemaOrgData_import_action).
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeEnthaeltKeinTextareaUndKeinScopeSpezifischesPostFeld(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('name="schemaOrgData_import_global"', $html);
    }

    /***************************************************************
    *
    * UX-Dreier: Keep-Konsequenz-
    * Hinweis, scope-abhängiger Titel, <details>-Wrapper um den
    * Import-Bereich.
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeEnthaeltKeepKonsequenzHinweis(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''],
        ]);

        $lang = $this->adminLang();
        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $lang, $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString($lang->getLanguageHtml('notice_keep_consequence_hint'), $html);
    }

    function testRenderExistingJsonLdNoticeGlobalScopeZeigtTemplateTitel(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''],
        ]);

        $lang = $this->adminLang();
        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $lang, $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString($lang->getLanguageHtml('notice_existing_jsonld_title_global'), $html);
        $this->assertStringNotContainsString($lang->getLanguageHtml('notice_existing_jsonld_title'), $html);
    }

    function testRenderExistingJsonLdNoticeCategoryScopeZeigtStandardTitel(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_cat_ueber-uns', [
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''],
        ]);

        $lang = $this->adminLang();
        $html = $this->renderer()->renderExistingJsonLdNotice(
            'category', 'ueber-uns', null, $lang, $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString($lang->getLanguageHtml('notice_existing_jsonld_title'), $html);
    }

    function testRenderExistingJsonLdNoticeDetailsOffenBeiVorhandenenBloecken(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('<details open="open">', $html);
    }

    /***************************************************************
    *
    * blocks === [] (existing_jsonld gesetzt, aber kein erkannter
    * Block, z. B. Category-Scope mit Flag ohne Content): kein
    * Import-<details> mehr - nur die Radio-Gruppe.
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeKeinDetailsOhneBloecke(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', [
            '_meta' => ['existing_jsonld' => true, 'jsonld_mode' => 'keep', 'existing_jsonld_content' => ''],
        ]);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringNotContainsString('<details', $html);
    }

    function testRenderExistingJsonLdNoticeEinBlockGenauEinSubmitOhneBlockueberschrift(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame(1, substr_count($html, 'name="schemaOrgData_import_action"'));
        $this->assertStringContainsString('value="global:0"', $html);
        $this->assertStringNotContainsString('schemaOrgData-jsonld-block-heading', $html);
    }

    function testRenderExistingJsonLdNoticeZweiBloeckeZweiSubmitsUndUeberschriften(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', [
            '{"@type":"LocalBusiness"}',
            '{"@context":"https://schema.org","@type":"AccountingService"}',
        ]);

        $lang = $this->adminLang();
        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $lang, $this->scopeResolver(), $settings
        );

        $this->assertSame(2, substr_count($html, 'name="schemaOrgData_import_action"'));
        $this->assertStringContainsString('value="global:0"', $html);
        $this->assertStringContainsString('value="global:1"', $html);
        $this->assertStringContainsString($lang->getLanguageHtml('label_detected_block').' 1/2', $html);
        $this->assertStringContainsString($lang->getLanguageHtml('label_detected_block').' 2/2', $html);
    }

    /***************************************************************
    *
    * Kein Inline-<script> mehr je Block - die Dialog-Verdrahtung
    * übernimmt initPreviewDialogs() (validator.js) über data-dialog/
    * data-dialog-close.
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeEnthaeltKeinInlineScriptMehr(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('data-dialog="schemaOrgData-preview-dialog-global-0"', $html);
        $this->assertStringContainsString('data-dialog-close="schemaOrgData-preview-dialog-global-0"', $html);
    }

    /***************************************************************
    *
    * Erzeugt ein Plugin mit isoliertem InMemorySettings-Stub als
    * $this->settings (siehe JsonLdOutputTest::createPlugin()).
    *
    * @return array{0: \schemaOrgData, 1: \InMemorySettings}
    *
    ***************************************************************/
    private function pluginWithInMemorySettings(): array {
        $plugin = new \schemaOrgData();
        $settings = new \InMemorySettings();

        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $settings);

        return [$plugin, $settings];
    }

    function testDebugOutputCheckboxIsUncheckedWhenDisabled(): void {
        $html = $this->renderer()->renderExcludedCatsField([], false, $this->adminLang(), $this->scopeResolver());

        $this->assertMatchesRegularExpression(
            '/name="schemaOrgData\[global\]\[debug_output\]"[^>]*>/',
            $html
        );
        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    function testRenderExistingJsonLdNoticeAbwesendWennKeineBloeckeUndKeinContent(): void {
        [$plugin, $settings] = $this->pluginWithInMemorySettings();

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), new \SchemaOrgData_ScopeResolver(), $settings
        );

        $this->assertSame('', $html);
    }

    /***************************************************************
    *
    * Import-Vorschau: die Vorschau des
    * erkannten Blocks ist ein read-only <pre>.
    *
    ***************************************************************/
    function testExistingJsonLdVorschauIstReadOnlyPre(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness","name":"Müller & Söhne"}']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('class="schemaOrgData-jsonld-preview"', $html);
        $this->assertStringContainsString('<pre class="schemaOrgData-jsonld-preview">', $html);
        $this->assertStringContainsString('Müller &amp; Söhne', $html);
        $this->assertStringNotContainsString('<textarea', $html);
    }

    function testExistingJsonLdVorschauEnthaeltVollansichtDialogMitScopeUndBlockindexEindeutigerId(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'category', ['{"@type":"LocalBusiness"}'], 'ueber-uns');

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'category', 'ueber-uns', null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('data-dialog="schemaOrgData-preview-dialog-category-0"', $html);
        $this->assertStringContainsString('id="schemaOrgData-preview-dialog-category-0"', $html);
        $this->assertStringContainsString('class="schemaOrgData-jsonld-preview-dialog"', $html);
        $this->assertStringContainsString('schemaOrgData-jsonld-preview-full', $html);
    }

    function testExistingJsonLdVorschauWirdBeiUngueltigemJsonAlsRohtextAngezeigt(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"']);

        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('{&quot;@type&quot;:&quot;LocalBusiness&quot;', $html);
    }

    /***************************************************************
    *
    * Import-Verdrahtung: echter Submit-Button je Block, kein
    * data-Attribut-Übernehmen-Pfad mehr.
    *
    ***************************************************************/
    function testRenderExistingJsonLdNoticeEnthaeltImportButton(): void {
        $settings = new \InMemorySettings();
        $this->seedBlocks($settings, 'global', ['{"@type":"LocalBusiness"}']);

        $lang = $this->adminLang();
        $html = $this->renderer()->renderExistingJsonLdNotice(
            'global', null, null, $lang, $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString($lang->getLanguageHtml('button_use_detected_jsonld'), $html);
        $this->assertStringContainsString('name="schemaOrgData_import_action"', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    // -----------------------------------------------------------
    // renderCollisionNotice()
    // -----------------------------------------------------------

    function testRenderCollisionNoticeLeerOhneKollision(): void {
        $settings = new \InMemorySettings();

        $html = $this->renderer()->renderCollisionNotice(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertSame('', $html);
    }

    function testRenderCollisionNoticeMitKollisionAufGlobalerEbene(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Global GmbH']]);

        $html = $this->renderer()->renderCollisionNotice(
            'category', 'ueber-uns', null, 'LocalBusiness', $this->adminLang(), $this->scopeResolver(), $settings
        );

        $this->assertStringContainsString('schemaOrgData-notice--info', $html);
    }

    // -----------------------------------------------------------
    // renderExcludedCatsField()
    // -----------------------------------------------------------

    function testRenderExcludedCatsFieldOhneCatPageNurDebugCheckbox(): void {
        $html = $this->renderer()->renderExcludedCatsField([], false, $this->adminLang(), $this->scopeResolver());

        $this->assertStringNotContainsString('schemaOrgData-checkbox--all', $html);
        $this->assertStringContainsString('schemaOrgData_global_debug_output', $html);
    }

    function testRenderExcludedCatsFieldDebugCheckboxGesetztWennAktiv(): void {
        $html = $this->renderer()->renderExcludedCatsField([], true, $this->adminLang(), $this->scopeResolver());

        $this->assertStringContainsString('schemaOrgData_global_debug_output" name="schemaOrgData[global][debug_output]" value="1" checked="checked"', $html);
    }

    /***************************************************************
    *
    * get_CatArray(true) liefert auch das Wurzelverzeichnis "kategorien"
    * selbst als Eintrag zurück - das ist keine echte Kategorie und
    * darf in der Ausschlussliste nicht als Checkbox erscheinen (nur
    * echte Kategorien + "Alle Kategorien"-Toggle). Nutzt FakeCatPage
    * aus tests/Fixtures/FakeCatPage.php.
    *
    ***************************************************************/
    function testRenderExcludedCatsFieldOmitsKategorienRootEntryDirekt(): void {
        global $CatPage;
        $CatPage = new FakeCatPage(['kategorien', 'ueber-uns', 'impressum']);

        $html = $this->renderer()->renderExcludedCatsField([], false, $this->adminLang(), $this->scopeResolver());

        // unset($CatPage) würde nur die lokale global-Bindung lösen, nicht
        // den Eintrag in $GLOBALS selbst - echtes Aufräumen erfordert
        // unset($GLOBALS['CatPage']), sonst leakt die FakeCatPage-Instanz in
        // spätere Tests derselben Klasse (z. B. testRenderScopeSelectorOhneCatPageLiefertLeerenString).
        unset($GLOBALS['CatPage']);

        $this->assertStringContainsString('value="ueber-uns"', $html);
        $this->assertStringContainsString('value="impressum"', $html);
        $this->assertStringNotContainsString('value="kategorien"', $html);
        $this->assertStringContainsString('data-select-all="schemaOrgData[global][excluded_cats][]"', $html);
    }

    /***************************************************************
    *
    * Gespeichert wird die sanitierte Form des Bezeichners, verglichen
    * wird gegen den rohen Bezeichner aus get_CatArray(). Der Punkt ist
    * das einzige Zeichen, das der moziloCMS-Bezeichner unkodiert trägt
    * und sanitizeScopeIdentifier() entfernt - ohne beidseitige
    * Sanitisierung bliebe die Checkbox einer Kategorie mit Punkt im
    * Namen ungehakt und die Ausschlussregel würde beim nächsten
    * Speichern still gelöscht.
    *
    ***************************************************************/
    function testRenderExcludedCatsFieldCheckboxGehaktBeiPunktImBezeichner(): void {
        global $CatPage;
        $CatPage = new FakeCatPage(['z.B.%20Aktuelles']);

        $html = $this->renderer()->renderExcludedCatsField(['zB%20Aktuelles'], false, $this->adminLang(), $this->scopeResolver());

        unset($GLOBALS['CatPage']);

        $this->assertStringContainsString('value="z.B.%20Aktuelles" checked="checked"', $html);
    }

    function testRenderExcludedCatsFieldCheckboxUngehaktWennNichtInAusschlussliste(): void {
        global $CatPage;
        $CatPage = new FakeCatPage(['z.B.%20Aktuelles', 'impressum']);

        $html = $this->renderer()->renderExcludedCatsField(['impressum'], false, $this->adminLang(), $this->scopeResolver());

        unset($GLOBALS['CatPage']);

        $this->assertStringContainsString('value="z.B.%20Aktuelles" />', $html);
        $this->assertStringContainsString('value="impressum" checked="checked"', $html);
    }

    /***************************************************************
    *
    * Prüft in einem Aufruf sowohl das Vorhandensein der Checkbox als
    * auch Label und Hinweistext.
    *
    ***************************************************************/
    function testRenderExcludedCatsFieldDebugCheckboxImmerMitLabelUndHintDirekt(): void {
        $html = $this->renderer()->renderExcludedCatsField([], false, $this->adminLang(), $this->scopeResolver());

        $this->assertStringContainsString('name="schemaOrgData[global][debug_output]"', $html);
        $this->assertStringContainsString('Debug', $html);
        $this->assertStringContainsString('validator.schema.org', $html);
    }

    // -----------------------------------------------------------
    // renderPlaceholderMissingNotice()
    // -----------------------------------------------------------

    function testRenderPlaceholderMissingNoticeLeerWennGefunden(): void {
        $html = $this->renderer()->renderPlaceholderMissingNotice(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OK, 'schemaOrgData', $this->adminLang()
        );

        $this->assertSame('', $html);
    }

    function testRenderPlaceholderMissingNoticeEnthaeltPluginNamenWennFehlend(): void {
        $html = $this->renderer()->renderPlaceholderMissingNotice(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_MISSING, 'schemaOrgData', $this->adminLang()
        );

        $this->assertStringContainsString('schemaOrgData-placeholder-notice', $html);
        $this->assertStringContainsString('schemaOrgData', $html);
    }

    /***************************************************************
    *
    * Positions-Hinweis (Platzhalter gefunden, aber hinter </head>):
    * bewusst zurückhaltend als Info-Hinweis formuliert, nicht als
    * Fehlermeldung (schemaOrgData-notice--info statt
    * schemaOrgData-placeholder-notice).
    *
    ***************************************************************/
    function testRenderPlaceholderMissingNoticeZeigtZurueckhaltendenHinweisBeiOutsideHead(): void {
        $html = $this->renderer()->renderPlaceholderMissingNotice(
            \SchemaOrgData_CollisionDetector::PLACEHOLDER_OUTSIDE_HEAD, 'schemaOrgData', $this->adminLang()
        );

        $this->assertStringContainsString('schemaOrgData-notice--info', $html);
        $this->assertStringNotContainsString('schemaOrgData-placeholder-notice', $html);
        $this->assertStringContainsString('schemaOrgData', $html);
    }

    // -----------------------------------------------------------
    // renderScopeSelector()
    // -----------------------------------------------------------

    function testRenderScopeSelectorOhneCatPageLiefertLeerenString(): void {
        $this->assertSame('', $this->renderer()->renderScopeSelector(null, null, $this->adminLang()));
    }

    // -----------------------------------------------------------
    // renderTypeSelector()
    // -----------------------------------------------------------

    function testRenderTypeSelectorEnthaeltKeinSchemaOptionUndVerfuegbareTypes(): void {
        $schema = $this->localBusinessSchema();

        $html = $this->renderer()->renderTypeSelector('global', ['LocalBusiness' => $schema], 'LocalBusiness', null, $this->adminLang());

        $this->assertStringContainsString('<option value="">', $html);
        $this->assertMatchesRegularExpression('/<option value="LocalBusiness" selected="selected">/', $html);
    }
}
