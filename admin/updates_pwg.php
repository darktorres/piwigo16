<?php

declare(strict_types=1);

use Piwigo\Admin\updates;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!\Piwigo\Core\Config::enableCoreUpdate()) {
    die('Piwigo core update system is disabled');
}

include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');

/*
STEP:
0 = check is needed. If version is latest or check fail, we stay on step 0
1 = new version on same branch AND new branch are available => user may choose upgrade.
2 = upgrade on same branch
3 = upgrade on different branch
*/
$step = is_numeric($_GET['step'] ?? null) ? (int) $_GET['step'] : 0;

[$ct_env, $ct_build_version] = get_container_info();

if ('Official' === $ct_env) {
    $template->assign([
      'CONTAINER_VERSION' => $ct_build_version,
      'DOCKER_UPDATE_GUIDE_URL' => PHPWG_URL . '/guide-update-docker',
    ]);
    // Remove optional ? on [a-z]? since it will only be available on piwigo 16.3
    // Docker images started to use letter suffix in 16.2
    check_input_parameter('to', $_GET, false, '/^\d+\.\d+\.\d+[a-z]?$/');
    $upgrade_to_raw = isset($_GET['to']) ? (is_scalar($_GET['to']) ? (string) $_GET['to'] : '') : '';
    $upgrade_to = $upgrade_to_raw !== '' ? (string) preg_replace('/[a-z]$/', '', $upgrade_to_raw) : '';
} else {
    check_input_parameter('to', $_GET, false, '/^\d+\.\d+\.\d+$/');
    $upgrade_to = isset($_GET['to']) ? (is_scalar($_GET['to']) ? (string) $_GET['to'] : '') : '';
}

$updates = new updates();
$new_versions = $updates->get_piwigo_new_versions();

// +-----------------------------------------------------------------------+
// |                                Step 0                                 |
// +-----------------------------------------------------------------------+
if ($step == 0) {
    if (isset($new_versions['minor']) and isset($new_versions['major'])) {
        $step = 1;
        $upgrade_to = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : '';
    } elseif (isset($new_versions['minor'])) {
        $step = 2;
        $upgrade_to = is_scalar($new_versions['minor']) ? (string) $new_versions['minor'] : '';
    } elseif (isset($new_versions['major'])) {
        $step = 3;
        $upgrade_to = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : '';
    }

    $template->assign('CHECK_VERSION', $new_versions['piwigo.org-checked']);
    $template->assign('DEV_VERSION', $new_versions['is_dev']);
}

// +-----------------------------------------------------------------------+
// |                                Step 1                                 |
// +-----------------------------------------------------------------------+
if ($step == 1) {
    // nothing to do here
}

// +-----------------------------------------------------------------------+
// |                                Step 2                                 |
// +-----------------------------------------------------------------------+
if ($step == 2 and is_webmaster()) {
    if (isset($_POST['submit']) and isset($_POST['upgrade_to'])) {
        $post_upgrade_to = is_scalar($_POST['upgrade_to']) ? (string) $_POST['upgrade_to'] : '';
        updates::upgrade_to($post_upgrade_to, $step);
    }
}

// +-----------------------------------------------------------------------+
// |                                Step 3                                 |
// +-----------------------------------------------------------------------+
if ($step == 3 and is_webmaster()) {
    if (isset($_POST['submit']) and isset($_POST['upgrade_to'])) {
        $post_upgrade_to3 = is_scalar($_POST['upgrade_to']) ? (string) $_POST['upgrade_to'] : '';
        updates::upgrade_to($post_upgrade_to3, $step);
    }

    $updates->get_merged_extensions($upgrade_to);
    $updates->get_server_extensions($upgrade_to);
    $template->assign('missing', $updates->missing);
}

// +-----------------------------------------------------------------------+
// | Check for requirements                                                |
// +-----------------------------------------------------------------------+

if (isset($new_versions['minor_php']) and version_compare(phpversion(), is_scalar($new_versions['minor_php']) ? (string) $new_versions['minor_php'] : '', '<')) {
    $template->assign('MINOR_RELEASE_PHP_REQUIRED', $new_versions['minor_php']);
}

if (isset($new_versions['major_php']) and version_compare(phpversion(), is_scalar($new_versions['major_php']) ? (string) $new_versions['major_php'] : '', '<')) {
    $template->assign('MAJOR_RELEASE_PHP_REQUIRED', $new_versions['major_php']);
}

// +-----------------------------------------------------------------------+
// |                        Process template                               |
// +-----------------------------------------------------------------------+

if (!is_webmaster()) {
    \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

$template->assign(
    [
  'STEP'          => $step,
  'PIWIGO_CURRENT_VERSION' => $page['updated_version'] ?? PHPWG_VERSION,
  'UPGRADE_TO'    => $upgrade_to,
  ]
);

if (isset($new_versions['minor'])) {
    $minor_ver = is_scalar($new_versions['minor']) ? (string) $new_versions['minor'] : '';
    $template->assign(
        [
        'MINOR_VERSION' => $minor_ver,
        'MINOR_RELEASE_URL' => (
            ('Official' === $ct_env)
        ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $minor_ver)
        : PHPWG_URL . '/releases/' . $minor_ver
        ),
    ]
    );
}

if (isset($new_versions['major'])) {
    $major_ver = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : '';
    $template->assign(
        [
        'MAJOR_VERSION' => $major_ver,
        'MAJOR_RELEASE_URL' =>   PHPWG_URL . '/releases/' .
          (('Official' === $ct_env) ? substr($major_ver, 0, -1) : $major_ver),
        'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $major_ver),
        'MAJOR_VERSION_PWG' => preg_replace('/[a-z]$/', '', $major_ver), // Remove container build ver
  ]
    );
}

$template->assign('ADMIN_PAGE_TITLE', l10n('Updates'));
$template->assign('page_data_json', json_encode([
    'str_are_you_sure' => l10n('Are you sure?'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
$template->set_filename('plugin_admin_content', 'updates_pwg.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
