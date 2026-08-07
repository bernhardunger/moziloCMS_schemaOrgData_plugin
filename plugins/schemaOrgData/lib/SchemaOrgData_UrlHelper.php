<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_UrlHelper
*
* Zustandsloser Helfer zur Ableitung der absoluten Basis-URL
* der Installation. Wird von der Fassade schemaOrgData über
* einen Lazy-Accessor verdrahtet (siehe README.md, Abschnitt
* "Organisations-Identität und @id-Anker").
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

    /***************************************************************
    *
    * Liefert das absolute, lokale Basisverzeichnis der moziloCMS-
    * Mediendateien (CONTENT_FILES_DIR_NAME, Core-Konstante) für die
    * serverseitige Existenzprüfung relativer Medienpfade (Personen-
    * Registry, Feld "image", siehe
    * SchemaOrgData_Validator::validatePersonImageExistence()).
    *
    * @return string absolutes Verzeichnis mit abschließendem "/" oder ''
    *                (leer, wenn BASE_DIR/CONTENT_FILES_DIR_NAME nicht
    *                definiert sind - dann wird keine Existenzprüfung
    *                durchgeführt)
    *
    ***************************************************************/
    public function resolveMediaBaseDir(): string {
        if(!defined('BASE_DIR') or !defined('CONTENT_FILES_DIR_NAME')) {
            return '';
        }

        return BASE_DIR.CONTENT_FILES_DIR_NAME.'/';
    }

    /***************************************************************
    *
    * URL-Pendant zu resolveMediaBaseDir(): absolute Basis-URL der
    * moziloCMS-Mediendateien für die Vervollständigung eines relativen
    * Medienpfads zu einer absoluten URL (Personen-Registry, Feld
    * "image", siehe SchemaOrgData_PersonsRegistryService::resolveAbsoluteImageUrl()).
    *
    * @return string absolute Basis-URL mit abschließendem "/" oder ''
    *                (leer, wenn resolveFrontendBaseUrl() leer ist oder
    *                CONTENT_FILES_DIR_NAME nicht definiert ist)
    *
    ***************************************************************/
    public function resolveMediaBaseUrl(): string {
        if(!defined('CONTENT_FILES_DIR_NAME')) {
            return '';
        }

        $baseUrl = $this->resolveFrontendBaseUrl();
        if($baseUrl === '') {
            return '';
        }

        return $baseUrl.CONTENT_FILES_DIR_NAME.'/';
    }

    /***************************************************************
    *
    * Cache-Busting-Query-Parameter für ein ausgeliefertes JS-Asset:
    * liefert filemtime() der tatsächlich auf dem Server liegenden Datei,
    * damit ein Browser nach jedem Deployment (FTP-Upload, ZIP-Install)
    * automatisch die neue Fassung nachlädt statt eine bereits gecachte,
    * veraltete Version weiterzuverwenden - ohne diesen Parameter blieben
    * <script src="...">-Requests ohne jedes Cache-Invalidierungssignal
    * (Root Cause eines RC-Bugs, bei dem eine neue Live-Validierung im
    * Browser nicht ankam, obwohl PHP- und Jest-Tests bereits grün waren).
    * Fallback "0", falls die Datei nicht lesbar ist (z. B. isoliertes
    * Testfixture).
    *
    * Die Methode liegt hier und nicht bei einem der Renderer, weil die
    * Frage "wie alt ist eine ausgelieferte Asset-Datei" weder admin- noch
    * frontend-spezifisch ist und beide Ausgabepfade sie stellen.
    *
    * @param string $pluginSelfDir Basis-Verzeichnis des Plugins (PLUGIN_SELF_DIR)
    * @param string $relativeAssetPath Pfad relativ zu $pluginSelfDir, z. B. "js/validator.js"
    *
    ***************************************************************/
    public function resolveAssetCacheBuster(string $pluginSelfDir, string $relativeAssetPath): string {
        $mtime = @filemtime($pluginSelfDir.$relativeAssetPath);

        return $mtime !== false ? (string) $mtime : '0';
    }
}
