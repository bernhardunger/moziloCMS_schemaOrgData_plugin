<?php

namespace SchemaOrgData\Tests;

use PHPUnit\Framework\TestCase;

/***************************************************************
*
* Tests für SchemaOrgData_UrlHelper::resolveBaseUrl():
*
*   - HTTP_HOST gesetzt  -> protokoll + host + pfad
*   - HTTP_HOST fehlt    -> '' (kein @id möglich)
*   - HTTPS aktiv        -> https://
*   - Kein HTTPS         -> http://
*   - Backslash im Pfad  -> wird zu "/" normalisiert
*
***************************************************************/
final class UrlHelperTest extends TestCase {

    private array $serverBackup = [];

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
    }

    private function helper(): \SchemaOrgData_UrlHelper {
        return new \SchemaOrgData_UrlHelper();
    }

    function testKeinHostGibtLeerenString(): void {
        unset($_SERVER['HTTP_HOST']);

        $result = $this->helper()->resolveBaseUrl();

        $this->assertSame('', $result);
    }

    function testHostMitHttpLiefertHttpUrl(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveBaseUrl();

        $this->assertSame('http://example.com/', $result);
    }

    function testHostMitHttpsLiefertHttpsUrl(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = 'on';

        $result = $this->helper()->resolveBaseUrl();

        $this->assertSame('https://example.com/', $result);
    }

    function testHttpsOffWirdAlsHttpBehandelt(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = 'off';

        $result = $this->helper()->resolveBaseUrl();

        $this->assertStringStartsWith('http://', $result);
    }

    function testPfadAusScriptNameWirdAbgeleitet(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/cms/index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveBaseUrl();

        $this->assertSame('http://example.com/cms/', $result);
    }

    function testBackslashImPfadWirdNormalisiert(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        // dirname() unter Windows kann Backslashes zurückgeben
        $_SERVER['SCRIPT_NAME'] = '\\cms\\index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveBaseUrl();

        $this->assertStringNotContainsString('\\', $result);
        $this->assertStringStartsWith('http://example.com/', $result);
    }

    function testFehlenderScriptNameLiefertWurzelPfad(): void {
        $_SERVER['HTTP_HOST'] = 'example.com';
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTPS']);

        $result = $this->helper()->resolveBaseUrl();

        $this->assertSame('http://example.com/', $result);
    }

    /***************************************************************
    *
    * Tests für SchemaOrgData_UrlHelper::resolveFrontendBaseUrl()
    * (F3: Admin-Anzeige der Referenz-URI zeigte fälschlich den
    * Admin-Pfad, siehe README.md, "@id-Anker und Knotenreferenzen").
    * ADMIN_DIR_NAME kann im Testlauf bereits durch andere Testdateien
    * definiert sein (PHP-Konstanten sind prozessweit einmalig) -
    * daher defined()-Guard analog zu AdminControllerTest.php.
    *
    ***************************************************************/

    function testMitAdminDirNameSegmentWirdGekuerzt(): void {
        if(!defined('ADMIN_DIR_NAME')) {
            define('ADMIN_DIR_NAME', 'admin');
        }

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/stb-hader/admin/index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveFrontendBaseUrl();

        $this->assertSame('http://example.com/stb-hader/', $result);
    }

    function testAdminDirNameAnInstallationsWurzelWirdGekuerzt(): void {
        if(!defined('ADMIN_DIR_NAME')) {
            define('ADMIN_DIR_NAME', 'admin');
        }

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveFrontendBaseUrl();

        $this->assertSame('http://example.com/', $result);
    }

    function testPfadOhneAdminSegmentBleibtUnveraendert(): void {
        if(!defined('ADMIN_DIR_NAME')) {
            define('ADMIN_DIR_NAME', 'admin');
        }

        // Randfall: Pfad endet nicht auf "admin/" (z. B. eine echte
        // Kategorie "administration") - darf nicht fälschlich gekürzt werden.
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/administration/index.php';
        unset($_SERVER['HTTPS']);

        $result = $this->helper()->resolveFrontendBaseUrl();

        $this->assertSame('http://example.com/administration/', $result);
    }
}
