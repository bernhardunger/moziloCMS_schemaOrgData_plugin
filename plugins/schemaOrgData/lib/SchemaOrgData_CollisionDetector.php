<?php if(!defined('IS_CMS')) die();

/***************************************************************
*
* SchemaOrgData_CollisionDetector
*
* Kapselt die Erkennung fremder <script type="application/
* ld+json">-Blöcke (siehe README.md, Abschnitt "Vorhandenes
* JSON-LD und Import"). Nicht Teil dieser Komponente:
* detectTypeCollision() (derselbe Schema-Type auf mehreren
* Scopes) - das bleibt im SchemaOrgData_ScopeResolver (Schritt 4).
*
* Zustandslos - $TEMPLATE_FILE und $CMS_CONF werden je Aufruf als
* Parameter übergeben statt aus globalem Scope gelesen, analog zur
* Linie aus den Schritten 4-6. BASE_DIR/LAYOUT_DIR_NAME sind echte
* PHP-Konstanten und bleiben direkt referenziert.
*
***************************************************************/
class SchemaOrgData_CollisionDetector {

    /** Rückgabewerte von detectPluginPlaceholderInTemplateAdmin() */
    public const PLACEHOLDER_OK = 'ok';
    public const PLACEHOLDER_MISSING = 'missing';
    public const PLACEHOLDER_OUTSIDE_HEAD = 'outside_head';

    /***************************************************************
    *
    * Extrahiert alle JSON-LD-Blöcke aus einem HTML-String und gibt
    * deren innere JSON-Texte zurück (ohne <script>-Tags).
    *
    * Gemeinsame Hilfsfunktion für detectExistingJsonLd*()-Methoden
    * sowie für die Inhaltsspeicherung (existing_jsonld_content,
    * siehe loadScopeMeta()/saveScopeMeta()).
    * Kein Absturz bei malformed HTML — preg_match_all gibt im
    * Fehlerfall false zurück, was als leeres Array behandelt wird.
    *
    * @param string $html zu prüfendes HTML
    * @return string[] innere JSON-Texte aller gefundenen Blöcke
    *
    ***************************************************************/
    public function extractExistingJsonLdBlocks(string $html): array {
        $result = preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        );
        return ($result !== false and !empty($matches[1])) ? $matches[1] : [];
    }

    /***************************************************************
    *
    * Prüft, ob im gerenderten HTML der Seite bereits ein
    * <script type="application/ld+json">-Block vorhanden ist
    * (kombinierte Prüfung: Inhalt + Template).
    *
    * Hinweis: Für die produktive Scope-Zuordnung in getContent()
    * wird diese kombinierte Methode nicht mehr verwendet — stattdessen
    * prüfen dort extractExistingJsonLdBlocksFromTemplate() (→ Global-Scope)
    * und extractExistingJsonLdBlocks($value) (→ Seiten-Scope) getrennt.
    * Diese Methode bleibt erhalten, da CollisionDetectorTest.php das
    * kombinierte Verhalten gezielt testet.
    *
    * @param string $html zu prüfendes HTML
    * @param string $templateFile Pfad zur aktiv geladenen Template-Datei
    *                              (entspricht $TEMPLATE_FILE)
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    public function detectExistingJsonLd(string $html, string $templateFile): bool {
        if(!empty($this->extractExistingJsonLdBlocks($html))) {
            return true;
        }
        return $this->detectExistingJsonLdInTemplate($templateFile);
    }

    /***************************************************************
    *
    * Liest das aktiv geladene Website-Template vom Dateisystem und
    * gibt alle darin enthaltenen JSON-LD-Blöcke zurück.
    *
    * $templateFile zeigt zu getContent()-Zeit bereits auf die
    * korrekte Datei (template.html oder gallerytemplate.html).
    *
    * @param string $templateFile Pfad zur aktiv geladenen Template-Datei
    *                              (entspricht $TEMPLATE_FILE)
    * @return string[] innere JSON-Texte aller gefundenen Blöcke (leer = keiner)
    *
    ***************************************************************/
    public function extractExistingJsonLdBlocksFromTemplate(string $templateFile): array {
        if(empty($templateFile) or !file_exists($templateFile)) {
            return [];
        }
        $content = file_get_contents($templateFile);
        return $content !== false ? $this->extractExistingJsonLdBlocks($content) : [];
    }

    /***************************************************************
    *
    * Prüft, ob das aktiv geladene Website-Template einen
    * <script type="application/ld+json">-Block enthält.
    *
    * @param string $templateFile Pfad zur aktiv geladenen Template-Datei
    *                              (entspricht $TEMPLATE_FILE)
    * @return bool true, wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    public function detectExistingJsonLdInTemplate(string $templateFile): bool {
        return !empty($this->extractExistingJsonLdBlocksFromTemplate($templateFile));
    }

    /***************************************************************
    *
    * Liest im Admin-Kontext das aktiv ausgelieferte Layout-Template
    * (template.html) vom Dateisystem und gibt alle darin enthaltenen
    * JSON-LD-Blöcke zurück.
    *
    * Der Template-Pfad wird aus $cmsConf->get('cmslayout') abgeleitet.
    * Bei aktivem Draftmode wird zusätzlich das Draftlayout geprüft
    * (mirrors moziloCMS-Frontend-index.php:87–91). Inaktive Layouts
    * werden bewusst NICHT geprüft (False-Positive-Schutz).
    *
    * Geprüft wird ausschließlich template.html je ermitteltem Layout
    * (Pfadbildung analog admin/template.php:46 / admin/editsite.php:346) -
    * gallerytemplate.html rendert strukturell nie echten Seiteninhalt
    * (eigenständiges Voll-Layout für die Galerie-Vollansicht ohne
    * {CONTENT}-Platzhalter), ein dort gefundener Block ist für die
    * reale Seiten-/Kategorie-Ausgabe irrelevant und würde sonst
    * fälschlich mit dem realen Block aus template.html konkateniert.
    * Defensiv: $cmsConf / LAYOUT_DIR_NAME / BASE_DIR auf Verfügbarkeit
    * geprüft, file_exists/Lesbarkeit abgesichert.
    *
    * @param mixed $cmsConf moziloCMS-Konfigurationsobjekt (entspricht
    *                       $CMS_CONF), bewusst kein Type-Hint (analog
    *                       $settings in SchemaOrgData_ScopeResolver) —
    *                       kompatibel zu realem Objekt und Test-Mocks
    * @return string[] innere JSON-Texte aller gefundenen Blöcke (leer = keiner)
    *
    ***************************************************************/
    public function extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf): array {
        if (!defined('BASE_DIR') || !defined('LAYOUT_DIR_NAME')
            || !isset($cmsConf) || !is_object($cmsConf)) {
            return [];
        }

        $activeLayout = (string) ($cmsConf->get('cmslayout') ?? '');
        if ($activeLayout === '' || $activeLayout === 'false') {
            return [];
        }

        // Immer das aktive Layout prüfen; Draftlayout zusätzlich nur
        // wenn Draftmode aktiviert und Draftlayout gültig gesetzt ist.
        $layoutsToCheck = [$activeLayout];

        if ($cmsConf->get('draftmode') === 'true') {
            $draftLayout = (string) ($cmsConf->get('draftlayout') ?? '');
            if ($draftLayout !== '' && $draftLayout !== 'false') {
                $layoutsToCheck[] = $draftLayout;
            }
        }

        $allBlocks = [];
        foreach ($layoutsToCheck as $layout) {
            $path = BASE_DIR . LAYOUT_DIR_NAME . '/' . $layout . '/template.html';
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content !== false) {
                $allBlocks = array_merge($allBlocks, $this->extractExistingJsonLdBlocks($content));
            }
        }

        return $allBlocks;
    }

    /***************************************************************
    *
    * Prüft im Admin-Kontext, ob das aktiv ausgelieferte Layout-Template
    * einen <script type="application/ld+json">-Block enthält.
    *
    * @param mixed $cmsConf moziloCMS-Konfigurationsobjekt (entspricht $CMS_CONF)
    * @return bool true wenn mindestens ein JSON-LD-Block gefunden wurde
    *
    ***************************************************************/
    public function detectExistingJsonLdInTemplateAdmin($cmsConf): bool {
        return !empty($this->extractExistingJsonLdBlocksFromTemplateAdmin($cmsConf));
    }

    /***************************************************************
    *
    * Prüft im Admin-Kontext, ob das aktiv ausgelieferte Layout-Template
    * (template.html) den Plugin-Platzhalter ({$pluginName} oder
    * {$pluginName|param}) enthält, und - falls ja - ob er innerhalb
    * von <head> steht. Fehlt der Platzhalter, ruft der Kern
    * getContent() des Plugins im Frontend nirgends auf - das Plugin
    * bleibt dann unabhängig von seiner Konfiguration wirkungslos
    * (siehe README.md).
    *
    * Geprüft wird ausschließlich template.html (siehe
    * extractExistingJsonLdBlocksFromTemplateAdmin() für die Begründung,
    * warum gallerytemplate.html hier bewusst nicht mitzählt). Layout-
    * Auflösung ansonsten analog extractExistingJsonLdBlocksFromTemplateAdmin()
    * (bewusst dupliziert statt über einen gemeinsamen privaten Helper
    * extrahiert, siehe README.md).
    *
    * Fail-safe statt Fail-loud: Kann die Prüfung mangels Grundlage nicht
    * durchgeführt werden (BASE_DIR/LAYOUT_DIR_NAME undefiniert, $cmsConf
    * fehlt/kein Objekt, cmslayout leer/'false'), liefert die Methode
    * PLACEHOLDER_OK (= Platzhalter gilt als vorhanden, kein Fehlalarm
    * auf Basis unklarer Umgebung).
    *
    * @param mixed $cmsConf moziloCMS-Konfigurationsobjekt (entspricht
    *                       $CMS_CONF), bewusst kein Type-Hint (siehe
    *                       extractExistingJsonLdBlocksFromTemplateAdmin())
    * @param string $pluginName Klassenname/Platzhaltername des Plugins
    *                           (z. B. "schemaOrgData")
    * @return string eine der PLACEHOLDER_*-Konstanten dieser Klasse:
    *                PLACEHOLDER_OK (gefunden innerhalb <head>, oder
    *                Prüfung mangels Grundlage nicht durchführbar),
    *                PLACEHOLDER_OUTSIDE_HEAD (gefunden, aber hinter
    *                </head>) oder PLACEHOLDER_MISSING (nicht gefunden)
    *
    ***************************************************************/
    public function detectPluginPlaceholderInTemplateAdmin($cmsConf, string $pluginName): string {
        if (!defined('BASE_DIR') || !defined('LAYOUT_DIR_NAME')
            || !isset($cmsConf) || !is_object($cmsConf)) {
            return self::PLACEHOLDER_OK;
        }

        $activeLayout = (string) ($cmsConf->get('cmslayout') ?? '');
        if ($activeLayout === '' || $activeLayout === 'false') {
            return self::PLACEHOLDER_OK;
        }

        $layoutsToCheck = [$activeLayout];

        if ($cmsConf->get('draftmode') === 'true') {
            $draftLayout = (string) ($cmsConf->get('draftlayout') ?? '');
            if ($draftLayout !== '' && $draftLayout !== 'false') {
                $layoutsToCheck[] = $draftLayout;
            }
        }

        $placeholderPattern = '/\{'.preg_quote($pluginName, '/').'(\|[^\[\]\{\}]*)?\}/';

        foreach ($layoutsToCheck as $layout) {
            $path = BASE_DIR . LAYOUT_DIR_NAME . '/' . $layout . '/template.html';
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }
            if (preg_match($placeholderPattern, $content, $match, PREG_OFFSET_CAPTURE) === 1) {
                $matchPos = $match[0][1];
                $headEndPos = strpos($content, '</head>');

                return ($headEndPos !== false && $matchPos > $headEndPos)
                    ? self::PLACEHOLDER_OUTSIDE_HEAD
                    : self::PLACEHOLDER_OK;
            }
        }

        return self::PLACEHOLDER_MISSING;
    }
}
