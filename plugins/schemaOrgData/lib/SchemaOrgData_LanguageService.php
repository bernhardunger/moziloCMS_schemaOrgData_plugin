<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_LanguageService
*
* Ordnet einen CMS-Sprachcode (z. B. 'de', 'deDE') dem
* Plugin-internen Locale-Code zu. Wird von der Fassade
* schemaOrgData über einen Lazy-Accessor verdrahtet (siehe
* README.md, Abschnitt "Sprachunterstützung").
*
***************************************************************/
class SchemaOrgData_LanguageService {

    /***************************************************************
    *
    * @param array  $prefixMap       Zuordnung 2-Zeichen-Prefix → Locale-Code
    *                                (Single Source of Truth: schemaOrgData::LANGUAGE_PREFIX_MAP)
    * @param string $defaultLanguage Fallback-Locale (schemaOrgData::DEFAULT_LANGUAGE)
    *
    ***************************************************************/
    public function __construct(
        private array $prefixMap,
        private string $defaultLanguage
    ) {}

    /***************************************************************
    *
    * Normalisiert einen rohen CMS-Sprachcode auf den Plugin-internen
    * moziloCMS-Locale-Code (z. B. 'deDE'). Fällt bei unbekannten
    * Locales auf DEFAULT_LANGUAGE zurück.
    *
    * @param string|null $code Sprachcode aus $ADMIN_CONF->get('language')
    *                          bzw. $CMS_CONF->get('cmslanguage')
    * @return string           Locale-Code ('deDE' oder 'enEN')
    *
    ***************************************************************/
    public function resolvePluginLanguage(?string $code): string {
        $lower = strtolower((string) $code);
        foreach($this->prefixMap as $prefix => $locale) {
            if(str_starts_with($lower, $prefix)) {
                return $locale;
            }
        }
        return $this->defaultLanguage;
    }
}
