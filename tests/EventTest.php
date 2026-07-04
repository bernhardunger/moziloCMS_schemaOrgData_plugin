<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für den Schema-Type Event (Seite): Schema-Struktur,
* place-Widget (location), id_reference_or_literal-Widget
* (organizer), Speichern (saveConfig()) und JSON-LD-Ausgabe
* (buildJsonLdScript()).
*
* Abgedeckte Bereiche:
*   - Event.json: Struktur, Scopes, required, definitions.PostalAddress
*   - saveConfig(): Round-Trip inkl. location (place-Widget) und
*     organizer (Referenz- und Literal-Modus), Pflichtfeld startDate,
*     endDate-vor-startDate-Fehler
*   - buildJsonLdScript(): location als Place mit verschachtelter
*     PostalAddress, organizer im Referenz- und Literal-Modus
*
***************************************************************/
final class EventTest extends TestCase {

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

    private function eventSchema(): array {
        return $this->schemaRepository()->loadSchema($this->pluginSelfDir(), 'Event');
    }

    private function callSaveConfig(string $scope, array $postData, \InMemorySettings $settings): array {
        return $this->configSaveService()->saveConfig(
            $scope, $postData, $settings, $this->adminLang(), $this->scopeResolver(),
            $this->schemaRepository(), $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(),
            $this->adminPageRenderer()
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

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "Event".
    *
    ***************************************************************/
    private function validEventData(): array {
        return [
            'type' => 'Event',
            'data' => [
                'name' => 'Sommerfest',
                'startDate' => '2026-09-15T19:00:00+02:00',
                'location' => [
                    'name' => 'Stadtpark',
                    'address' => [
                        'streetAddress' => 'Am Stadtpark 1',
                        'postalCode' => '12345',
                        'addressLocality' => 'Musterstadt',
                        'addressRegion' => '',
                        'addressCountry' => 'DE',
                    ],
                ],
                'organizer' => [
                    '_mode' => 'literal',
                    'name' => 'Förderverein Musterstadt e. V.',
                ],
            ],
            'extension' => ['Event' => ''],
        ];
    }

    // -----------------------------------------------------------
    // Event.json: Struktur und Metadaten
    // -----------------------------------------------------------

    function testEventSchemaLoadsCorrectly(): void {
        $schema = $this->eventSchema();

        $this->assertIsArray($schema, 'Event.json muss ladbar sein');
        $this->assertSame('Event', $schema['title']);
        $this->assertSame(['page'], $schema['ui:scopes']);
        $this->assertSame(['name', 'startDate'], $schema['required']);
    }

    function testEventSchemaHasAllExpectedProperties(): void {
        $schema = $this->eventSchema();

        foreach(['name', 'description', 'startDate', 'endDate', 'eventAttendanceMode', 'eventStatus', 'location', 'organizer'] as $property) {
            $this->assertArrayHasKey($property, $schema['properties'], 'Property "'.$property.'" muss im Schema vorhanden sein');
        }
    }

    function testEventStartDateIsRequiredDateTimeField(): void {
        $schema = $this->eventSchema();
        $startDate = $schema['properties']['startDate'];

        $this->assertSame('date-time', $startDate['format']);
        $this->assertTrue((bool) ($startDate['ui:required'] ?? false));
    }

    function testEventEndDateIsOptionalDateTimeField(): void {
        $schema = $this->eventSchema();
        $endDate = $schema['properties']['endDate'];

        $this->assertSame('date-time', $endDate['format']);
        $this->assertFalse((bool) ($endDate['ui:required'] ?? false));
    }

    function testEventLocationIsPlaceWidgetWithNestedPostalAddress(): void {
        $schema = $this->eventSchema();
        $location = $schema['properties']['location'];

        $this->assertSame('place', $location['ui:widget']);
        $this->assertArrayHasKey('name', $location['properties']);
        $this->assertArrayHasKey('address', $location['properties']);

        $addressSchema = $this->schemaRepository()->resolveSchemaRef($location['properties']['address'], $schema);
        $this->assertSame('postal_address', $addressSchema['ui:widget']);
        $this->assertContains('addressLocality', $addressSchema['required']);
        $this->assertContains('addressCountry', $addressSchema['required']);
    }

    function testEventOrganizerIsIdReferenceOrLiteralWidget(): void {
        $schema = $this->eventSchema();
        $organizer = $schema['properties']['organizer'];

        $this->assertSame('id_reference_or_literal', $organizer['ui:widget']);
        $this->assertSame(['name'], $organizer['ui:literalFields']);
        $this->assertSame('Organization', $organizer['ui:literalType']);
        $this->assertFalse((bool) ($organizer['ui:required'] ?? false));
    }

    function testEventAttendanceModeUsesFullSchemaOrgUris(): void {
        $schema = $this->eventSchema();
        $enum = $schema['properties']['eventAttendanceMode']['enum'];

        $this->assertContains('https://schema.org/OfflineEventAttendanceMode', $enum);
        $this->assertContains('https://schema.org/OnlineEventAttendanceMode', $enum);
        $this->assertContains('https://schema.org/MixedEventAttendanceMode', $enum);
    }

    function testEventStatusUsesFullSchemaOrgUris(): void {
        $schema = $this->eventSchema();
        $enum = $schema['properties']['eventStatus']['enum'];

        $this->assertContains('https://schema.org/EventScheduled', $enum);
        $this->assertContains('https://schema.org/EventCancelled', $enum);
    }

    // -----------------------------------------------------------
    // saveConfig(): Round-Trip
    // -----------------------------------------------------------

    function testSaveConfigRoundTripMitLocationUndOrganizerLiteral(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $result = $this->callSaveConfig('page', $this->validEventData(), $settings);
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $settings->get('config_page_veranstaltungen_sommerfest')['Event'];
        $this->assertSame('Sommerfest', $config['name']);
        $this->assertSame('2026-09-15T19:00:00+02:00', $config['startDate']);
        $this->assertSame('Stadtpark', $config['location']['name']);
        $this->assertSame('Musterstadt', $config['location']['address']['addressLocality']);
        $this->assertSame('literal', $config['organizer']['_mode']);
        $this->assertSame('Förderverein Musterstadt e. V.', $config['organizer']['name']);
    }

    function testSaveConfigRoundTripMitOrganizerReferenceModus(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $postData = $this->validEventData();
        $postData['data']['organizer'] = ['_mode' => 'reference', '_fragment' => 'organization'];

        $result = $this->callSaveConfig('page', $postData, $settings);
        $this->assertTrue($result['success'], implode(', ', $result['errors']));

        $config = $settings->get('config_page_veranstaltungen_sommerfest')['Event'];
        $this->assertSame('reference', $config['organizer']['_mode']);
        $this->assertSame('organization', $config['organizer']['_fragment']);
    }

    function testSaveConfigLehntFehlendesStartDateAb(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $postData = $this->validEventData();
        $postData['data']['startDate'] = '';

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    function testSaveConfigLehntEndDateVorStartDateAb(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $postData = $this->validEventData();
        $postData['data']['endDate'] = '2026-09-15T18:00:00+02:00';

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertFalse($result['success']);
        $this->assertContains($this->adminLang()->getLanguageValue('error_date_range_invalid'), $result['errors']);
    }

    function testSaveConfigAkzeptiertEndDateNachStartDate(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData_cat'] = 'veranstaltungen';
        $_POST['schemaOrgData_page'] = 'sommerfest';

        $postData = $this->validEventData();
        $postData['data']['endDate'] = '2026-09-15T22:00:00+02:00';

        $result = $this->callSaveConfig('page', $postData, $settings);

        $this->assertTrue($result['success'], implode(', ', $result['errors']));
        $config = $settings->get('config_page_veranstaltungen_sommerfest')['Event'];
        $this->assertSame('2026-09-15T22:00:00+02:00', $config['endDate']);
    }

    // -----------------------------------------------------------
    // buildJsonLdScript(): location/organizer-Verschachtelung
    // -----------------------------------------------------------

    function testBuildJsonLdScriptNestsLocationAsPlaceWithPostalAddress(): void {
        $decoded = $this->buildDecoded('Event', [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'location' => [
                'name' => 'Stadtpark',
                'address' => ['addressLocality' => 'Musterstadt', 'addressCountry' => 'DE'],
            ],
        ]);

        $this->assertSame('Event', $decoded['@type']);
        $this->assertSame('Place', $decoded['location']['@type']);
        $this->assertSame('PostalAddress', $decoded['location']['address']['@type']);
    }

    function testBuildJsonLdScriptOrganizerLiteralModusEmittiertOrganizationType(): void {
        $decoded = $this->buildDecoded('Event', [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'organizer' => ['_mode' => 'literal', 'name' => 'Förderverein Musterstadt e. V.'],
        ]);

        $this->assertSame('Organization', $decoded['organizer']['@type']);
        $this->assertSame('Förderverein Musterstadt e. V.', $decoded['organizer']['name']);
        $this->assertArrayNotHasKey('@id', $decoded['organizer']);
    }

    function testBuildJsonLdScriptOrganizerReferenceModusEmittiertNurAtId(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $decoded = $this->buildDecoded('Event', [
            'name' => 'Sommerfest',
            'startDate' => '2026-09-15T19:00:00+02:00',
            'organizer' => ['_mode' => 'reference', '_fragment' => 'organization'],
        ]);

        $this->assertSame('https://www.example.org/#organization', $decoded['organizer']['@id']);
        $this->assertArrayNotHasKey('name', $decoded['organizer']);
    }
}
