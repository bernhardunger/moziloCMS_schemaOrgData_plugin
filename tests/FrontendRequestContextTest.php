<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_FrontendRequestContext:
*
*   - Konstruktor setzt alle 8 Properties korrekt
*   - Properties sind readonly (Schreibversuch wirft Error)
*
***************************************************************/
final class FrontendRequestContextTest extends TestCase {

    private function buildContext(): \SchemaOrgData_FrontendRequestContext {
        return new \SchemaOrgData_FrontendRequestContext(
            new \InMemorySettings(),
            '/pfad/zum/plugin/',
            new \SchemaOrgData_ScopeResolver(),
            new \SchemaOrgData_SchemaRepository(),
            new \SchemaOrgData_JsonLdBuilder(),
            new \SchemaOrgData_IdReferenceService(),
            new \SchemaOrgData_CollisionDetector(),
            new \SchemaOrgData_UrlHelper()
        );
    }

    function testKonstruktorSetztAllePropertiesKorrekt(): void {
        $settings = new \InMemorySettings();
        $scopeResolver = new \SchemaOrgData_ScopeResolver();
        $schemaRepository = new \SchemaOrgData_SchemaRepository();
        $jsonLdBuilder = new \SchemaOrgData_JsonLdBuilder();
        $idReferenceService = new \SchemaOrgData_IdReferenceService();
        $collisionDetector = new \SchemaOrgData_CollisionDetector();
        $urlHelper = new \SchemaOrgData_UrlHelper();

        $context = new \SchemaOrgData_FrontendRequestContext(
            $settings,
            '/pfad/zum/plugin/',
            $scopeResolver,
            $schemaRepository,
            $jsonLdBuilder,
            $idReferenceService,
            $collisionDetector,
            $urlHelper
        );

        $this->assertSame($settings, $context->settings);
        $this->assertSame('/pfad/zum/plugin/', $context->pluginSelfDir);
        $this->assertSame($scopeResolver, $context->scopeResolver);
        $this->assertSame($schemaRepository, $context->schemaRepository);
        $this->assertSame($jsonLdBuilder, $context->jsonLdBuilder);
        $this->assertSame($idReferenceService, $context->idReferenceService);
        $this->assertSame($collisionDetector, $context->collisionDetector);
        $this->assertSame($urlHelper, $context->urlHelper);
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
}
