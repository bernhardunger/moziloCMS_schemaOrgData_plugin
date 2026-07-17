<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_OrgRelationsService
* (Organisations-Relationen founder/employee/member, siehe README.md,
* Abschnitt "@id-Anker und Knotenreferenzen"): roles(), sanitizeAndValidate()
* (Rollen-Whitelist, Slug-Existenzprüfung, Verwurf der leeren Anlege-Zeile)
* und buildOutputGroups() (Gruppierung nach Rolle, Dangling-/Status-Filter).
*
***************************************************************/
final class OrgRelationsServiceTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function setActivePerson(\InMemorySettings $settings, string $slug, array $overrides = []): void {
        $registry = $settings->keyExists(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            ? $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY)
            : [];
        $registry[$slug] = array_merge([
            'name' => 'Max Mustermann',
            'status' => \SchemaOrgData_PersonsRegistryService::STATUS_ACTIVE,
        ], $overrides);
        $settings->set(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY, $registry);
    }

    // -----------------------------------------------------------
    // roles()
    // -----------------------------------------------------------

    function testRolesListsFounderEmployeeMember(): void {
        $this->assertSame(['founder', 'employee', 'member'], \SchemaOrgData_OrgRelationsService::roles());
    }

    // -----------------------------------------------------------
    // sanitizeAndValidate()
    // -----------------------------------------------------------

    function testSanitizeAndValidateAcceptsKnownRoleAndExistingPerson(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');

        $result = (new \SchemaOrgData_OrgRelationsService())->sanitizeAndValidate(
            [['person' => 'max-mustermann', 'role' => 'founder']],
            $settings, new \SchemaOrgData_PersonsRegistryService(), $this->adminLang()
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([['person' => 'max-mustermann', 'role' => 'founder']], $result['relations']);
    }

    function testSanitizeAndValidateSkipsRowWithEmptyPerson(): void {
        $settings = new \InMemorySettings();

        $result = (new \SchemaOrgData_OrgRelationsService())->sanitizeAndValidate(
            [['person' => '', 'role' => 'founder']],
            $settings, new \SchemaOrgData_PersonsRegistryService(), $this->adminLang()
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['relations'],
            'Die stets mitgesendete leere Anlege-Zeile darf keine Relation erzeugen');
    }

    function testSanitizeAndValidateRejectsUnknownRole(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');

        $result = (new \SchemaOrgData_OrgRelationsService())->sanitizeAndValidate(
            [['person' => 'max-mustermann', 'role' => 'ceo']],
            $settings, new \SchemaOrgData_PersonsRegistryService(), $this->adminLang()
        );

        $this->assertNotSame([], $result['errors']);
        $this->assertSame([], $result['relations']);
    }

    function testSanitizeAndValidateRejectsUnknownPersonSlug(): void {
        $settings = new \InMemorySettings();
        // Kein Registry-Eintrag für "geloescht".

        $result = (new \SchemaOrgData_OrgRelationsService())->sanitizeAndValidate(
            [['person' => 'geloescht', 'role' => 'founder']],
            $settings, new \SchemaOrgData_PersonsRegistryService(), $this->adminLang()
        );

        $this->assertNotSame([], $result['errors']);
        $this->assertSame([], $result['relations']);
    }

    function testSanitizeAndValidateProcessesMultipleValidRows(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');
        $this->setActivePerson($settings, 'julia-weber');

        $result = (new \SchemaOrgData_OrgRelationsService())->sanitizeAndValidate(
            [
                ['person' => 'max-mustermann', 'role' => 'founder'],
                ['person' => 'julia-weber', 'role' => 'employee'],
            ],
            $settings, new \SchemaOrgData_PersonsRegistryService(), $this->adminLang()
        );

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['relations']);
    }

    // -----------------------------------------------------------
    // buildOutputGroups()
    // -----------------------------------------------------------

    function testBuildOutputGroupsReturnsEmptyArrayForEmptyRelations(): void {
        $settings = new \InMemorySettings();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [], [], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame([], $result);
    }

    function testBuildOutputGroupsGroupsByRole(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');
        $this->setActivePerson($settings, 'julia-weber');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [
                ['person' => 'max-mustermann', 'role' => 'founder'],
                ['person' => 'julia-weber', 'role' => 'employee'],
            ],
            [], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame(['https://www.example.org/#person-max-mustermann'], array_column($result['founder'], '@id'));
        $this->assertSame(['https://www.example.org/#person-julia-weber'], array_column($result['employee'], '@id'));
    }

    function testBuildOutputGroupsGroupsMultiplePersonsUnderSameRole(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');
        $this->setActivePerson($settings, 'julia-weber');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [
                ['person' => 'max-mustermann', 'role' => 'employee'],
                ['person' => 'julia-weber', 'role' => 'employee'],
            ],
            [], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertCount(2, $result['employee']);
    }

    function testBuildOutputGroupsSkipsDanglingSuppressedTarget(): void {
        $settings = new \InMemorySettings();
        // Kein Registry-Eintrag - applyDanglingReferenceGuard() hätte
        // "person-geloescht" bereits als suppressedIdTarget aufgenommen.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [['person' => 'geloescht', 'role' => 'founder']],
            ['person-geloescht'], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame([], $result);
    }

    function testBuildOutputGroupsSkipsInactivePerson(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann', ['status' => \SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE]);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [['person' => 'max-mustermann', 'role' => 'founder']],
            [], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame([], $result,
            'Kontrast zur AP2-Statuslogik (applyDanglingReferenceGuard() ist status-unabhängig): '
            .'die org_relations-Ausgabe selbst blendet inaktive Personen aus');
    }

    function testBuildOutputGroupsEmptyWithoutResolvableBaseUrl(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');
        unset($_SERVER['HTTP_HOST']);

        $result = (new \SchemaOrgData_OrgRelationsService())->buildOutputGroups(
            [['person' => 'max-mustermann', 'role' => 'founder']],
            [], $settings, new \SchemaOrgData_PersonsRegistryService(), new \SchemaOrgData_UrlHelper()
        );

        $this->assertSame([], $result);
    }
}
