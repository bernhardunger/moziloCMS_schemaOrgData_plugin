<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für den Schema-Type Article: das auf id_reference_or_literal
* umgestellte Feld "author" (Organisation-Referenz, Registry-Person-
* Referenz, Gast-Autor als Literal) sowie das neue Feld "publisher"
* (id_reference auf "organization").
*
* Abgedeckte Bereiche:
*   - Article.json: author als id_reference_or_literal, publisher als
*     id_reference
*   - saveConfig(): Round-Trip aller drei author-Modi
*   - renderField(): Redisplay-Zustand nach saveConfig()-Round-Trip,
*     Lesekompatibilität für einen als reinen String gespeicherten
*     Freitext-Altbestand (author vor dieser Umstellung)
*   - buildJsonLdScript(): author-Emission (Referenz/Literal/Freitext-
*     Altbestand), publisher-Emission
*   - resolveAvailableGlobalFragments(): Registry-Personen stehen als
*     Referenzziel für author zur Verfügung
*   - resolveTypeInheritance(): author als Objekt überschreibt als
*     Ganzes (kein Feld-Merge innerhalb des Objekts)
*
***************************************************************/
final class ArticleTest extends TestCase {

    private array $serverBackup = [];
    private ?string $pluginDir = null;
    private ?\InMemorySettings $settings = null;

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
        $_POST = [];
        if($this->pluginDir !== null) {
            $this->removeDirectory($this->pluginDir);
            $this->pluginDir = null;
        }
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

    private function createPlugin(): \schemaOrgData {
        $this->pluginDir = sys_get_temp_dir().'/schemaOrgData_article_'.uniqid().'/';
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/schemas', $this->pluginDir.'schemas');
        $this->copyDirectory(\BASE_DIR.'plugins/schemaOrgData/sprachen', $this->pluginDir.'sprachen');

        $plugin = new \schemaOrgData();
        $plugin->PLUGIN_SELF_DIR = $this->pluginDir;

        $this->settings = new \InMemorySettings();
        $ref = new \ReflectionProperty(\schemaOrgData::class, 'settings');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $this->settings);

        return $plugin;
    }

    private function adminLang(\schemaOrgData $plugin): \Language {
        return new \Language($plugin->PLUGIN_SELF_DIR.'sprachen/admin_language_deDE.txt');
    }

    private function articleSchema(\schemaOrgData $plugin): array {
        return (new \SchemaOrgData_SchemaRepository())->loadSchema($plugin->PLUGIN_SELF_DIR, 'Article');
    }

    private function callSaveConfig(\schemaOrgData $plugin, string $scope, array $postData): array {
        return (new \SchemaOrgData_ConfigSaveService())->saveConfig(
            $scope, $postData, $this->settings, $this->adminLang($plugin), new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(), $plugin->PLUGIN_SELF_DIR, new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_OrgRelationsService()
        );
    }

    private function buildDecoded(\schemaOrgData $plugin, string $type, array $data, string $nodeId = '', array $suppressedIdTargets = []): array {
        $script = (new \SchemaOrgData_JsonLdBuilder())->buildJsonLdScript(
            new \SchemaOrgData_SchemaRepository(), new \SchemaOrgData_UrlHelper(),
            $plugin->PLUGIN_SELF_DIR, $type, $data, $nodeId, $suppressedIdTargets
        );
        preg_match('#<script type="application/ld\+json">\n(.*)\n</script>#s', $script, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'buildJsonLdScript muss valides JSON erzeugen');
        return $decoded;
    }

    private function validArticleData(): array {
        return [
            'type' => 'Article',
            'data' => [
                'headline' => 'Neuigkeiten aus dem Verein',
                'author' => ['_mode' => 'literal', 'name' => 'Max Mustermann'],
            ],
            'extension' => ['Article' => ''],
        ];
    }

    // -----------------------------------------------------------
    // Article.json: Struktur und Metadaten
    // -----------------------------------------------------------

    function testArticleAuthorIsIdReferenceOrLiteralWidget(): void {
        $plugin = $this->createPlugin();
        $author = $this->articleSchema($plugin)['properties']['author'];

        $this->assertSame('id_reference_or_literal', $author['ui:widget']);
        $this->assertSame(['name'], $author['ui:literalFields']);
        $this->assertSame('Person', $author['ui:literalType']);
        $this->assertFalse((bool) ($author['ui:required'] ?? false));
        $this->assertSame('placeholder_author_name', $author['ui:literalFieldPlaceholders']['name']);
    }

    function testArticlePublisherIsIdReferenceWidget(): void {
        $plugin = $this->createPlugin();
        $publisher = $this->articleSchema($plugin)['properties']['publisher'];

        $this->assertSame('id_reference', $publisher['ui:widget']);
        $this->assertSame('organization', $publisher['ui:idTarget']);
        $this->assertFalse((bool) ($publisher['ui:required'] ?? false));
    }

    function testArticleHeadlineStaysRequired(): void {
        $plugin = $this->createPlugin();
        $schema = $this->articleSchema($plugin);

        $this->assertSame(['headline'], $schema['required'],
            'Die Umstellung von author darf headline als einziges Pflichtfeld nicht verändern');
    }

    // -----------------------------------------------------------
    // saveConfig(): Round-Trip aller drei author-Modi
    // -----------------------------------------------------------

    function testSaveConfigRoundTripMitGastAutorLiteral(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $result = $this->callSaveConfig($plugin, 'page', $this->validArticleData());
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertSame('literal', $config['author']['_mode']);
        $this->assertSame('Max Mustermann', $config['author']['name']);
    }

    function testSaveConfigRoundTripMitAutorReferenzAufOrganisation(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $postData = $this->validArticleData();
        $postData['data']['author'] = ['_mode' => 'reference', '_fragment' => 'organization'];

        $result = $this->callSaveConfig($plugin, 'page', $postData);
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertSame('reference', $config['author']['_mode']);
        $this->assertSame('organization', $config['author']['_fragment']);
    }

    function testSaveConfigRoundTripMitAutorReferenzAufRegistryPerson(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $personsRegistry = new \SchemaOrgData_PersonsRegistryService();
        $personsRegistry->createPerson(
            $this->settings, ['name' => 'Erika Musterfrau', 'slug' => 'erika-musterfrau'],
            $this->adminLang($plugin), new \SchemaOrgData_Validator()
        );

        $postData = $this->validArticleData();
        $postData['data']['author'] = ['_mode' => 'reference', '_fragment' => 'person-erika-musterfrau'];

        $result = $this->callSaveConfig($plugin, 'page', $postData);
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertSame('reference', $config['author']['_mode']);
        $this->assertSame('person-erika-musterfrau', $config['author']['_fragment']);
    }

    function testSaveConfigSucceedsWithoutPublisherInPost(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $result = $this->callSaveConfig($plugin, 'page', $this->validArticleData());
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertArrayNotHasKey('publisher', $config,
            'publisher wird erst zur Ausgabezeit über buildJsonLdScript() emittiert, nicht gespeichert');
    }

    // -----------------------------------------------------------
    // resolveAvailableGlobalFragments(): Registry-Personen als
    // Referenzziel für author verfügbar
    // -----------------------------------------------------------

    function testRegistryPersonErscheintAlsVerfuegbaresReferenzzielFuerAuthor(): void {
        $plugin = $this->createPlugin();
        $personsRegistry = new \SchemaOrgData_PersonsRegistryService();
        $personsRegistry->createPerson(
            $this->settings, ['name' => 'Erika Musterfrau', 'slug' => 'erika-musterfrau'],
            $this->adminLang($plugin), new \SchemaOrgData_Validator()
        );

        $fragments = (new \SchemaOrgData_IdReferenceService())->resolveAvailableGlobalFragments(
            new \SchemaOrgData_ScopeResolver(), new \SchemaOrgData_SchemaRepository(), $this->settings,
            $plugin->PLUGIN_SELF_DIR, $this->adminLang($plugin), $personsRegistry
        );

        $this->assertArrayHasKey('person-erika-musterfrau', $fragments);
    }

    // -----------------------------------------------------------
    // Redisplay nach saveConfig(): id_reference_or_literal-Widget
    // -----------------------------------------------------------

    function testRenderFieldZeigtLiteralRadioNachSaveConfigRoundTripAlsAusgewaehlt(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $result = $this->callSaveConfig($plugin, 'page', $this->validArticleData());
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $schema = $this->articleSchema($plugin);

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'author', $schema['properties']['author'], $config['author'], $schema, $config,
            'page_blog_neuigkeiten_Article', null, null, $this->adminLang($plugin), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
            $this->adminLang($plugin), ['organization' => 'Organization']
        );

        $this->assertMatchesRegularExpression(
            '/value="literal"\s+checked="checked"/', $html,
            'Literal-Radio muss nach dem saveConfig()-Round-Trip als ausgewählt markiert sein'
        );
        $this->assertDoesNotMatchRegularExpression('/value="reference"\s+checked="checked"/', $html);
        $this->assertStringContainsString('value="Max Mustermann"', $html);
    }

    function testRenderFieldZeigtReferenceRadioNachSaveConfigRoundTripAlsAusgewaehlt(): void {
        $plugin = $this->createPlugin();
        $_POST['schemaOrgData_cat'] = 'blog';
        $_POST['schemaOrgData_page'] = 'neuigkeiten';

        $postData = $this->validArticleData();
        $postData['data']['author'] = ['_mode' => 'reference', '_fragment' => 'organization'];

        $result = $this->callSaveConfig($plugin, 'page', $postData);
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $schema = $this->articleSchema($plugin);

        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'author', $schema['properties']['author'], $config['author'], $schema, $config,
            'page_blog_neuigkeiten_Article', null, null, $this->adminLang($plugin), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
            $this->adminLang($plugin), ['organization' => 'Organization']
        );

        $this->assertMatchesRegularExpression(
            '/value="reference"\s+checked="checked"/', $html,
            'Referenz-Radio muss nach dem saveConfig()-Round-Trip als ausgewählt markiert sein'
        );
        $this->assertDoesNotMatchRegularExpression('/value="literal"\s+checked="checked"/', $html);
    }

    /***************************************************************
    *
    * Lesekompatibilität für Freitext-Bestandsdaten (SRD 4.4): ein vor
    * dieser Umstellung als reiner String gespeicherter author-Wert
    * (Settings direkt befüllt, nicht über saveConfig() - simuliert
    * unveränderten Altbestand) wird beim Redisplay transparent als
    * Literal-Modus mit dem ursprünglichen Wert im name-Feld angezeigt,
    * ohne dass die Settings dabei verändert werden.
    *
    ***************************************************************/
    function testRenderFieldZeigtFreitextAltbestandAlsLiteralModusOhneSettingsZuVeraendern(): void {
        $plugin = $this->createPlugin();
        $this->settings->set('config_page_blog_neuigkeiten', [
            'Article' => ['headline' => 'Alter Artikel', 'author' => 'Alter Autor Name'],
        ]);

        $config = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertSame('Alter Autor Name', $config['author'], 'Vorbedingung: author ist ein reiner String');

        $schema = $this->articleSchema($plugin);
        $html = (new \SchemaOrgData_FormRenderer())->renderField(
            'page', 'author', $schema['properties']['author'], $config['author'], $schema, $config,
            'page_blog_neuigkeiten_Article', null, null, $this->adminLang($plugin), new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_UrlHelper(), 'deDE', new \SchemaOrgData_OpeningHoursHelper(), new \SchemaOrgData_Validator(),
            $this->adminLang($plugin), ['organization' => 'Organization']
        );

        $this->assertMatchesRegularExpression(
            '/value="literal"\s+checked="checked"/', $html,
            'Ein Freitext-Altbestand muss als Literal-Modus angezeigt werden'
        );
        $this->assertStringContainsString('value="Alter Autor Name"', $html);

        // Settings dürfen durch das reine Redisplay nicht verändert worden sein.
        $configAfterRender = $this->settings->get('config_page_blog_neuigkeiten')['Article'];
        $this->assertSame('Alter Autor Name', $configAfterRender['author'],
            'Redisplay darf keinen stillen Migrations-Write auslösen');
    }

    // -----------------------------------------------------------
    // buildJsonLdScript(): author-/publisher-Emission
    // -----------------------------------------------------------

    function testBuildJsonLdEmittiertGastAutorAlsEingebettetesPersonObjekt(): void {
        $plugin = $this->createPlugin();
        $data = [
            'headline' => 'Neuigkeiten aus dem Verein',
            'author' => ['_mode' => 'literal', 'name' => 'Max Mustermann'],
        ];

        $decoded = $this->buildDecoded($plugin, 'Article', $data);

        $this->assertSame('Person', $decoded['author']['@type']);
        $this->assertSame('Max Mustermann', $decoded['author']['name']);
        $this->assertArrayNotHasKey('@id', $decoded['author']);
    }

    function testBuildJsonLdEmittiertAutorReferenzAufOrganisationAlsIdVerweis(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $data = [
            'headline' => 'Neuigkeiten aus dem Verein',
            'author' => ['_mode' => 'reference', '_fragment' => 'organization'],
        ];

        $decoded = $this->buildDecoded($plugin, 'Article', $data);

        $this->assertSame('https://www.example.org/#organization', $decoded['author']['@id']);
        $this->assertArrayNotHasKey('name', $decoded['author']);
    }

    function testBuildJsonLdEmittiertAutorReferenzAufRegistryPersonAlsIdVerweis(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $data = [
            'headline' => 'Neuigkeiten aus dem Verein',
            'author' => ['_mode' => 'reference', '_fragment' => 'person-erika-musterfrau'],
        ];

        $decoded = $this->buildDecoded($plugin, 'Article', $data);

        $this->assertSame('https://www.example.org/#person-erika-musterfrau', $decoded['author']['@id']);
    }

    function testBuildJsonLdEmittiertPublisherAlsIdVerweisAufOrganisation(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $plugin = $this->createPlugin();
        $data = ['headline' => 'Neuigkeiten aus dem Verein'];

        $decoded = $this->buildDecoded($plugin, 'Article', $data);

        $this->assertSame('https://www.example.org/#organization', $decoded['publisher']['@id']);
    }

    /***************************************************************
    *
    * Kern der Lesekompatibilität (SRD 4.4): ein reiner Freitext-String
    * (Altbestand vor dieser Umstellung) muss weiterhin als eingebettetes
    * Person-Objekt emittiert werden, ohne dass ein Redakteur das
    * Formular zuvor erneut gespeichert haben muss.
    *
    ***************************************************************/
    function testBuildJsonLdEmittiertFreitextAltbestandAlsEingebettetesPersonObjekt(): void {
        $plugin = $this->createPlugin();
        $data = [
            'headline' => 'Alter Artikel',
            'author' => 'Alter Autor Name',
        ];

        $decoded = $this->buildDecoded($plugin, 'Article', $data);

        $this->assertSame('Person', $decoded['author']['@type']);
        $this->assertSame('Alter Autor Name', $decoded['author']['name']);
        $this->assertArrayNotHasKey('@id', $decoded['author']);
    }

    // -----------------------------------------------------------
    // resolveTypeInheritance(): author als Objekt überschreibt als
    // Ganzes (Kategorie -> Seite)
    // -----------------------------------------------------------

    function testResolveTypeInheritanceAuthorObjektUeberschreibtAlsGanzesAufSeite(): void {
        $scopeConfigs = [
            'category' => [
                'Article' => [
                    'headline' => 'Kategorie-Standardartikel',
                    'author' => ['_mode' => 'literal', 'name' => 'Kategorie-Redaktion'],
                ],
            ],
            'page' => [
                'Article' => [
                    'headline' => 'Seiten-Artikel',
                    'author' => ['_mode' => 'reference', '_fragment' => 'organization'],
                ],
            ],
        ];

        $result = (new \SchemaOrgData_ScopeResolver())->resolveTypeInheritance($scopeConfigs);

        $this->assertArrayNotHasKey('Article', $result['category'] ?? [],
            'Der Type wird nur auf der spezifischsten Ebene (Seite) ausgegeben');
        $this->assertSame(
            ['_mode' => 'reference', '_fragment' => 'organization'],
            $result['page']['Article']['author'],
            'Das Seiten-author-Objekt überschreibt das Kategorie-Objekt vollständig, kein Feld-Merge'
        );
    }

    function testResolveTypeInheritanceSeiteErbtAuthorVonKategorieWennSeiteLeerIst(): void {
        $scopeConfigs = [
            'category' => [
                'Article' => [
                    'headline' => 'Kategorie-Standardartikel',
                    'author' => ['_mode' => 'literal', 'name' => 'Kategorie-Redaktion'],
                ],
            ],
            'page' => [
                'Article' => [
                    'headline' => 'Seiten-Artikel',
                ],
            ],
        ];

        $result = (new \SchemaOrgData_ScopeResolver())->resolveTypeInheritance($scopeConfigs);

        $this->assertSame(
            ['_mode' => 'literal', 'name' => 'Kategorie-Redaktion'],
            $result['page']['Article']['author'],
            'Fehlt author auf der Seite komplett, wird das Kategorie-Objekt vollständig übernommen'
        );
    }
}
