<?php

/***************************************************************
*
* Deptrac-Konfiguration für schemaOrgData
*
* Vier Schichten mit einer einzigen Aussage: Domain greift auf
* nichts zu. JSON-LD-Aufbau, @id-Auflösung sowie Geltungsbereichs-
* und Kollisionslogik kennen moziloCMS nicht.
*
* Die Zuordnung läuft über classLike-Collectors auf die
* Komponentennamen, nicht über Verzeichnisse: lib/ ist flach und
* trägt die Schichtung nicht. Eine neue Komponente bleibt bis zum
* Eintrag schichtlos und erscheint als uncovered dependency, nicht
* als Verstoß.
*
* Die Cms-Schicht führt nur die belegten Kernklassen. Eine nicht
* geführte Kernklasse fällt ebenfalls in die uncovered-Zahl.
*
***************************************************************/

use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('plugins/schemaOrgData')
        ->cacheFile('.deptrac.cache')
        ->layers(
            $domain = Layer::withName('Domain')->collectors(
                ClassLikeConfig::create('^SchemaOrgData_ScopeResolver$'),
                ClassLikeConfig::create('^SchemaOrgData_JsonLdBuilder$'),
                ClassLikeConfig::create('^SchemaOrgData_IdReferenceService$'),
                ClassLikeConfig::create('^SchemaOrgData_CollisionDetector$'),
                ClassLikeConfig::create('^SchemaOrgData_OpeningHoursHelper$'),
                ClassLikeConfig::create('^SchemaOrgData_DataSplitHelper$'),
                ClassLikeConfig::create('^SchemaOrgData_Validator$'),
                ClassLikeConfig::create('^SchemaOrgData_ValidationResult$'),
                ClassLikeConfig::create('^SchemaOrgData_OrgRelationsService$'),
            ),
            $io = Layer::withName('Io')->collectors(
                ClassLikeConfig::create('^SchemaOrgData_UrlHelper$'),
                ClassLikeConfig::create('^SchemaOrgData_LanguageService$'),
                ClassLikeConfig::create('^SchemaOrgData_SchemaRepository$'),
                ClassLikeConfig::create('^SchemaOrgData_ImportService$'),
                ClassLikeConfig::create('^SchemaOrgData_ConfigSaveService$'),
                ClassLikeConfig::create('^SchemaOrgData_PersonsRegistryService$'),
            ),
            $ui = Layer::withName('Ui')->collectors(
                ClassLikeConfig::create('^SchemaOrgData_FormRenderer$'),
                ClassLikeConfig::create('^SchemaOrgData_AdminController$'),
                ClassLikeConfig::create('^SchemaOrgData_AdminPageRenderer$'),
                ClassLikeConfig::create('^SchemaOrgData_AdminRequestHandler$'),
                ClassLikeConfig::create('^SchemaOrgData_AdminRequestContext$'),
                ClassLikeConfig::create('^SchemaOrgData_FrontendRenderer$'),
                ClassLikeConfig::create('^SchemaOrgData_FrontendRequestContext$'),
                ClassLikeConfig::create('^SchemaOrgData_PersonsAdminRenderer$'),
                ClassLikeConfig::create('^SchemaOrgData_PersonsAdminRequestHandler$'),
                ClassLikeConfig::create('^SchemaOrgData_PersonSuggestionService$'),
                ClassLikeConfig::create('^schemaOrgData$'),
            ),
            $cms = Layer::withName('Cms')->collectors(
                ClassLikeConfig::create('^Plugin$'),
                ClassLikeConfig::create('^Language$'),
                ClassLikeConfig::create('^Properties$'),
                ClassLikeConfig::create('^CatPageClass$'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($domain),
            Ruleset::forLayer($io)->accesses($domain, $cms),
            Ruleset::forLayer($ui)->accesses($domain, $io, $cms),
            Ruleset::forLayer($cms),
        );
};
