<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_UrlHelper
*
* Zustandsloser Helfer zur Ableitung der absoluten Basis-URL
* der Installation. Wird von der Fassade schemaOrgData über
* einen Lazy-Accessor verdrahtet (siehe README.md, Abschnitt
* "@id-Anker und Knotenreferenzen").
*
***************************************************************/
class SchemaOrgData_UrlHelper {

    /***************************************************************
    *
    * Ermittelt die absolute Basis-URL der Installation als Quelle
    * für stabile @id-Anker (siehe README.md, Abschnitt "@id-Anker").
    *
    * Gespiegelt wird das Core-Muster der kanonischen URL ({CANONICAL_LINK}):
    * Protokoll (aus $_SERVER['HTTPS']) + Host (aus $_SERVER['HTTP_HOST'])
    * + Pfad (Verzeichnis von $_SERVER['SCRIPT_NAME']). Es gibt bewusst
    * kein eigenes Domain-Setting; die Host-Kanonisierung (z. B. 301 auf
    * den www-/HTTPS-Host) erfolgt projektseitig per .htaccess.
    *
    * @return string absolute Basis-URL mit abschließendem "/" oder ''
    *                (leer, wenn kein Host ermittelbar ist - dann wird
    *                kein @id gebildet, siehe resolveNodeId())
    *
    ***************************************************************/
    public function resolveBaseUrl(): string {
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        if($host === '') {
            // Ohne Host keine global eindeutige URI - kein @id (siehe README.md).
            return '';
        }

        $protocol = (!empty($_SERVER['HTTPS']) and strtolower((string) $_SERVER['HTTPS']) !== 'off')
            ? 'https://'
            : 'http://';

        // Pfad-Anteil aus dem Verzeichnis von SCRIPT_NAME ableiten. dirname()
        // nutzt unter Windows den Backslash als Trenner - daher das Ergebnis
        // auf "/" normalisieren, damit die @id plattformunabhängig bleibt.
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $dir = $scriptName !== '' ? str_replace('\\', '/', dirname($scriptName)) : '';
        $path = $dir !== '' ? rtrim($dir, '/').'/' : '/';

        return $protocol.$host.$path;
    }
}
