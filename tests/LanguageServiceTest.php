<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_LanguageService:
*
* resolvePluginLanguage():
*   - 'deDE' / 'de'  -> deDE
*   - 'enEN' / 'en'  -> enEN
*   - unbekannter Code -> DEFAULT_LANGUAGE (deDE)
*   - null             -> DEFAULT_LANGUAGE (deDE)
*
* loadAdminLanguageFile() / loadCmsLanguageFile():
*   - bauen für (pluginSelfDir, locale) den korrekten Dateipfad
*     (sprachen/admin_language_{locale}.txt bzw.
*     sprachen/cms_language_{locale}.txt) und liefern ein
*     funktionsfähiges Language-Objekt (deDE und enEN)
*
***************************************************************/
final class LanguageServiceTest extends TestCase {

    private function service(): \SchemaOrgData_LanguageService {
        return new \SchemaOrgData_LanguageService(
            ['de' => 'deDE', 'en' => 'enEN'],
            'deDE'
        );
    }

    function testVollstaendigerDedeCodeWirdErkannt(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('deDE'));
    }

    function testZweiZeichenDePrefixWirdErkannt(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('de'));
    }

    function testVollstaendigerEnEnCodeWirdErkannt(): void {
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('enEN'));
    }

    function testZweiZeichenEnPrefixWirdErkannt(): void {
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('en'));
    }

    function testUnbekannterCodeFaelltAufDefault(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('fr'));
    }

    function testNullFaelltAufDefault(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage(null));
    }

    function testGrossSchreibungWirdIgnoriert(): void {
        $this->assertSame('deDE', $this->service()->resolvePluginLanguage('DE'));
        $this->assertSame('enEN', $this->service()->resolvePluginLanguage('EN'));
    }

    private function pluginSelfDir(): string {
        return BASE_DIR.'plugins/schemaOrgData/';
    }

    function testLoadAdminLanguageFileLaedtDeDeDatei(): void {
        $lang = $this->service()->loadAdminLanguageFile($this->pluginSelfDir(), 'deDE');

        $this->assertSame('Einstellungen', $lang->getLanguageValue('admin_button'));
    }

    function testLoadAdminLanguageFileLaedtEnEnDatei(): void {
        $lang = $this->service()->loadAdminLanguageFile($this->pluginSelfDir(), 'enEN');

        $this->assertSame('Settings', $lang->getLanguageValue('admin_button'));
    }

    function testLoadCmsLanguageFileLaedtDeDeDatei(): void {
        $lang = $this->service()->loadCmsLanguageFile($this->pluginSelfDir(), 'deDE');

        $this->assertSame('Montag', $lang->getLanguageValue('weekday_monday'));
    }

    function testLoadCmsLanguageFileLaedtEnEnDatei(): void {
        $lang = $this->service()->loadCmsLanguageFile($this->pluginSelfDir(), 'enEN');

        $this->assertSame('Monday', $lang->getLanguageValue('weekday_monday'));
    }

    // B4-06: Beide Ladefunktionen bauen aus dem Locale einen Dateipfad und
    // lassen nur die Werteseite der prefixMap durch. Der Language-Mock in
    // bootstrap.php fällt für eine fehlende Datei auf ein leeres
    // Properties-Objekt zurück, der Kern dagegen auf deDE - geprüft wird
    // hier also der Guard, nicht das Fallback-Verhalten des Kerns.
    function testLoadAdminLanguageFileFaelltBeiUnbekanntemLocaleAufDeDeZurueck(): void {
        $lang = $this->service()->loadAdminLanguageFile($this->pluginSelfDir(), 'frFR');

        $this->assertSame('Einstellungen', $lang->getLanguageValue('admin_button'));
    }

    function testLoadAdminLanguageFileFaelltBeiLocaleMitPfadanteilAufDeDeZurueck(): void {
        $lang = $this->service()->loadAdminLanguageFile($this->pluginSelfDir(), '../../../etc/passwd');

        $this->assertSame('Einstellungen', $lang->getLanguageValue('admin_button'));
    }

    function testLoadCmsLanguageFileFaelltBeiUnbekanntemLocaleAufDeDeZurueck(): void {
        $lang = $this->service()->loadCmsLanguageFile($this->pluginSelfDir(), 'frFR');

        $this->assertSame('Montag', $lang->getLanguageValue('weekday_monday'));
    }

    // Facade-Methode loadAdminLanguage() (index.php): Cache-Guard für
    // $admin_lang (nur beim ersten Aufruf instanziiert) und
    // Seiteneffekt $pluginLang (bei jedem Aufruf neu aus $ADMIN_CONF
    // aufgelöst, kein Cache-Guard). $ADMIN_CONF-Mock in bootstrap.php:
    // 'language' => 'de' -> aufgelöst zu 'deDE'.
    function testLoadAdminLanguageCachtInstanzUndAktualisiertPluginLangBeiJedemAufruf(): void {
        $plugin = new \schemaOrgData();

        $first = callPluginMethod($plugin, 'loadAdminLanguage');
        $second = callPluginMethod($plugin, 'loadAdminLanguage');

        $this->assertSame($first, $second);

        $ref = new \ReflectionProperty(\schemaOrgData::class, 'pluginLang');
        $ref->setAccessible(true);
        $this->assertSame('deDE', $ref->getValue($plugin));
    }
}
