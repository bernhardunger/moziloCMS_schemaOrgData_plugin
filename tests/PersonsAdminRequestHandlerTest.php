<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_PersonsAdminRequestHandler:
* handlePersonsPostRequest() - Dispatch von "create"/"update:<slug>"/
* "delete:<slug>" an SchemaOrgData_PersonsRegistryService, ungültige
* Aktion, kein "schemaOrgData_persons_action" im POST -> null.
*
***************************************************************/
final class PersonsAdminRequestHandlerTest extends TestCase {

    protected function setUp(): void {
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
    }

    private function handler(): \SchemaOrgData_PersonsAdminRequestHandler {
        return new \SchemaOrgData_PersonsAdminRequestHandler();
    }

    private function registryService(): \SchemaOrgData_PersonsRegistryService {
        return new \SchemaOrgData_PersonsRegistryService();
    }

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function validator(): \SchemaOrgData_Validator {
        return new \SchemaOrgData_Validator();
    }

    function testOhnePersonsActionImPostLiefertNull(): void {
        $_POST = ['schemaOrgData' => ['global' => ['type' => '']]];

        $result = $this->handler()->handlePersonsPostRequest(
            new \InMemorySettings(), $this->adminLang(), $this->registryService(), $this->validator()
        );

        $this->assertNull($result);
    }

    function testCreateActionLegtPersonAnUndLiefertSlug(): void {
        $settings = new \InMemorySettings();
        $_POST = [
            'schemaOrgData_persons_action' => 'create',
            'schemaOrgData_persons_data' => ['name' => 'Max Mustermann', 'slug' => 'max'],
        ];

        $result = $this->handler()->handlePersonsPostRequest($settings, $this->adminLang(), $this->registryService(), $this->validator());

        $this->assertTrue($result['success']);
        $this->assertTrue($result['persons']);
        $this->assertSame('create', $result['action']);
        $this->assertSame('max', $result['slug']);
        $this->assertSame('Max Mustermann', $this->registryService()->getPerson($settings, 'max')['name']);
    }

    function testCreateActionMitFehlendemNamenLiefertFehler(): void {
        $_POST = [
            'schemaOrgData_persons_action' => 'create',
            'schemaOrgData_persons_data' => [],
        ];

        $result = $this->handler()->handlePersonsPostRequest(new \InMemorySettings(), $this->adminLang(), $this->registryService(), $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('create', $result['action']);
        $this->assertNull($result['slug']);
    }

    function testUpdateActionAktualisiertBestehendePerson(): void {
        $settings = new \InMemorySettings();
        $registryService = $this->registryService();
        $registryService->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $this->adminLang(), $this->validator());

        $_POST = [
            'schemaOrgData_persons_action' => 'update:max',
            'schemaOrgData_persons_data' => ['name' => 'Max M. Mustermann'],
        ];

        $result = $this->handler()->handlePersonsPostRequest($settings, $this->adminLang(), $registryService, $this->validator());

        $this->assertTrue($result['success']);
        $this->assertSame('update', $result['action']);
        $this->assertSame('max', $result['slug']);
        $this->assertSame('Max M. Mustermann', $registryService->getPerson($settings, 'max')['name']);
    }

    function testUpdateActionNichtVorhandenerSlugLiefertFehler(): void {
        $_POST = [
            'schemaOrgData_persons_action' => 'update:nicht-vorhanden',
            'schemaOrgData_persons_data' => ['name' => 'X'],
        ];

        $result = $this->handler()->handlePersonsPostRequest(new \InMemorySettings(), $this->adminLang(), $this->registryService(), $this->validator());

        $this->assertFalse($result['success']);
        $this->assertSame('update', $result['action']);
        $this->assertSame('nicht-vorhanden', $result['slug']);
    }

    function testDeleteActionEntferntPerson(): void {
        $settings = new \InMemorySettings();
        $registryService = $this->registryService();
        $registryService->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $this->adminLang(), $this->validator());

        $_POST = ['schemaOrgData_persons_action' => 'delete:max'];

        $result = $this->handler()->handlePersonsPostRequest($settings, $this->adminLang(), $registryService, $this->validator());

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $result['action']);
        $this->assertSame('max', $result['slug']);
        $this->assertFalse($registryService->slugExists($settings, 'max'));
    }

    function testUngueltigeAktionLiefertFehler(): void {
        $_POST = ['schemaOrgData_persons_action' => 'irgendwas'];

        $result = $this->handler()->handlePersonsPostRequest(new \InMemorySettings(), $this->adminLang(), $this->registryService(), $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['action']);
        $this->assertNull($result['slug']);
    }
}
