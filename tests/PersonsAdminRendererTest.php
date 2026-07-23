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
        // Speichern kollidieren (siehe renderToggleScript()-Docblock).
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
}
