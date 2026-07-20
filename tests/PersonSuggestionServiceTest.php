<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_PersonSuggestionService
* (Übernahme-Vorschläge für Personen-Literale im Erweiterungsfeld eines
* global konfigurierten Organisations-Identity-Types, siehe README.md,
* Abschnitt "Erweiterungsfeld"): detectSuggestions() (Fund mit/ohne
* Namenstreffer, Negativfälle), acceptSuggestion() (Neuanlage,
* Verlinkung, Wettlauf-Fall, ungültige Property).
*
***************************************************************/
final class PersonSuggestionServiceTest extends TestCase {

    private function adminLang(): \Language {
        return new \Language(\BASE_DIR.'plugins/schemaOrgData/sprachen/admin_language_deDE.txt');
    }

    private function validator(): \SchemaOrgData_Validator {
        return new \SchemaOrgData_Validator();
    }

    private function personsRegistryService(): \SchemaOrgData_PersonsRegistryService {
        return new \SchemaOrgData_PersonsRegistryService();
    }

    private function orgRelationsService(): \SchemaOrgData_OrgRelationsService {
        return new \SchemaOrgData_OrgRelationsService();
    }

    private function service(): \SchemaOrgData_PersonSuggestionService {
        return new \SchemaOrgData_PersonSuggestionService();
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
    // detectSuggestions()
    // -----------------------------------------------------------

    function testDetectSuggestionsFindsPersonLiteralWithoutRegistryMatch(): void {
        $settings = new \InMemorySettings();
        $typeConfig = ['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertCount(1, $result);
        $this->assertSame('employee', $result[0]['property']);
        $this->assertSame('Julia Weber', $result[0]['literal']['name']);
        $this->assertNull($result[0]['matchedSlug']);
    }

    function testDetectSuggestionsFindsPersonLiteralWithRegistryMatch(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'julia-weber', ['name' => 'Julia Weber']);
        $typeConfig = ['founder' => ['@type' => 'Person', 'name' => 'Julia Weber']];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertCount(1, $result);
        $this->assertSame('julia-weber', $result[0]['matchedSlug']);
    }

    function testDetectSuggestionsMatchesAgainstInactivePersonsToo(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'julia-weber', [
            'name' => 'Julia Weber', 'status' => \SchemaOrgData_PersonsRegistryService::STATUS_INACTIVE,
        ]);
        $typeConfig = ['member' => ['@type' => 'Person', 'name' => 'Julia Weber']];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertSame('julia-weber', $result[0]['matchedSlug'],
            'Namensgleichheit ist unabhängig vom Sichtbarkeits-Status');
    }

    function testDetectSuggestionsIgnoresArrayOfMultiplePersons(): void {
        $settings = new \InMemorySettings();
        $typeConfig = ['employee' => [
            ['@type' => 'Person', 'name' => 'Julia Weber'],
            ['@type' => 'Person', 'name' => 'Max Mustermann'],
        ]];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertSame([], $result);
    }

    function testDetectSuggestionsIgnoresObjectWithoutPersonType(): void {
        $settings = new \InMemorySettings();
        $typeConfig = ['employee' => ['@type' => 'Organization', 'name' => 'Andere GmbH']];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertSame([], $result);
    }

    function testDetectSuggestionsIgnoresOtherPropertyNames(): void {
        $settings = new \InMemorySettings();
        $typeConfig = ['foundingPerson' => ['@type' => 'Person', 'name' => 'Julia Weber']];

        $result = $this->service()->detectSuggestions($typeConfig, $this->personsRegistryService(), $settings);

        $this->assertSame([], $result);
    }

    function testDetectSuggestionsReturnsEmptyArrayForEmptyTypeConfig(): void {
        $result = $this->service()->detectSuggestions([], $this->personsRegistryService(), new \InMemorySettings());

        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------
    // renderSuggestionNotice()
    // -----------------------------------------------------------

    function testRenderSuggestionNoticeReturnsEmptyStringWithoutSuggestions(): void {
        $html = $this->service()->renderSuggestionNotice([], 'LocalBusiness', $this->adminLang(), 'global_LocalBusiness');

        $this->assertSame('', $html);
    }

    function testRenderSuggestionNoticeRendersCreateButtonWithoutMatch(): void {
        $suggestions = [['property' => 'employee', 'literal' => ['@type' => 'Person', 'name' => 'Julia Weber'], 'matchedSlug' => null]];

        $html = $this->service()->renderSuggestionNotice($suggestions, 'LocalBusiness', $this->adminLang(), 'global_LocalBusiness');

        $this->assertStringContainsString('name="schemaOrgData_accept_person_suggestion"', $html);
        $this->assertStringContainsString('value="employee"', $html);
        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('button_person_suggestion_create'), $html);
        $this->assertStringContainsString('Julia Weber', $html);
    }

    function testRenderSuggestionNoticeRendersLinkButtonWithMatch(): void {
        $suggestions = [['property' => 'founder', 'literal' => ['@type' => 'Person', 'name' => 'Julia Weber'], 'matchedSlug' => 'julia-weber']];

        $html = $this->service()->renderSuggestionNotice($suggestions, 'LocalBusiness', $this->adminLang(), 'global_LocalBusiness');

        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('button_person_suggestion_link'), $html);
    }

    // -----------------------------------------------------------
    // acceptSuggestion()
    // -----------------------------------------------------------

    function testAcceptSuggestionCreatesNewPersonAndAddsRelation(): void {
        $settings = new \InMemorySettings();
        $config = ['LocalBusiness' => ['employee' => ['@type' => 'Person', 'name' => 'Julia Weber', 'jobTitle' => 'Steuerberaterin']]];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);

        $registry = $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY);
        $this->assertArrayHasKey('julia-weber', $registry);
        $this->assertSame('Steuerberaterin', $registry['julia-weber']['jobTitle']);

        $savedConfig = $settings->get('config_global');
        $this->assertSame([['person' => 'julia-weber', 'role' => 'employee']], $savedConfig['org_relations']);
        $this->assertArrayNotHasKey('employee', $savedConfig['LocalBusiness']);
    }

    function testAcceptSuggestionMapsSameAsArrayToRegistryEntry(): void {
        $settings = new \InMemorySettings();
        $config = ['LocalBusiness' => ['founder' => [
            '@type' => 'Person', 'name' => 'Julia Weber',
            'sameAs' => ['https://www.linkedin.com/in/julia-weber', 'https://www.xing.com/profile/julia_weber'],
        ]]];

        $this->service()->acceptSuggestion(
            'founder', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $registry = $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY);
        $this->assertSame(
            ['https://www.linkedin.com/in/julia-weber', 'https://www.xing.com/profile/julia_weber'],
            $registry['julia-weber']['sameAs']
        );
    }

    function testAcceptSuggestionLinksExistingPersonWithoutCreatingDuplicate(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'julia-weber', ['name' => 'Julia Weber']);
        $config = ['LocalBusiness' => ['member' => ['@type' => 'Person', 'name' => 'Julia Weber']]];

        $result = $this->service()->acceptSuggestion(
            'member', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertTrue($result['success']);
        $registry = $settings->get(\SchemaOrgData_PersonsRegistryService::SETTINGS_KEY);
        $this->assertCount(1, $registry);

        $savedConfig = $settings->get('config_global');
        $this->assertSame([['person' => 'julia-weber', 'role' => 'member']], $savedConfig['org_relations']);
    }

    function testAcceptSuggestionAppendsToExistingOrgRelations(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'max-mustermann');
        $config = [
            'LocalBusiness' => ['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']],
            'org_relations' => [['person' => 'max-mustermann', 'role' => 'founder']],
        ];
        $this->setActivePerson($settings, 'julia-weber', ['name' => 'Julia Weber']);

        $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $savedConfig = $settings->get('config_global');
        $this->assertCount(2, $savedConfig['org_relations']);
    }

    /***************************************************************
    *
    * Reproduktionsfall aus dem manuellen Smoke-Test (hader-304.internet-
    * site.info, 2026-07-20): dieselbe Person wird über eine erneute
    * Übernahme mit exakt derselben Rolle verlinkt, mit der sie bereits in
    * org_relations steht (z. B. identisches Personen-Literal mehrfach ins
    * Erweiterungsfeld eingefügt und jeweils per "Verlinken" bestätigt).
    *
    ***************************************************************/
    function testAcceptSuggestionDoesNotDuplicateExistingRelationWithSameRole(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'julia-weber', ['name' => 'Julia Weber']);
        $config = [
            'LocalBusiness' => ['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']],
            'org_relations' => [['person' => 'julia-weber', 'role' => 'employee']],
        ];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertTrue($result['success']);
        $savedConfig = $settings->get('config_global');
        $this->assertSame([['person' => 'julia-weber', 'role' => 'employee']], $savedConfig['org_relations'],
            'kein doppelter org_relations-Eintrag bei erneuter Verlinkung mit derselben Rolle');
        $this->assertArrayNotHasKey('employee', $savedConfig['LocalBusiness'],
            'Property wird trotzdem aus der Type-Konfiguration entfernt');
    }

    /***************************************************************
    *
    * Regressionstest zum Dedup-Fix: dieselbe Person mit einer ANDEREN
    * Rolle bleibt weiterhin ein eigenständiger, zusätzlicher Eintrag
    * (legitimer Mehrfachrollen-Fall, z. B. jemand ist gleichzeitig
    * founder UND employee).
    *
    ***************************************************************/
    function testAcceptSuggestionAddsSeparateRelationForSamePersonWithDifferentRole(): void {
        $settings = new \InMemorySettings();
        $this->setActivePerson($settings, 'julia-weber', ['name' => 'Julia Weber']);
        $config = [
            'LocalBusiness' => ['employee' => ['@type' => 'Person', 'name' => 'Julia Weber']],
            'org_relations' => [['person' => 'julia-weber', 'role' => 'founder']],
        ];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertTrue($result['success']);
        $savedConfig = $settings->get('config_global');
        $this->assertSame(
            [
                ['person' => 'julia-weber', 'role' => 'founder'],
                ['person' => 'julia-weber', 'role' => 'employee'],
            ],
            $savedConfig['org_relations']
        );
    }

    function testAcceptSuggestionRejectsInvalidProperty(): void {
        $settings = new \InMemorySettings();
        $config = ['LocalBusiness' => []];

        $result = $this->service()->acceptSuggestion(
            'ceo', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    /***************************************************************
    *
    * Wettlauf-Fall: die Property wurde zwischen dem Anzeigen des
    * Vorschlags und dem Bestätigen bereits anderweitig verändert
    * (z. B. durch ein zwischenzeitliches Speichern) - acceptSuggestion()
    * liest den aktuellen Wert erneut aus $config statt eines separat
    * übergebenen Literals und lehnt eine nicht mehr passende Property ab.
    *
    ***************************************************************/
    function testAcceptSuggestionRejectsOutdatedPropertyValue(): void {
        $settings = new \InMemorySettings();
        $config = ['LocalBusiness' => ['employee' => 'kein-objekt-mehr']];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
        $this->assertFalse($settings->keyExists('config_global'));
    }

    function testAcceptSuggestionRejectsMissingProperty(): void {
        $settings = new \InMemorySettings();
        $config = ['LocalBusiness' => []];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertFalse($result['success']);
    }

    function testAcceptSuggestionPropagatesPersonCreationErrors(): void {
        $settings = new \InMemorySettings();
        // Leerer Name -> createPerson() lehnt ab (error_person_slug_required
        // bzw. Pflichtfeld-Fehler aus validatePersonData()).
        $config = ['LocalBusiness' => ['employee' => ['@type' => 'Person', 'name' => '']]];

        $result = $this->service()->acceptSuggestion(
            'employee', $config, 'LocalBusiness', $settings,
            $this->personsRegistryService(), $this->orgRelationsService(), $this->adminLang(), $this->validator()
        );

        $this->assertFalse($result['success']);
        $this->assertNotSame([], $result['errors']);
        $this->assertFalse($settings->keyExists('config_global'),
            'Bei fehlgeschlagener Neuanlage darf config_global nicht verändert werden');
    }
}
