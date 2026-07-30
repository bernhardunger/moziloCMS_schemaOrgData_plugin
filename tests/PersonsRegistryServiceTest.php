<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_PersonsRegistryService:
* Slug-Bildung/-Sanitizing, Bereinigung/Validierung der Formularfelder
* sowie CRUD gegen InMemorySettings (loadRegistry/getPerson/slugExists/
* createPerson/updatePerson/deletePerson/findReferences).
*
***************************************************************/
final class PersonsRegistryServiceTest extends TestCase {

    private function service(): \SchemaOrgData_PersonsRegistryService {
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

    // generateSlugSuggestion() -----------------------------------------

    function testGenerateSlugSuggestionTransliteriertUmlauteUndKleinschreibung(): void {
        $service = $this->service();

        $this->assertSame('max-mustermann', $service->generateSlugSuggestion('Max Mustermann'));
        $this->assertSame('juergen-mueller-schoen', $service->generateSlugSuggestion('Jürgen Müller-Schön'));
        $this->assertSame('strasse-weiss', $service->generateSlugSuggestion('Straße Weiß'));
    }

    function testGenerateSlugSuggestionEntferntFuehrendeUndAbschliessendeBindestriche(): void {
        $service = $this->service();

        $this->assertSame('a-b', $service->generateSlugSuggestion('  !A B?  '));
        $this->assertSame('', $service->generateSlugSuggestion('   '));
    }

    // sanitizeSlugCandidate() -------------------------------------------

    function testSanitizeSlugCandidateEntferntUnzulaessigeZeichenUndKleinschreibt(): void {
        $service = $this->service();

        $this->assertSame('max-mustermann', $service->sanitizeSlugCandidate('Max Mustermann'));
        $this->assertSame('ab_c-1', $service->sanitizeSlugCandidate('A/b_c-1!'));
    }

    // sanitizeRelativeMediaPath() -----------------------------------------

    function testSanitizeRelativeMediaPathEntferntTraversalUndFuehrendeSlashes(): void {
        $service = $this->service();

        $this->assertSame('persons/max.jpg', $service->sanitizeRelativeMediaPath('/persons/max.jpg'));
        $this->assertSame('etc/passwd', $service->sanitizeRelativeMediaPath('../../etc/passwd'));
        $this->assertSame('a/b.png', $service->sanitizeRelativeMediaPath('a/../b.png'));
    }

    // sanitizePersonData() -------------------------------------------------

    function testSanitizePersonDataTrimmtUndEntferntHtmlTags(): void {
        $service = $this->service();

        $result = $service->sanitizePersonData([
            'name' => '  <b>Max Mustermann</b>  ',
            'honorificPrefix' => ' Dr. ',
            'jobTitle' => ' Steuerberater ',
        ]);

        $this->assertSame('Max Mustermann', $result['name']);
        $this->assertSame('Dr.', $result['honorificPrefix']);
        $this->assertSame('Steuerberater', $result['jobTitle']);
    }

    function testSanitizePersonDataTeiltSameAsZeilenweiseUndTilgtLeerzeilen(): void {
        $service = $this->service();

        $result = $service->sanitizePersonData([
            'sameAs' => "https://www.linkedin.com/in/max\n\n  \nhttps://www.xing.com/profile/max",
        ]);

        $this->assertSame(
            ['https://www.linkedin.com/in/max', 'https://www.xing.com/profile/max'],
            $result['sameAs']
        );
    }

    function testSanitizePersonDataTeiltKnowsAboutZeilenweiseUndTilgtLeerzeilen(): void {
        $service = $this->service();

        $result = $service->sanitizePersonData([
            'knowsAbout' => "Steuerrecht\n\n  \nUnternehmensberatung",
        ]);

        $this->assertSame(['Steuerrecht', 'Unternehmensberatung'], $result['knowsAbout']);
    }

    function testSanitizePersonDataKnowsAboutFehltErgibtLeeresArray(): void {
        $service = $this->service();

        $this->assertSame([], $service->sanitizePersonData([])['knowsAbout']);
    }

    function testSanitizePersonDataStatusDefaultUndWhitelist(): void {
        $service = $this->service();

        $this->assertSame('active', $service->sanitizePersonData([])['status']);
        $this->assertSame('inactive', $service->sanitizePersonData(['status' => 'inactive'])['status']);
        $this->assertSame('active', $service->sanitizePersonData(['status' => 'irgendwas'])['status']);
    }

    function testSanitizePersonDataSortOrderDefaultBeiLeerOderUngueltig(): void {
        $service = $this->service();

        $this->assertSame(100, $service->sanitizePersonData([])['sortOrder']);
        $this->assertSame(100, $service->sanitizePersonData(['sortOrder' => 'abc'])['sortOrder']);
        $this->assertSame(50, $service->sanitizePersonData(['sortOrder' => '50'])['sortOrder']);
        $this->assertSame(-5, $service->sanitizePersonData(['sortOrder' => '-5'])['sortOrder']);
    }

    function testSanitizePersonDataImageAbsoluteUrlBleibtUnveraendertRelativeWirdSanitiert(): void {
        $service = $this->service();

        $this->assertSame(
            'https://example.com/x.jpg',
            $service->sanitizePersonData(['image' => 'https://example.com/x.jpg'])['image']
        );
        $this->assertSame(
            'persons/max.jpg',
            $service->sanitizePersonData(['image' => '/persons/max.jpg'])['image']
        );
    }

    // validatePersonData() ---------------------------------------------

    function testValidatePersonDataMeldetFehlendenNamen(): void {
        $service = $this->service();
        $lang = $this->adminLang();

        $errors = $service->validatePersonData($service->sanitizePersonData([]), $lang, $this->validator());

        $this->assertNotEmpty($errors);
    }

    function testValidatePersonDataOkBeiGueltigenDaten(): void {
        $service = $this->service();
        $lang = $this->adminLang();

        $sanitized = $service->sanitizePersonData([
            'name' => 'Max Mustermann',
            'url' => 'https://www.example.com',
            'sameAs' => "https://www.linkedin.com/in/max",
            'image' => 'https://example.com/x.jpg',
        ]);

        $this->assertSame([], $service->validatePersonData($sanitized, $lang, $this->validator()));
    }

    function testValidatePersonDataKnowsAboutOhneUrlformatBleibtFehlerfrei(): void {
        $service = $this->service();
        $lang = $this->adminLang();

        $sanitized = $service->sanitizePersonData([
            'name' => 'Max Mustermann',
            'knowsAbout' => "Steuerrecht\nkein-url-format",
        ]);

        $this->assertSame([], $service->validatePersonData($sanitized, $lang, $this->validator()));
    }

    function testValidatePersonDataMeldetUngueltigeUrlUndSameAs(): void {
        $service = $this->service();
        $lang = $this->adminLang();

        $sanitized = $service->sanitizePersonData([
            'name' => 'Max Mustermann',
            'url' => 'nicht-eine-url',
            'sameAs' => "auch-keine-url",
            'image' => 'http://absolute-aber-ungueltig',
        ]);

        $errors = $service->validatePersonData($sanitized, $lang, $this->validator());

        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    // checkImageAvailability() ------------------------------------------

    function testCheckImageAvailabilityLeerOderAbsoluteUrlOhneWarnung(): void {
        $service = $this->service();
        $lang = $this->adminLang();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $this->assertNull($service->checkImageAvailability('', $lang, $this->validator(), $urlHelper)['status']);
        $this->assertNull($service->checkImageAvailability('https://example.com/x.jpg', $lang, $this->validator(), $urlHelper)['status']);
    }

    function testCheckImageAvailabilityWarntBeiNichtVorhandenerDatei(): void {
        $service = $this->service();
        $lang = $this->adminLang();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $result = $service->checkImageAvailability('persons/definitiv-nicht-vorhanden.jpg', $lang, $this->validator(), $urlHelper);

        $this->assertSame('warning', $result['status']);
    }

    // createPerson() / updatePerson() / deletePerson() -------------------

    function testCreatePersonMitAutomatischemSlugAusNamen(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $result = $service->createPerson($settings, ['name' => 'Max Mustermann'], $lang, $this->validator());

        $this->assertTrue($result['success']);
        $this->assertSame('max-mustermann', $result['slug']);
        $this->assertSame('Max Mustermann', $service->getPerson($settings, 'max-mustermann')['name']);
    }

    function testCreatePersonMitExplizitAngegebenemSlug(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $result = $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'MM 2026!'], $lang, $this->validator());

        $this->assertTrue($result['success']);
        $this->assertSame('mm-2026', $result['slug']);
    }

    function testCreatePersonLehntKollidierendenSlugAbMitVerweisAufVorhandenePerson(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $result = $service->createPerson($settings, ['name' => 'Anderer Max', 'slug' => 'max'], $lang, $this->validator());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Max Mustermann', implode(' ', $result['errors']));
        $this->assertNull($result['slug']);

        // Die ursprüngliche Person darf durch den fehlgeschlagenen Versuch
        // nicht überschrieben worden sein.
        $this->assertSame('Max Mustermann', $service->getPerson($settings, 'max')['name']);
    }

    function testCreatePersonOhneNamenUndOhneSlugLiefertSlugPflichtFehler(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $result = $service->createPerson($settings, [], $lang, $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    function testUpdatePersonAktualisiertBestehendePerson(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $result = $service->updatePerson($settings, 'max', ['name' => 'Max M. Mustermann', 'jobTitle' => 'Steuerberater'], $lang, $this->validator());

        $this->assertTrue($result['success']);
        $person = $service->getPerson($settings, 'max');
        $this->assertSame('Max M. Mustermann', $person['name']);
        $this->assertSame('Steuerberater', $person['jobTitle']);
    }

    function testUpdatePersonIgnoriertGepostetenSlugWert(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $service->updatePerson($settings, 'max', ['name' => 'Max Mustermann', 'slug' => 'anderer-slug'], $lang, $this->validator());

        $this->assertTrue($service->slugExists($settings, 'max'));
        $this->assertFalse($service->slugExists($settings, 'anderer-slug'));
    }

    function testUpdatePersonNichtVorhandenerSlugLiefertFehler(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $result = $service->updatePerson($settings, 'nicht-vorhanden', ['name' => 'X'], $lang, $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    function testDeletePersonEntferntPersonAusDerRegistry(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $result = $service->deletePerson($settings, 'max', $lang);

        $this->assertTrue($result['success']);
        $this->assertFalse($service->slugExists($settings, 'max'));
    }

    function testDeletePersonIstIdempotentBeiNichtVorhandenemSlug(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();

        $result = $service->deletePerson($settings, 'nicht-vorhanden', $this->adminLang());

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
    }

    // saveRegistry()-Fehlschlag über createPerson/updatePerson/deletePerson ---

    function testCreatePersonMeldetFehlschlagWennRegistryNichtGeschriebenWird(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $settings->failWrites();

        $result = $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $this->adminLang(), $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['slug']);
        $this->assertFalse($service->slugExists($settings, 'max'));
    }

    function testUpdatePersonMeldetFehlschlagWennRegistryNichtGeschriebenWird(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $settings->failWrites();
        $result = $service->updatePerson($settings, 'max', ['name' => 'Neuer Name'], $lang, $this->validator());

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('Max Mustermann', $service->getPerson($settings, 'max')['name']);
    }

    function testDeletePersonMeldetFehlschlagWennRegistryNichtGeschriebenWird(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $settings->failWrites();
        $result = $service->deletePerson($settings, 'max', $lang);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue($service->slugExists($settings, 'max'));
    }

    // findReferences() ----------------------------------------------------

    /**
    * Legt die Organisations-Relationen des globalen Geltungsbereichs so ab,
    * wie SchemaOrgData_ConfigSaveService::saveConfig() sie schreibt.
    *
    * @param array<int, mixed> $relations
    */
    private function storeOrgRelations(\InMemorySettings $settings, $relations): void {
        $settings->set('config_global', ['org_relations' => $relations]);
    }

    function testFindReferencesLiefertRollenLabelEinerVerlinktenPerson(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $this->storeOrgRelations($settings, [
            ['person' => 'max', 'role' => 'founder'],
            ['person' => 'erika', 'role' => 'employee'],
            // Zweite Relation derselben Person und Rolle: das Label erscheint
            // trotzdem nur einmal.
            ['person' => 'max', 'role' => 'founder'],
            ['person' => 'max', 'role' => 'member'],
        ]);

        $this->assertSame(
            [$lang->getLanguageValue('label_role_founder'), $lang->getLanguageValue('label_role_member')],
            $service->findReferences($settings, 'max', $lang)
        );
    }

    function testFindReferencesLiefertLeeresArrayFuerNichtVerlinktePerson(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $this->storeOrgRelations($settings, [['person' => 'erika', 'role' => 'employee']]);

        $this->assertSame([], $service->findReferences($settings, 'max', $lang));
    }

    function testFindReferencesUeberstehtFehlendesUndDefektesOrgRelations(): void {
        $service = $this->service();
        $lang = $this->adminLang();

        $ohneKey = new \InMemorySettings();
        $this->assertSame([], $service->findReferences($ohneKey, 'max', $lang));

        $ohneRelationen = new \InMemorySettings();
        $ohneRelationen->set('config_global', ['Organization' => ['name' => 'Beispiel']]);
        $this->assertSame([], $service->findReferences($ohneRelationen, 'max', $lang));

        $defekt = new \InMemorySettings();
        $defekt->set('config_global', ['org_relations' => 'kein Array']);
        $this->assertSame([], $service->findReferences($defekt, 'max', $lang));

        $defekteZeilen = new \InMemorySettings();
        $this->storeOrgRelations($defekteZeilen, [
            'kein Array',
            ['person' => ['max'], 'role' => 'founder'],
            ['person' => 'max', 'role' => ['founder']],
            ['person' => 'max', 'role' => 'geschaeftsfuehrer'],
            ['role' => 'founder'],
        ]);
        $this->assertSame([], $service->findReferences($defekteZeilen, 'max', $lang));
    }

    function testDeletePersonBrichtBeiVorhandenerRelationAbUndLaesstRegistryUnveraendert(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $this->storeOrgRelations($settings, [['person' => 'max', 'role' => 'employee']]);

        $result = $service->deletePerson($settings, 'max', $lang);

        $this->assertFalse($result['success']);
        $this->assertSame([
            $lang->getLanguageValue(
                'error_person_delete_has_references',
                $lang->getLanguageValue('label_role_employee')
            )
        ], $result['errors']);
        $this->assertTrue($service->slugExists($settings, 'max'));
    }

    function testDeletePersonLoeschtWennOrgRelationsEineAnderePersonFuehrt(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        $service->createPerson($settings, ['name' => 'Max Mustermann', 'slug' => 'max'], $lang, $this->validator());
        $service->createPerson($settings, ['name' => 'Erika Mustermann', 'slug' => 'erika'], $lang, $this->validator());
        $this->storeOrgRelations($settings, [['person' => 'erika', 'role' => 'founder']]);

        $result = $service->deletePerson($settings, 'max', $lang);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse($service->slugExists($settings, 'max'));
    }

    function testDeletePersonBleibtBeiNichtVorhandenemSlugTrotzRelationIdempotent(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();
        $lang = $this->adminLang();

        // Regressionsschutz für die Reihenfolge in deletePerson(): die
        // Idempotenz-Vorprüfung steht vor der Fundstellen-Prüfung, sonst
        // meldete ein bereits gelöschter Slug plötzlich einen Fehler.
        $this->storeOrgRelations($settings, [['person' => 'max', 'role' => 'founder']]);

        $result = $service->deletePerson($settings, 'max', $lang);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
    }

    // loadRegistry() -------------------------------------------------------

    function testLoadRegistryLiefertLeeresArrayOhneGespeicherteRegistry(): void {
        $settings = new \InMemorySettings();
        $service = $this->service();

        $this->assertSame([], $service->loadRegistry($settings));
    }
}
