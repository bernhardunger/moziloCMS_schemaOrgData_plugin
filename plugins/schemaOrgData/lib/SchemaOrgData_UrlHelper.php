<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_UrlHelper
*
* Zustandsloser Helfer zur Ableitung der absoluten Basis-URL
* der Installation. Wird von der Fassade schemaOrgData über
* einen Lazy-Accessor verdrahtet (siehe README.md, Abschnitt
* "@id-Anker (stabile Knoten-Identität)").
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

    /***************************************************************
    *
    * Wie resolveBaseUrl(), aber für Admin-seitige @id-Anzeigen
    * (siehe renderField(), Widget "id_reference"): kürzt ein
    * abschließendes ADMIN_DIR_NAME-Pfadsegment, falls vorhanden.
    *
    * Hintergrund: resolveBaseUrl() leitet den Pfad aus SCRIPT_NAME
    * ab. Im PLUGINADMIN-Kontext ist das ".../admin/index.php" -
    * die reine Spiegelung würde daher fälschlich ein "admin/"-Segment
    * in die angezeigte Referenz-URI übernehmen, obwohl die Frontend-
    * Emission (buildJsonLdScript()/resolveNodeId(), unverändert über
    * resolveBaseUrl()) dieses Segment nie enthält.
    *
    * Kein Kern-Konstante liefert die Frontend-Basis-URL direkt
    * (Rechercheergebnis): URL_BASE bildet zwar dasselbe Prinzip im
    * Core nach (Kürzung um ADMIN_DIR_NAME."/index.php" statt nur
    * "index.php"), enthält aber nur den Pfad ohne Protokoll/Host -
    * eine Wiederverwendung würde
    * eine eigene Kern-Konstanten-Abhängigkeit an dieser Stelle nötig
    * machen, die der Rest des Plugins bewusst vermeidet (siehe
    * resolveBaseUrl()-Kommentar zum fehlenden Domain-Setting).
    * Konservative Kürzung ist daher additiv und lokal auf diese
    * Methode begrenzt - resolveBaseUrl() selbst bleibt unverändert.
    *
    * @return string Basis-URL wie resolveBaseUrl(), ohne ein
    *                abschließendes "<ADMIN_DIR_NAME>/"-Segment
    *
    ***************************************************************/
    public function resolveFrontendBaseUrl(): string {
        $baseUrl = $this->resolveBaseUrl();
        if($baseUrl === '' or !defined('ADMIN_DIR_NAME')) {
            return $baseUrl;
        }

        $adminSegment = trim((string) ADMIN_DIR_NAME, '/').'/';
        if($adminSegment !== '/' and str_ends_with($baseUrl, $adminSegment)) {
            $baseUrl = substr($baseUrl, 0, -strlen($adminSegment));
        }

        return $baseUrl;
    }
}
