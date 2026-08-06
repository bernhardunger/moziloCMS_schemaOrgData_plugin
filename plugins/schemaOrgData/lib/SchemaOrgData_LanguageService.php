<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_LanguageService
*
* Ordnet einen CMS-Sprachcode (z. B. 'de', 'deDE') dem
* Plugin-internen Locale-Code zu. Wird von der Fassade
* schemaOrgData über einen Lazy-Accessor verdrahtet.
*
***************************************************************/
class SchemaOrgData_LanguageService {

    /***************************************************************
    *
    * @param array<string, string> $prefixMap Zuordnung 2-Zeichen-Prefix → Locale-Code
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

    /***************************************************************
    *
    * Lässt nur die als Werteseite von $prefixMap bekannten Locales
    * durch und fällt sonst auf DEFAULT_LANGUAGE zurück. Die beiden
    * Ladefunktionen bauen aus dem Parameter einen Dateipfad; ohne
    * diesen Guard trüge ein Aufrufer, der den Wert nicht aus
    * resolvePluginLanguage() bezieht, Pfadanteile hinein. Damit ist der
    * Kontrakt derselbe wie bei SchemaOrgData_SchemaRepository::loadSchema(),
    * das den Type-Namen ebenfalls gegen den Bestand prüft statt ihn
    * still zu normalisieren. Kein Verhaltensunterschied für die
    * heutigen Aufrufer.
    *
    ***************************************************************/
    private function normalizeLocale(string $locale): string {
        return in_array($locale, $this->prefixMap, true) ? $locale : $this->defaultLanguage;
    }

    /***************************************************************
    *
    * Instanziiert das Sprachobjekt für die Admin-UI
    * (sprachen/admin_language_{locale}.txt). Zustandslos - Caching
    * und $pluginLang-Seiteneffekt bleiben auf der Fassade.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $locale        Locale-Code ('deDE' oder 'enEN')
    *
    ***************************************************************/
    public function loadAdminLanguageFile(string $pluginSelfDir, string $locale): Language {
        return new Language($pluginSelfDir.'sprachen/admin_language_'.$this->normalizeLocale($locale).'.txt');
    }

    /***************************************************************
    *
    * Instanziiert ein Sprachobjekt aus dem CMS-Sprachbestand
    * (sprachen/cms_language_{locale}.txt). Wird sowohl für den
    * Frontend-Kontext (cms_lang) als auch für die Wochentag-Labels
    * im Admin-Formular (weekday_lang, dort mit der Admin-Sprache)
    * verwendet.
    *
    * @param string $pluginSelfDir Plugin-Basisverzeichnis (PLUGIN_SELF_DIR)
    * @param string $locale        Locale-Code ('deDE' oder 'enEN')
    *
    ***************************************************************/
    public function loadCmsLanguageFile(string $pluginSelfDir, string $locale): Language {
        return new Language($pluginSelfDir.'sprachen/cms_language_'.$this->normalizeLocale($locale).'.txt');
    }
}
