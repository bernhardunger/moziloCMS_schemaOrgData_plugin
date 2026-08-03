<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Direkt-Tests der Komponente SchemaOrgData_PersonsAdminRenderer:
* renderPersonsSection() - Personenliste (leer/befüllt), aktive
* Ansicht (Liste/Anlegen/Bearbeiten), disabled-Zustand inaktiver
* Ansichten, Redisplay mit Fehlermeldungen, Bild-Verfügbarkeits-
* Feedback.
*
***************************************************************/
final class PersonsAdminRendererTest extends TestCase {

    private function renderer(): \SchemaOrgData_PersonsAdminRenderer {
        return new \SchemaOrgData_PersonsAdminRenderer();
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

    private function urlHelper(): \SchemaOrgData_UrlHelper {
        return new \SchemaOrgData_UrlHelper();
    }

    private function formRenderer(): \SchemaOrgData_FormRenderer {
        return new \SchemaOrgData_FormRenderer();
    }

    private function render(
        $settings,
        bool $visibleInitially = false,
        string $activeViewId = '',
        array $redisplayData = [],
        array $redisplayErrors = []
    ): string {
        $renderer = $this->renderer();
        $activeViewId = ($activeViewId !== '') ? $activeViewId : $renderer->listViewId();

        return $renderer->renderPersonsSection(
            $settings, $this->adminLang(), $this->registryService(), $this->validator(), $this->urlHelper(),
            $this->formRenderer(), $visibleInitially, $activeViewId, $redisplayData, $redisplayErrors
        );
    }

    function testLeereRegistryZeigtLeerHinweisUndNeueLPersonButton(): void {
        $html = $this->render(new \InMemorySettings());

        $this->assertStringContainsString('schemaOrgData_persons_container', $html);
        $this->assertStringContainsString($this->adminLang()->getLanguageValue('label_persons_registry_empty'), $html);
        $this->assertStringContainsString('schemaOrgData_persons_view_new', $html);
    }

    function testContainerIstInitialVersborgenWennNichtSichtbar(): void {
        $html = $this->render(new \InMemorySettings(), false);

        $this->assertMatchesRegularExpression(
            '/<div id="schemaOrgData_persons_container"[^>]*style="display:none"/',
            $html
        );
    }

    function testContainerIstSichtbarWennVisibleInitiallyTrue(): void {
        $html = $this->render(new \InMemorySettings(), true);

        $this->assertDoesNotMatchRegularExpression(
            '/<div id="schemaOrgData_persons_container"[^>]*style="display:none"/',
            $html
        );
    }

    /***************************************************************
    *
    * Der "Zurück zu Global/Kategorie/Seite"-Button lebt im Personen-
    * Container, nicht im Scope-Container (siehe SchemaOrgData_AdminController::
    * renderAdminPage()) - er muss deshalb unabhängig von der jeweils
    * aktiven Unteransicht (Liste/Anlegen/Bearbeiten) immer vorhanden
    * sein, damit er aus jeder Unteransicht heraus erreichbar ist.
    *
    ***************************************************************/
    function testZurueckButtonIstInJederUnteransichtVorhanden(): void {
        $listHtml = $this->render(new \InMemorySettings());
        $this->assertStringContainsString('schemaOrgData_persons_back_btn', $listHtml);

        $newHtml = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());
        $this->assertStringContainsString('schemaOrgData_persons_back_btn', $newHtml);

        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());
        $editHtml = $this->render($settings, true, $this->renderer()->buildEditViewId('max'));
        $this->assertStringContainsString('schemaOrgData_persons_back_btn', $editHtml);
    }

    /***************************************************************
    *
    * Der "Personen verwalten"-Button gehört in den Scope-Container
    * (SchemaOrgData_AdminController::renderAdminPage()) - im Personen-
    * Container selbst darf er nicht erneut auftauchen, sonst würde ein
    * Klick darauf ins Leere laufen (beide Container wären bereits im
    * Zielzustand).
    *
    ***************************************************************/
    function testManagePersonsButtonErscheintNichtImPersonenContainer(): void {
        $html = $this->render(new \InMemorySettings());

        $this->assertStringNotContainsString('schemaOrgData_persons_toggle_btn', $html);
    }

    function testPersonenlisteZeigtNameJobTitleStatusUndSortOrder(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'jobTitle' => 'Steuerberater', 'sortOrder' => '50', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings);

        $this->assertStringContainsString('Max Mustermann', $html);
        $this->assertStringContainsString('Steuerberater', $html);
        $this->assertStringContainsString('value="50"', $html);
        $this->assertStringContainsString('schemaOrgData_persons_view_edit_max', $html);
        $this->assertStringContainsString('value="delete:max"', $html);
    }

    function testInaktivePersonZeigtInaktivLabel(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Ehemalige Person', 'status' => 'inactive', 'slug' => 'ehemalige',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings);

        $this->assertStringContainsString($this->adminLang()->getLanguageValue('label_person_status_inactive'), $html);
    }

    function testNeueLPersonAnsichtIstStandardmaessigVerborgenUndDisabled(): void {
        $html = $this->render(new \InMemorySettings());

        // Die "Neue Person"-Ansicht ist vorgerendert, aber inaktiv (Liste ist
        // Default-Ansicht) - ihre Felder müssen disabled sein, sonst würden
        // mehrfach vorgerenderte Formulare mit identischen Feldnamen beim
        // Speichern kollidieren.
        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_persons_view_new"[^>]*style="display:none"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input disabled="disabled"[^>]*name="schemaOrgData_persons_data\[name\]"/',
            $html
        );
    }

    function testAktiveNeueLPersonAnsichtIstSichtbarUndNichtDisabled(): void {
        $renderer = $this->renderer();
        $html = $this->render(new \InMemorySettings(), true, $renderer->newViewId());

        $this->assertDoesNotMatchRegularExpression(
            '/id="schemaOrgData_persons_view_new"[^>]*style="display:none"/',
            $html
        );
    }

    function testBearbeitenAnsichtZeigtSlugSchreibgeschuetztUndVorbefuellteWerte(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'jobTitle' => 'Steuerberater', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $renderer = $this->renderer();
        $html = $this->render($settings, true, $renderer->buildEditViewId('max'));

        $this->assertStringContainsString('<code>max</code>', $html);
        $this->assertStringContainsString('value="Steuerberater"', $html);

        // Das Slug-Eingabefeld (name="...[slug]") existiert nur im "Neue
        // Person"-Formular (dort disabled, da inaktiv) - im Bearbeiten-
        // Formular selbst darf es kein zweites, editierbares Slug-Feld geben.
        $editViewHtml = substr($html, (int) strpos($html, 'id="schemaOrgData_persons_view_edit_max"'));
        $this->assertStringNotContainsString('name="schemaOrgData_persons_data[slug]"', $editViewHtml);
    }

    function testRedisplayZeigtFehlermeldungenInDerAktivenAnsicht(): void {
        $renderer = $this->renderer();
        $html = $this->render(
            new \InMemorySettings(), true, $renderer->newViewId(),
            ['name' => ''], ['Pflichtfeld "Name" fehlt.']
        );

        $this->assertStringContainsString('Pflichtfeld &quot;Name&quot; fehlt.', $html);
    }

    function testBildFeedbackWarntBeiNichtVorhandenerRelativerDatei(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'image' => 'persons/nicht-vorhanden.jpg', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $renderer = $this->renderer();
        $html = $this->render($settings, true, $renderer->buildEditViewId('max'));

        $this->assertStringContainsString('schemaOrgData-feedback--warning', $html);
    }

    /***************************************************************
    *
    * Das sortOrder-Feld erklärt seinen Zweck (Anzeigereihenfolge, keine
    * Auswirkung auf JSON-LD) über einen Hilfetext und trägt das
    * data-validate-Attribut für die nicht-blockierende Live-Warnung bei
    * ungültiger Eingabe (js/validator.js, validateSortOrder()).
    *
    ***************************************************************/
    function testSortOrderFeldZeigtHilfetextUndTraegtDataValidateAttribut(): void {
        $html = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());

        $this->assertStringContainsString($this->adminLang()->getLanguageHtml('hint_sort_order'), $html);
        $this->assertMatchesRegularExpression(
            '/name="schemaOrgData_persons_data\[sortOrder\]"[^>]*data-validate="sort_order"/',
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest gegen den optionalen $extraAttrs-Parameter von
    * renderTextRow(): das url-Feld darf kein data-validate-Attribut
    * erhalten, da der Default-Parameter dort nicht greifen soll. Das
    * name-Feld trägt im "Neue Person"-Formular seit der Slug-Live-
    * Kollisionsprüfung bewusst data-validate="person_slug" (siehe
    * testNameUndSlugFeldTragenDataValidatePersonSlugImNeuFormular())
    * und ist daher hier ausgenommen.
    *
    ***************************************************************/
    function testUebrigeTextfelderTragenKeinDataValidateAttribut(): void {
        $html = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());

        $this->assertDoesNotMatchRegularExpression(
            '/name="schemaOrgData_persons_data\[url\]"[^>]*data-validate/',
            $html
        );
    }

    /***************************************************************
    *
    * Jede Zeile der Personenliste trägt den Slug sowie den
    * Anzeigenamen als Datenattribute - Grundlage für die client-
    * seitige Slug-Live-Kollisionsprüfung (js/validator.js,
    * runPersonSlugValidation()), die die Personenliste ohne
    * zusätzlichen Server-Request direkt aus dem DOM ausliest.
    *
    ***************************************************************/
    function testListenZeileTraegtDataSlugUndDataSlugName(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max', 'honorificPrefix' => 'Dr.',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true, $this->renderer()->listViewId());

        $this->assertStringContainsString('data-slug="max"', $html);
        $this->assertStringContainsString('data-slug-name="Dr. Max Mustermann"', $html);
    }

    /***************************************************************
    *
    * Slug- und Namensfeld des "Neue Person"-Formulars sind über
    * data-validate="person_slug" und ein gegenseitiges data-pair
    * miteinander verknüpft - Grundlage für runPersonSlugValidation()
    * (js/validator.js), das je nach ausgelöstem Feld entweder den
    * expliziten Slug oder den aus dem Namen abgeleiteten Vorschlag
    * gegen die Personenliste prüft.
    *
    ***************************************************************/
    function testNameUndSlugFeldTragenDataValidatePersonSlugImNeuFormular(): void {
        $html = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());

        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_persons_new_slug"[^>]*data-validate="person_slug"[^>]*data-pair="schemaOrgData_persons_new_name"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="schemaOrgData_persons_new_name"[^>]*data-validate="person_slug"[^>]*data-pair="schemaOrgData_persons_new_slug"/',
            $html
        );
    }

    /***************************************************************
    *
    * Regressionstest: Das Namensfeld im Bearbeiten-Formular trägt
    * kein data-validate-Attribut - dort ist der Slug schreibgeschützt
    * (<code>-Anzeige statt Eingabefeld), eine Live-Kollisionsprüfung
    * ist dort kein Anwendungsfall.
    *
    ***************************************************************/
    function testNamensfeldImBearbeitenFormularTraegtKeinDataValidateAttribut(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true, $this->renderer()->buildEditViewId('max'));

        // Alle Ansichten werden gleichzeitig vorgerendert (siehe
        // Klassen-Docblock) - die Prüfung muss sich daher auf den
        // Bearbeiten-Ausschnitt beschränken, sonst würde das
        // gleichnamige name-Feld des "Neue Person"-Formulars (trägt das
        // Attribut bewusst) den Regressionstest verfälschen.
        $editViewHtml = substr($html, (int) strpos($html, 'id="schemaOrgData_persons_view_edit_max"'));
        $this->assertDoesNotMatchRegularExpression(
            '/name="schemaOrgData_persons_data\[name\]"[^>]*data-validate/',
            $editViewHtml
        );
    }

    /***************************************************************
    *
    * Das Namensfeld steht im gerenderten HTML jetzt vor dem Slug-Feld
    * bzw. der Slug-<code>-Zeile (passender, da der Slug meist aus dem
    * Namen abgeleitet wird) - in beiden Ansichten (Neu und
    * Bearbeiten).
    *
    ***************************************************************/
    function testNamensfeldStehtVorDemSlugFeldInBeidenAnsichten(): void {
        $newHtml = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());
        $namePosNew = strpos($newHtml, 'name="schemaOrgData_persons_data[name]"');
        $slugPosNew = strpos($newHtml, 'name="schemaOrgData_persons_data[slug]"');
        $this->assertIsInt($namePosNew);
        $this->assertIsInt($slugPosNew);
        $this->assertLessThan($slugPosNew, $namePosNew);

        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());
        $editHtml = $this->render($settings, true, $this->renderer()->buildEditViewId('max'));
        $namePosEdit = strpos($editHtml, 'name="schemaOrgData_persons_data[name]"');
        $slugPosEdit = strpos($editHtml, '<code>max</code>');
        $this->assertIsInt($namePosEdit);
        $this->assertIsInt($slugPosEdit);
        $this->assertLessThan($slugPosEdit, $namePosEdit);
    }

    // -----------------------------------------------------------
    // data-action-Verdrahtung (js/validator.js, initDataActions())
    // -----------------------------------------------------------

    function testZurueckButtonTraegtDataActionStattInlineHandler(): void {
        $html = $this->render(new \InMemorySettings(), true);

        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="schemaOrgData_persons_back_btn"[^>]*data-action="persons-back"/',
            $html
        );
    }

    function testListenButtonsTragenDataActionMitViewZiel(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true);

        $this->assertMatchesRegularExpression(
            '/data-action="persons-show" data-persons-target="schemaOrgData_persons_view_edit_max"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-action="persons-show" data-persons-target="'.preg_quote($this->renderer()->newViewId(), '/').'"/',
            $html
        );
    }

    function testAbbrechenButtonZeigtAufDieListenansicht(): void {
        $html = $this->render(new \InMemorySettings(), true, $this->renderer()->newViewId());

        $this->assertMatchesRegularExpression(
            '/data-action="persons-show" data-persons-target="'.preg_quote($this->renderer()->listViewId(), '/').'"/',
            $html
        );
    }

    function testLoeschenButtonTraegtBestaetigungstextAlsDatenattribut(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true);

        $this->assertStringContainsString(
            'data-action="confirm" data-confirm="'
                .htmlspecialchars($this->adminLang()->getLanguageValue('confirm_delete_person'), ENT_QUOTES, CHARSET).'"',
            $html
        );
    }

    /***************************************************************
    *
    * Ein Apostroph im Bestätigungstext darf das Attribut nicht
    * verlassen. Als Inhalt eines onclick-Attributs lag der Wert in
    * einem JS-String-Literal innerhalb eines HTML-Attributs: der
    * HTML-Parser wandelte das von ENT_QUOTES erzeugte &#039; vor der
    * JS-Auswertung wieder in einen Apostroph zurück, der das Literal
    * dann vorzeitig beendete. Als reines Datenattribut gibt es nur
    * noch den HTML-Kontext, den htmlspecialchars() abdeckt.
    *
    ***************************************************************/
    function testApostrophImBestaetigungstextBleibtImDatenattributKodiert(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $langFile = sys_get_temp_dir().'/schemaOrgData_admin_language_apostroph_'.uniqid().'_deDE.txt';
        $original = file_get_contents($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
        $patched = preg_replace(
            '/^confirm_delete_person = .*$/m',
            "confirm_delete_person = Person's Eintrag wirklich löschen?",
            (string) $original
        );
        file_put_contents($langFile, $patched);

        try {
            $renderer = $this->renderer();
            $html = $renderer->renderPersonsSection(
                $settings, new \Language($langFile), $this->registryService(), $this->validator(),
                $this->urlHelper(), $this->formRenderer(), true, $renderer->listViewId(), [], []
            );

            $this->assertStringContainsString('data-confirm="Person&#039;s Eintrag wirklich löschen?"', $html);
            $this->assertStringNotContainsString('data-confirm="Person\'s', $html);
        } finally {
            unlink($langFile);
        }
    }

    function testAusgabeEnthaeltKeineInlineEventHandlerAttribute(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true);

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|keyup|input)=/i', $html);
    }

    function testAusgabeEnthaeltKeinInlineScript(): void {
        $settings = new \InMemorySettings();
        $this->registryService()->createPerson($settings, [
            'name' => 'Max Mustermann', 'slug' => 'max',
        ], $this->adminLang(), $this->validator());

        $html = $this->render($settings, true);

        $this->assertStringNotContainsString('<script', $html);
    }
}
