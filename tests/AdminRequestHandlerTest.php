<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_AdminRequestHandler:
* POST-Verarbeitung des Admin-Formulars (handlePostRequest()) - seit
* Fahrplan-Schritt 5 aus SchemaOrgData_AdminController ausgelagert
* (siehe doc/adr_ziel_architektur.md). Echte, zustandslose
* Language-/SchemaOrgData_ScopeResolver-/SchemaOrgData_SchemaRepository-/
* SchemaOrgData_Validator-/SchemaOrgData_OpeningHoursHelper-/
* SchemaOrgData_AdminPageRenderer-/SchemaOrgData_ConfigSaveService-
* Instanzen, $pluginSelfDir zeigt auf die realen Schema-/Sprach-Fixtures
* des Plugins, $settings ist ein isolierter InMemorySettings-Stub.
*
***************************************************************/
final class AdminRequestHandlerTest extends TestCase {

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

    private function adminPageRenderer(): \SchemaOrgData_AdminPageRenderer {
        return new \SchemaOrgData_AdminPageRenderer();
    }

    private function configSaveService(): \SchemaOrgData_ConfigSaveService {
        return new \SchemaOrgData_ConfigSaveService();
    }

    private function adminRequestHandler(): \SchemaOrgData_AdminRequestHandler {
        return new \SchemaOrgData_AdminRequestHandler();
    }

    /***************************************************************
    *
    * Minimale, gültige Formulardaten für den Type "LocalBusiness"
    * (analog AdminControllerTest::validLocalBusinessData()).
    *
    ***************************************************************/
    private function validLocalBusinessData(string $name = 'Muster GmbH'): array {
        return [
            'type' => 'LocalBusiness',
            'data' => [
                'name' => $name,
                'url' => 'https://www.example.com',
                'address' => [
                    'streetAddress' => 'Musterstraße 12',
                    'postalCode' => '12345',
                    'addressLocality' => 'Musterstadt',
                    'addressRegion' => '',
                    'addressCountry' => 'DE',
                ],
                'openingHours' => [
                    'Mo' => ['from' => '09:00', 'to' => '18:00'],
                    'Tu' => ['from' => '', 'to' => ''],
                    'We' => ['from' => '', 'to' => ''],
                    'Th' => ['from' => '', 'to' => ''],
                    'Fr' => ['from' => '', 'to' => ''],
                    'Sa' => ['from' => '', 'to' => ''],
                    'Su' => ['from' => '', 'to' => ''],
                ],
            ],
            'extension' => ['LocalBusiness' => ''],
        ];
    }

    // -----------------------------------------------------------
    // handlePostRequest()
    // -----------------------------------------------------------

    private function callHandlePostRequest($settings): ?array {
        return $this->adminRequestHandler()->handlePostRequest(
            $settings, $this->adminLang(), $this->scopeResolver(), $this->schemaRepository(),
            $this->pluginSelfDir(), $this->validator(), $this->openingHoursHelper(), $this->adminPageRenderer(),
            $this->configSaveService()
        );
    }

    function testHandlePostRequestOhnePostDatenLiefertNull(): void {
        $_POST = [];

        $this->assertNull($this->callHandlePostRequest(new \InMemorySettings()));
    }

    function testHandlePostRequestSpeichertGlobalenScope(): void {
        $settings = new \InMemorySettings();
        $_POST['schemaOrgData'] = ['global' => $this->validLocalBusinessData()];
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertTrue($settings->keyExists('config_global'));
    }

    function testHandlePostRequestLoeschtBeiDeleteFlag(): void {
        $settings = new \InMemorySettings();
        $settings->set('config_global', ['LocalBusiness' => ['name' => 'Muster GmbH']]);
        $_POST['schemaOrgData'] = ['global' => []];
        $_POST['schemaOrgData_delete_global'] = '1';
        $_POST['schemaOrgData_cat'] = '';
        $_POST['schemaOrgData_page'] = '';

        $result = $this->callHandlePostRequest($settings);

        $this->assertTrue($result['success']);
        $this->assertFalse($settings->keyExists('config_global'));
    }
}
