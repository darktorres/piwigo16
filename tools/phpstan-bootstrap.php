<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');
define('PHPWG_VERSION', '16.3.0');
define('PWG_LOCAL_DIR', 'local/');
define('PHPWG_INSTALLED', true);
define('IN_ADMIN', false);

/** @var array<string,mixed> $conf */
$conf = [];
/** @var array<string,mixed> $user */
$user = [];
/** @var array{infos:list<string>,errors:list<string>,warnings:list<string>,messages:list<string>,body_classes:list<string>,body_data:array<string,mixed>} $page */
$page = ['infos' => [], 'errors' => [], 'warnings' => [], 'messages' => [], 'body_classes' => [], 'body_data' => []];
/** @var array<string,string> $lang */
$lang = [];
/** @var \Piwigo\Template\Template|null $template */
$template = null;
/** @var \Piwigo\Core\Logger|null $logger */
$logger = null;
/** @var \mysqli|null $mysqli */
$mysqli = null;

// Stubs for procedural plugin/theme callbacks (defined at runtime by plugin files).
// DummyPlugin_maintain and DummyTheme_maintain delegate to these when a plugin
// defines them as free functions instead of extending PluginMaintain/ThemeMaintain.
if (!function_exists('plugin_install')) {
    function plugin_install(string $plugin_id, string $version, array &$errors = []): mixed { return null; }
    function plugin_activate(string $plugin_id, string $version, array &$errors = []): mixed { return null; }
    function plugin_deactivate(string $plugin_id): mixed { return null; }
    function plugin_uninstall(string $plugin_id): mixed { return null; }
}
if (!function_exists('theme_activate')) {
    function theme_activate(string $theme_id, string $version, array &$errors = []): mixed { return null; }
    function theme_deactivate(string $theme_id): mixed { return null; }
    function theme_delete(string $theme_id): mixed { return null; }
}
