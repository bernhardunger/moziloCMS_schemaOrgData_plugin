<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für den Schema-Type ProfilePage (Seite): Schema-Struktur,
* mainEntity als reine Personen-Referenz (id_reference_or_literal
* mit ui:referenceTargets: ["persons"], ui:allowLiteral: false),
* Datumsfelder dateCreated/dateModified sowie Speichern
* (saveConfig()) und JSON-LD-Ausgabe (buildJsonLdScript()).
*
* Abgedeckte Bereiche:
*   - ProfilePage.json: Struktur, ui:scopes (nur "page"), required
*   - mainEntity: ui:widget/ui:required/ui:referenceTargets/ui:allowLiteral
*   - dateCreated/dateModified: format date-time, optional, deutsches
*     Eingabeformat (analog JobPosting.datePosted/validThrough)
*   - renderField(): rendert ausschließlich Personen-Optionen, kein
*     Modus-Umschalter, kein Organisation-Ziel
*   - validateFormData(): Pflichtfeld-Fehler bei fehlender mainEntity,
*     Ablehnung von Organisation-Referenz/Literal-Modus
*   - saveConfig(): Round-Trip inkl. Datumsnormalisierung, Ablehnung
*     bei leerer mainEntity
*   - buildJsonLdScript(): mainEntity als {"@id": "...#person-{slug}"}
*
***************************************************************/
final class ProfilePageTest extends TestCase {

    private array $serverBackup = [];

    protected function setUp(): void {
        $_POST = [];
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void {
        $_POST = [];
        $_SERVER = $this->serverBackup;
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

    private function adminPageRenderer(): \SchemaOrgData_AdminPageRenderer {
        return new \SchemaOrgData_AdminPageRenderer();
    }

    private function configSaveService(): \SchemaOrgData_ConfigSaveService {
        return new \SchemaOrgData_ConfigSaveService();
    }

    private function formRenderer(): \SchemaOrgData_FormRenderer {
        return new \SchemaOrgData_FormRenderer();
    }

    private function profilePageSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'ProfilePage');
    }

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->configSaveService()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->adminPageRenderer(), new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_OrgRelationsService()
        );
    }

    private function buildDecoded(string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $script = (new \SchemaOrgData_JsonLdBuilder())->buildJsonLdScript(
            $this->schemaRepository(), new \SchemaOrgData_UrlHelper(),
            $this->pluginSelfDir(), $type, $data, $nodeId, $suppressedIdTargets
        );
        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'buildJsonLdScript muss valides JSON erzeugen');
        return $decoded;
    }

    private function setActivePerson(\InMemorySettings $settings, string $slug, array $overrides = []): void {
        $registry = $settings->keyExists(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            ? $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            : [];
        $registry[$slug] = array_merge([
            'name'            => 'Anna Muster',
            'honorificPrefix' => '',
            'jobTitle'        => '',
            'description'     => '',
            'url'             => '',
            'sameAs'          => [],
            'knowsAbout'      => [],
            'image'           => '',
            'status'          => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE,
            'sortOrder'       => 100,
        ], $overrides);
        $settings->set(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY, $registry);
    }

    // -----------------------------------------------------------
    // ProfilePage.json: Struktur und Metadaten
    // -----------------------------------------------------------

    function testProfilePageSchemaLoadsCorrectly(): void {
        $schema = $this->profilePageSchema();

        $this->assertIsArray($schema, 'ProfilePage.json muss ladbar sein');
        $this->assertSame('ProfilePage', $schema['title']);
        $this->assertArrayHasKey('mainEntity', $schema['properties']);
        $this->assertArrayHasKey('dateCreated', $schema['properties']);
        $this->assertArrayHasKey('dateModified', $schema['properties']);
    }

    function testProfilePageIsScopedToPageOnly(): void {
        $schema = $this->profilePageSchema();

        $this->assertSame(['page'], $schema['ui:scopes'],
            'ProfilePage darf laut Requirement (F1) nur im Seiten-Scope angeboten werden');
    }

    function testProfilePageHasNoIdFragment(): void {
        $schema = $this->profilePageSchema();

        $this->assertArrayNotHasKey('ui:idFragment', $schema,
            'ProfilePage traegt kein eigenes @id-Fragment (F9) - Anker bleibt #person-{slug}');
    }

    function testProfilePageRequiredArrayMatchesUiRequired(): void {
        $schema = $this->profilePageSchema();

        $this->assertSame(['mainEntity'], $schema['required']);
    }

    function testMainEntityIsRestrictedToPersonReferenceWithoutLiteral(): void {
        $mainEntity = $this->profilePageSchema()['properties']['mainEntity'];

        $this->assertSame('id_reference_or_literal', $mainEntity['ui:widget']);
        $this->assertTrue((bool) ($mainEntity['ui:required'] ?? false));
        $this->assertSame(['persons'], $mainEntity['ui:referenceTargets']);
        $this->assertFalse((bool) ($mainEntity['ui:allowLiteral'] ?? true));
    }

    function testDateFieldsAreOptionalDateTimeWithLocalizedPlaceholderKey(): void {
        $schema = $this->profilePageSchema();

        foreach(['dateCreated', 'dateModified'] as $name) {
            $field = $schema['properties'][$name];
            $this->assertSame('date-time', $field['format'], $name.' muss format date-time verwenden');
            $this->assertFalse((bool) ($field['ui:required'] ?? false), $name.' ist optional (F4)');
            $this->assertSame('placeholder_date', $field['ui:placeholderKey'] ?? null, $name.' fuehrt den Sprachschluessel statt eines literalen Platzhalters');
            $this->assertArrayNotHasKey('ui:placeholder', $field, $name.' darf keinen literalen Platzhalter mehr fuehren');
        }
    }

    // -----------------------------------------------------------
    // buildValidationAttrs(): kein data-check-past fuer dateCreated/dateModified
    // -----------------------------------------------------------

    function testDateFieldsDoNotGetPastWarningAttribute(): void {
        $schema = $this->profilePageSchema();
        $renderer = $this->formRenderer();

        foreach(['dateCreated', 'dateModified'] as $name) {
            $attrs = $renderer->buildValidationAttrs('page', $name, $schema['properties'][$name], $schema, null, $this->adminLang());
            $this->assertArrayNotHasKey('data-check-past', $attrs,
                $name.' darf laut F4 keine Vergangenheits-Warnung erhalten (nur Event.startDate betrifft das)');
        }
    }

    // -----------------------------------------------------------
    // renderField(): mainEntity zeigt ausschliesslich Personen-Optionen
    // -----------------------------------------------------------

    function testRenderFieldRendersOnlyPersonOptionsWithoutModeToggle(): void {
        $schema = $this->profilePageSchema();
        $availableFragments = [
            'organization'    => 'Organization — Muster GmbH',
            'person-anna-muster' => 'Anna Muster',
        ];

        $html = $this->formRenderer()->renderField(
            'page', 'mainEntity', $schema['properties']['mainEntity'], [], $schema, [], null, null, null,
            $this->adminLang(), $this->schemaRepository(), new \SchemaOrgData_UrlHelper(), 'deDE',
            $this->openingHoursHelper(), $this->validator(), $this->adminLang(), $availableFragments
        );

        $this->assertStringContainsString('Anna Muster', $html);
        $this->assertStringNotContainsString('Organization — Muster GmbH', $html,
            'Organisation darf laut ui:referenceTargets nicht als Ziel angeboten werden');
        $this->assertStringNotContainsString('schemaOrgData-idrl-radio', $html,
            'ui:allowLiteral: false blendet den Modus-Umschalter vollstaendig aus');
        $this->assertStringNotContainsString('[mainEntity][name]', $html,
            'kein Literal-Feldname im POST-Namensraum, da kein Literal-Modus angeboten wird');
    }

    // -----------------------------------------------------------
    // validateFormData(): Pflichtpruefung und Zieleinschraenkung
    // -----------------------------------------------------------

    function testValidateFormDataRequiresMainEntityWhenMissing(): void {
        $errors = $this->validator()->validateFormData([], $this->profilePageSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertContains(
            $this->adminLang()->getLanguageValue('error_required_field', $this->adminLang()->getLanguageValue('label_main_entity')),
            $errors
        );
    }

    function testValidateFormDataAcceptsPersonReferenceForMainEntity(): void {
        $formData = ['mainEntity' => ['_mode' => 'reference', '_fragment' => 'person-anna-muster']];
        $errors = $this->validator()->validateFormData($formData, $this->profilePageSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertSame([], $errors);
    }

    function testValidateFormDataRejectsOrganizationReferenceForMainEntity(): void {
        $formData = ['mainEntity' => ['_mode' => 'reference', '_fragment' => 'organization']];
        $errors = $this->validator()->validateFormData($formData, $this->profilePageSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertContains(
            $this->adminLang()->getLanguageValue('error_id_reflit_restricted', $this->adminLang()->getLanguageValue('label_main_entity')),
            $errors
        );
    }

    function testValidateFormDataRejectsLiteralModeForMainEntity(): void {
        $formData = ['mainEntity' => ['_mode' => 'literal', 'name' => 'Gast-Person']];
        $errors = $this->validator()->validateFormData($formData, $this->profilePageSchema(), [], $this->adminLang(), $this->schemaRepository());

        $this->assertContains(
            $this->adminLang()->getLanguageValue('error_id_reflit_restricted', $this->adminLang()->getLanguageValue('label_main_entity')),
            $errors
        );
    }

    // -----------------------------------------------------------
    // saveConfig(): Round-Trip, Pflichtpruefung, Datumsnormalisierung
    // -----------------------------------------------------------

    function testSaveConfigBlocksEmptyMainEntity(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'ueber-uns';
        $_POST['schemaOrgData_page'] = 'anna-muster';

        $postData = [
            'type' => 'ProfilePage',
            'data' => ['mainEntity' => ['_mode' => 'reference', '_fragment' => '']],
            'extension' => ['ProfilePage' => ''],
        ];

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertContains(
            $this->adminLang()->getLanguageValue('error_required_field', $this->adminLang()->getLanguageValue('label_main_entity')),
            $result['errors']
        );
    }

    function testSaveConfigRoundTripPersistsPersonReferenceAndNormalizesDates(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'anna-muster');
        $_POST['schemaOrgData_cat'] = 'ueber-uns';
        $_POST['schemaOrgData_page'] = 'anna-muster';

        $postData = [
            'type' => 'ProfilePage',
            'data' => [
                'mainEntity'   => ['_mode' => 'reference', '_fragment' => 'person-anna-muster'],
                'dateCreated'  => '01.03.2026',
                'dateModified' => '15.06.2026',
            ],
            'extension' => ['ProfilePage' => ''],
        ];

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $settings->get('config_page_ueber-uns_anna-muster')['ProfilePage'];
        $this->assertSame(['_mode' => 'reference', '_fragment' => 'person-anna-muster'], $config['mainEntity']);
        $this->assertSame('2026-03-01', $config['dateCreated']);
        $this->assertSame('2026-06-15', $config['dateModified']);
    }

    // -----------------------------------------------------------
    // buildJsonLdScript(): mainEntity als id_reference_or_literal-Emission
    // -----------------------------------------------------------

    function testBuildJsonLdScriptEmitsMainEntityAsPersonReference(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $data = [
            'mainEntity'   => ['_mode' => 'reference', '_fragment' => 'person-anna-muster'],
            'dateCreated'  => '2026-03-01',
            'dateModified' => '2026-06-15',
        ];

        $decoded = $this->buildDecoded('ProfilePage', $data);

        $this->assertSame('https://www.example.org/#person-anna-muster', $decoded['mainEntity']['@id']);
        $this->assertSame('2026-03-01', $decoded['dateCreated']);
        $this->assertSame('2026-06-15', $decoded['dateModified']);
        $this->assertArrayNotHasKey('@id', $decoded, 'ProfilePage traegt selbst kein @id-Fragment (F9)');
    }

    // -----------------------------------------------------------
    // applyDanglingReferenceGuard(): F3 (Referenz-Emission), F5
    // (Vollunterdrueckung bei geloeschter Person)
    // -----------------------------------------------------------

    private function idReferenceService(): \SchemaOrgData_IdReferenceService {
        return new \SchemaOrgData_IdReferenceService();
    }

    function testGuardAddsReferencedPersonToActivePersonSlugsWithoutTouchingProfilePage(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'anna-muster');

        $scopeConfigs = [
            'page' => ['ProfilePage' => [
                'mainEntity' => ['_mode' => 'reference', '_fragment' => 'person-anna-muster'],
            ]],
        ];

        [$result, $suppressed, $activePersonSlugs] = $this->idReferenceService()->applyDanglingReferenceGuard(
            $this->scopeResolver(), $this->schemaRepository(), $settings, $this->pluginSelfDir(),
            $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['anna-muster'], $activePersonSlugs);
        $this->assertSame($scopeConfigs, $result, 'F3: bei existierender Person bleibt der ProfilePage-Knoten unveraendert');
    }

    function testDeletedPersonSuppressesEntireProfilePageBlock(): void {
        $settings = new \InMemorySettings();
        // Keine Registry-Person - Slug existiert nicht (mehr).

        $scopeConfigs = [
            'global' => ['Organization' => ['name' => 'Muster GmbH', 'url' => 'https://www.example.org']],
            'page'   => ['ProfilePage' => [
                'mainEntity' => ['_mode' => 'reference', '_fragment' => 'person-geloescht'],
            ]],
        ];

        [$result, $suppressed, $activePersonSlugs] = $this->idReferenceService()->applyDanglingReferenceGuard(
            $this->scopeResolver(), $this->schemaRepository(), $settings, $this->pluginSelfDir(),
            $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame(['person-geloescht'], $suppressed);
        $this->assertSame([], $activePersonSlugs);
        $this->assertArrayNotHasKey('ProfilePage', $result['page'],
            'F5: eine ProfilePage ohne mainEntity waere ein ungueltiger Torso - der gesamte Knoten muss entfallen, nicht nur die Property');
        $this->assertArrayHasKey('Organization', $result['global'],
            'F5 darf ausschliesslich den betroffenen Knoten entfernen - die uebrige Ausgabe bleibt vollstaendig');
    }

    // -----------------------------------------------------------
    // Gesamtszenario: globale Organisations-Identitaet + ProfilePage +
    // referenzierte Person -> drei Bloecke (Akzeptanzkriterium 1)
    // -----------------------------------------------------------

    function testFullScenarioEmitsThreeBlocksWithMainEntityReferenceAndUnchangedDedup(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'anna-muster', ['name' => 'Anna Muster']);

        $scopeConfigs = [
            'global' => ['Organization' => ['name' => 'Muster GmbH', 'url' => 'https://www.example.org']],
            'page'   => ['ProfilePage' => [
                'mainEntity' => ['_mode' => 'reference', '_fragment' => 'person-anna-muster'],
            ]],
        ];

        [$scopeConfigs, $suppressed, $activePersonSlugs] = $this->idReferenceService()->applyDanglingReferenceGuard(
            $this->scopeResolver(), $this->schemaRepository(), $settings, $this->pluginSelfDir(),
            $scopeConfigs, false, new \SchemaOrgData_PersonsRegistryService()
        );

        $this->assertSame([], $suppressed);
        $this->assertSame(['anna-muster'], $activePersonSlugs);

        // Nachbau der Ausgabeschleife aus SchemaOrgData_FrontendRenderer::renderFrontend()
        // (getContent() ist fuer Seiten-Scope-Types wegen CAT_REQUEST/PAGE_REQUEST=false
        // in tests/bootstrap.php nicht End-zu-Ende testbar, siehe DonateActionTest/EventTest).
        $urlHelper = new \SchemaOrgData_UrlHelper();
        $jsonLdBuilder = new \SchemaOrgData_JsonLdBuilder();
        $assignedFragments = [];
        $blocks = [];

        foreach($scopeConfigs as $config) {
            foreach($config as $type => $data) {
                $nodeId = $jsonLdBuilder->resolveNodeId($this->schemaRepository(), $urlHelper, $this->pluginSelfDir(), $type, $assignedFragments);
                $script = $jsonLdBuilder->buildJsonLdScript($this->schemaRepository(), $urlHelper, $this->pluginSelfDir(), $type, $data, $nodeId, $suppressed);
                preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
                $blocks[] = json_decode($matches[1], true);
            }
        }

        foreach($activePersonSlugs as $slug) {
            $person = (new \SchemaOrgData_PersonsRegistryService())->getPerson($settings, $slug);
            $nodeId = $jsonLdBuilder->resolvePersonNodeId($urlHelper, $slug, $assignedFragments);
            $script = $jsonLdBuilder->buildJsonLdScript(
                $this->schemaRepository(), $urlHelper, $this->pluginSelfDir(), 'Person', ['name' => $person['name'] ?? ''], $nodeId, $suppressed
            );
            preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
            $blocks[] = json_decode($matches[1], true);
        }

        $this->assertCount(3, $blocks, 'globale Organization + ProfilePage + Person = drei Bloecke');
        $byType = [];
        foreach($blocks as $block) {
            $byType[$block['@type']] = $block;
        }

        $this->assertSame('https://www.example.org/#organization', $byType['Organization']['@id']);
        $this->assertArrayNotHasKey('@id', $byType['ProfilePage'], 'ProfilePage traegt kein eigenes @id-Fragment (F9)');
        $this->assertSame('https://www.example.org/#person-anna-muster', $byType['ProfilePage']['mainEntity']['@id']);
        $this->assertSame('https://www.example.org/#person-anna-muster', $byType['Person']['@id']);
        $this->assertSame(['organization', 'person-anna-muster'], $assignedFragments,
            'De-Dup-Guard bleibt unveraendert - jedes Fragment wird trotz ProfilePage in der Ausgabeschleife nur einmal vergeben');
    }
}
