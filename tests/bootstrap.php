<?php

/***************************************************************
*
* PHPUnit-Bootstrap für schemaOrgData
*
* Stellt die moziloCMS-Konstanten und Basisklassen (Plugin,
* Language, Properties) als einfache Mocks bereit, damit
* plugins/schemaOrgData/index.php außerhalb des CMS geladen
* und getestet werden kann.
*
***************************************************************/

// moziloCMS-Konstanten ------------------------------------------------------

define('IS_CMS', true);
define('CHARSET', 'utf-8');
define('PLUGIN_DIR_NAME', 'plugins');
define('URL_BASE', 'http://localhost/');
define('BASE_DIR', dirname(__DIR__).'/');

// Im Test ohne aktive Kategorie/Seite (entspricht der Startseite)
define('CAT_REQUEST', false);
define('PAGE_REQUEST', false);

/***************************************************************
*
* Vereinfachter Ersatz für die moziloCMS-Klasse Properties.
*
* Liest sowohl Sprachdateien (Format "schluessel = wert" je
* Zeile) als auch conf-Dateien (Format "<?php die(); ?>" gefolgt
* von einem serialize()-Array).
*
***************************************************************/
class Properties {

    private array $data = [];

    function __construct(string $file) {
        if(!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);

        if(str_starts_with($content, '<?php')) {
            $content = preg_replace('/^<\?php[^\n]*\n/', '', $content, 1);
            $data = unserialize(trim((string) $content));
            $this->data = is_array($data) ? $data : [];
            return;
        }

        foreach(preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);
            if($line === '' or !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $this->data[trim($key)] = trim($value);
        }
    }

    function get(string $key) {
        return $this->data[$key] ?? null;
    }

    function keyExists(string $key): bool {
        return array_key_exists($key, $this->data);
    }

    function set(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }

    function delete(string $key): void {
        unset($this->data[$key]);
    }

    function toArray(): array {
        return $this->data;
    }
}

/***************************************************************
*
* In-Memory-Ersatz für $this->settings (moziloCMS-Properties-API)
* in Tests, die saveConfig()/deleteConfig() o. ä. isoliert von der
* echten plugin.conf.php ausführen wollen (siehe PersistenceTest,
* JsonLdOutputTest).
*
***************************************************************/
class InMemorySettings {

    private array $data = [];

    function set(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }

    function get(string $key): mixed {
        return $this->data[$key] ?? null;
    }

    function keyExists(string $key): bool {
        return array_key_exists($key, $this->data);
    }

    function delete(string $key): void {
        unset($this->data[$key]);
    }
}

/***************************************************************
*
* Vereinfachter Ersatz für die moziloCMS-Klasse Language.
*
***************************************************************/
class Language {

    private Properties $conf;

    function __construct(string $lang_dir) {
        $this->conf = new Properties($lang_dir);
    }

    function getLanguageValue(string $phrase, string $param1 = '', string $param2 = ''): string {
        $text = (string) ($this->conf->get($phrase) ?? '');
        return str_replace(['{PARAM1}', '{PARAM2}'], [$param1, $param2], $text);
    }

    function getLanguageHtml(string $phrase, string $param1 = '', string $param2 = ''): string {
        $text = $this->getLanguageValue($phrase, $param1, $param2);
        $text = html_entity_decode($text, ENT_QUOTES, CHARSET);
        return htmlentities($text, ENT_COMPAT, CHARSET);
    }
}

/***************************************************************
*
* Vereinfachter Ersatz für die moziloCMS-Klasse Plugin.
*
***************************************************************/
class Plugin {

    public $error = null;
    public $settings;
    public string $PLUGIN_SELF_DIR;
    public string $PLUGIN_SELF_URL;

    function __construct() {
        $plugin_class = get_class($this);
        $this->PLUGIN_SELF_DIR = BASE_DIR.PLUGIN_DIR_NAME.'/'.$plugin_class.'/';
        $this->PLUGIN_SELF_URL = URL_BASE.PLUGIN_DIR_NAME.'/'.$plugin_class.'/';

        $conf_file = $this->PLUGIN_SELF_DIR.'plugin.conf.php';
        if(file_exists($conf_file)) {
            $this->settings = new Properties($conf_file);
        }
    }
}

/***************************************************************
*
* Minimaler Ersatz für die moziloCMS-Konfigurationsobjekte
* $CMS_CONF und $ADMIN_CONF.
*
***************************************************************/
class MockConf {

    function __construct(private array $values) {
    }

    function get(string $key) {
        return $this->values[$key] ?? null;
    }
}

$GLOBALS['CMS_CONF'] = new MockConf(['cmslanguage' => 'de']);
$GLOBALS['ADMIN_CONF'] = new MockConf(['language' => 'de']);

/***************************************************************
*
* Ruft eine private/protected Methode eines Objekts auf.
*
* Wird in den Tests verwendet, um die internen Methoden von
* schemaOrgData zu prüfen, ohne deren Sichtbarkeit zu ändern.
*
***************************************************************/
function callPluginMethod(object $object, string $method, array $args = []) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

require_once BASE_DIR.'plugins/schemaOrgData/index.php';
