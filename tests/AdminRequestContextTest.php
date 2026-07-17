<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_AdminRequestContext:
*
*   - Konstruktor setzt alle 23 Properties korrekt
*   - Properties sind readonly (Schreibversuch wirft Error)
*
***************************************************************/
final class AdminRequestContextTest extends TestCase {

    private function pluginSelfDir(): string {
        return \BASE_DIR.'plugins/schemaOrgData/';
    }

    private function adminLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/admin_language_deDE.txt');
    }

    private function weekdayLang(): \Language {
        return new \Language($this->pluginSelfDir().'sprachen/cms_language_deDE.txt');
    }

    private function buildContext(): \SchemaOrgData_AdminRequestContext {
        return new \SchemaOrgData_AdminRequestContext(
            new \InMemorySettings(),
            $this->adminLang(),
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            $this->pluginSelfDir(),
            new \SchemaOrgData_FormRenderer(),
            new \SchemaOrgData_DataSplitHelper(),
            new \SchemaOrgData_UrlHelper(),
            'deDE',
            'https://www.example.com/plugins/schemaOrgData/',
            $this->weekdayLang(),
            new \SchemaOrgData_IdReferenceService(),
            new \SchemaOrgData_Validator(),
            new \SchemaOrgData_OpeningHoursHelper(),
            new \SchemaOrgData_CollisionDetector(),
            new \SchemaOrgData_AdminPageRenderer(),
            new \SchemaOrgData_AdminRequestHandler(),
            new \SchemaOrgData_ConfigSaveService(),
            new \SchemaOrgData_ImportService(),
            new \SchemaOrgData_PersonsRegistryService(),
            new \SchemaOrgData_PersonsAdminRenderer(),
            new \SchemaOrgData_PersonsAdminRequestHandler(),
            new \SchemaOrgData_OrgRelationsService()
        );
    }

    function testKonstruktorSetztAllePropertiesKorrekt(): void {
        $settings = new \InMemorySettings();
        $lang = $this->adminLang();
        $scopeResolver = new \SchemaOrgData_ScopeResolver();
        $schemaRepository = new \SchemaOrgData_SchemaRepository();
        $pluginSelfDir = $this->pluginSelfDir();
        $formRenderer = new \SchemaOrgData_FormRenderer();
        $dataSplitHelper = new \SchemaOrgData_DataSplitHelper();
        $urlHelper = new \SchemaOrgData_UrlHelper();
        $weekdayLang = $this->weekdayLang();
        $idReferenceService = new \SchemaOrgData_IdReferenceService();
        $validator = new \SchemaOrgData_Validator();
        $openingHoursHelper = new \SchemaOrgData_OpeningHoursHelper();
        $collisionDetector = new \SchemaOrgData_CollisionDetector();
        $adminPageRenderer = new \SchemaOrgData_AdminPageRenderer();
        $adminRequestHandler = new \SchemaOrgData_AdminRequestHandler();
        $configSaveService = new \SchemaOrgData_ConfigSaveService();
        $importService = new \SchemaOrgData_ImportService();
        $personsRegistryService = new \SchemaOrgData_PersonsRegistryService();
        $personsAdminRenderer = new \SchemaOrgData_PersonsAdminRenderer();
        $personsAdminRequestHandler = new \SchemaOrgData_PersonsAdminRequestHandler();
        $orgRelationsService = new \SchemaOrgData_OrgRelationsService();

        $context = new \SchemaOrgData_AdminRequestContext(
            $settings,
            $lang,
            $scopeResolver,
            $schemaRepository,
            $pluginSelfDir,
            $formRenderer,
            $dataSplitHelper,
            $urlHelper,
            'deDE',
            'https://www.example.com/plugins/schemaOrgData/',
            $weekdayLang,
            $idReferenceService,
            $validator,
            $openingHoursHelper,
            $collisionDetector,
            $adminPageRenderer,
            $adminRequestHandler,
            $configSaveService,
            $importService,
            $personsRegistryService,
            $personsAdminRenderer,
            $personsAdminRequestHandler,
            $orgRelationsService
        );

        $this->assertSame($settings, $context->settings);
        $this->assertSame($lang, $context->lang);
        $this->assertSame($scopeResolver, $context->scopeResolver);
        $this->assertSame($schemaRepository, $context->schemaRepository);
        $this->assertSame($pluginSelfDir, $context->pluginSelfDir);
        $this->assertSame($formRenderer, $context->formRenderer);
        $this->assertSame($dataSplitHelper, $context->dataSplitHelper);
        $this->assertSame($urlHelper, $context->urlHelper);
        $this->assertSame('deDE', $context->pluginLang);
        $this->assertSame('https://www.example.com/plugins/schemaOrgData/', $context->pluginSelfUrl);
        $this->assertSame($weekdayLang, $context->weekdayLang);
        $this->assertSame($idReferenceService, $context->idReferenceService);
        $this->assertSame($validator, $context->validator);
        $this->assertSame($openingHoursHelper, $context->openingHoursHelper);
        $this->assertSame($collisionDetector, $context->collisionDetector);
        $this->assertSame($adminPageRenderer, $context->adminPageRenderer);
        $this->assertSame($adminRequestHandler, $context->adminRequestHandler);
        $this->assertSame($configSaveService, $context->configSaveService);
        $this->assertSame($importService, $context->importService);
        $this->assertSame($personsRegistryService, $context->personsRegistryService);
        $this->assertSame($personsAdminRenderer, $context->personsAdminRenderer);
        $this->assertSame($personsAdminRequestHandler, $context->personsAdminRequestHandler);
        $this->assertSame($orgRelationsService, $context->orgRelationsService);
    }

    function testSchreibversuchAufReadonlyPropertyWirftError(): void {
        $context = $this->buildContext();

        $this->expectException(\Error::class);

        $context->pluginSelfDir = '/anderer/pfad/';
    }

    function testSchreibversuchAufSettingsPropertyWirftError(): void {
        $context = $this->buildContext();

        $this->expectException(\Error::class);

        $context->settings = new \InMemorySettings();
    }

    function testSchreibversuchAufConfigSaveServicePropertyWirftError(): void {
        $context = $this->buildContext();

        $this->expectException(\Error::class);

        $context->configSaveService = new \SchemaOrgData_ConfigSaveService();
    }
}
