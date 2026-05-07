<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');
// PHPWG_VERSION migrated to AppInfo::VERSION — no PHP define needed.
define('PWG_LOCAL_DIR', 'local/');
define('IN_ADMIN', false);

// PREFIX_TABLE is defined at runtime by UpgradeController / UpgradeFeedController (upgrade path only).
// Declared here as a PHPStan placeholder so migration-step analysis resolves it.
define('PREFIX_TABLE', 'piwigo_');

/** @var string $prefixeTable */
$prefixeTable = 'piwigo_';
/** @var array{id:int,username:string,email:string,language:string,theme:string,status:string,enabled_high:bool,internal_status:array<string,mixed>,cache_update_time:int,last_visit:string,...} $user */
$user = ['id' => 0, 'username' => '', 'email' => '', 'language' => 'en_UK', 'theme' => 'modus', 'status' => 'guest', 'enabled_high' => false, 'internal_status' => [], 'cache_update_time' => 0, 'last_visit' => ''];
/** @var array<string,mixed> $page */
$page = ['infos' => [], 'errors' => [], 'warnings' => [], 'messages' => [], 'body_classes' => [], 'body_data' => []];
/** @var array<string,string> $lang */
$lang = [];
/** @var \Piwigo\Template\Template|null $template */
$template = null;
/** @var \Psr\Log\LoggerInterface|null $logger */
$logger = null;
/** @var array<string,mixed> $filter */
$filter = [];
/** @var array<string,mixed> $pwg_event_handlers */
$pwg_event_handlers = [];
/** @var array<string,mixed> $pwg_loaded_plugins */
$pwg_loaded_plugins = [];
/** @var \Piwigo\Ws\PwgServer|null $service */
$service = null;
/** @var array<string,mixed> $persistent_cache */
$persistent_cache = [];

// Runtime constants from CommonBootstrap::run() — placeholder values for static analysis.
if (!defined('PHPWG_DOMAIN')) {
    define('PHPWG_DOMAIN', 'piwigo.org');
    define('PHPWG_URL', 'https://piwigo.org');
    define('PEM_URL', 'https://piwigo.org/ext');
}

// Admin URL constants — defined at runtime by PhotoController; declared here for static analysis.
if (!defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', 'index.php?/admin&page=photos_add');
}

// WS constants — defined by PwgServer::boot() at runtime; declared here for static analysis.
if (!defined('WS_PARAM_OPTIONAL')) {
    define('WS_PARAM_ACCEPT_ARRAY', 0x010000);
    define('WS_PARAM_FORCE_ARRAY', 0x030000);
    define('WS_PARAM_OPTIONAL', 0x040000);
    define('WS_TYPE_BOOL', 0x01);
    define('WS_TYPE_INT', 0x02);
    define('WS_TYPE_FLOAT', 0x04);
    define('WS_TYPE_POSITIVE', 0x10);
    define('WS_TYPE_NOTNULL', 0x20);
    define('WS_TYPE_ID', WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);
    define('WS_ERR_INVALID_METHOD', 501);
    define('WS_ERR_MISSING_PARAM', 1002);
    define('WS_ERR_INVALID_PARAM', 1003);
    define('WS_XML_ATTRIBUTES', 'attributes_xml_');
}

// Stubs for procedural plugin/theme callbacks (defined at runtime by plugin files).
// These allow PHPStan to know the functions exist without is_callable() checks.
if (!function_exists('plugin_install')) {
    function plugin_install(string $plugin_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function plugin_activate(string $plugin_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function plugin_deactivate(string $plugin_id): mixed
    {
        return null;
    }
    function plugin_uninstall(string $plugin_id): mixed
    {
        return null;
    }
}
if (!function_exists('theme_activate')) {
    function theme_activate(string $theme_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function theme_deactivate(string $theme_id): mixed
    {
        return null;
    }
    function theme_delete(string $theme_id): mixed
    {
        return null;
    }
}
