<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var string $debug
 * @var float $t2
 * @var \Template $template
 * @var array<string, mixed> $page
 */
global $conf, $debug, $t2, $template, $page;

$template->set_filenames([
    'tail' => 'footer.tpl',
]);

trigger_notify('loc_begin_page_tail');

$template->assign(
    [
        'VERSION' => $conf['show_version'] ? PHPWG_VERSION : '',
        'PHPWG_URL' => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
    ]
);

// --------------------------------------------------------------------- contact

if (! is_a_guest()) {
    $template->assign(
        'CONTACT_MAIL',
        get_webmaster_mail_address()
    );
}

// --------------------------------------------------------- update notification
$update_notify_check_period = $conf['update_notify_check_period'];
if (is_int($update_notify_check_period) && $update_notify_check_period > 0) {
    $check_for_updates = false;

    $update_notify_last_check = $conf['update_notify_last_check'] ?? null;
    $update_notify_last_check = is_string($update_notify_last_check) ? $update_notify_last_check : null;

    if ($update_notify_last_check !== null) {
        if (strtotime($update_notify_last_check) < strtotime($update_notify_check_period . ' seconds ago')) {
            $check_for_updates = true;
        }
    } else {
        $check_for_updates = true;
    }

    if ($check_for_updates) {
        $exec_id = pwg_unique_exec_begins('check_for_updates');
        if ($exec_id !== false) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
            include_once PHPWG_ROOT_PATH . 'admin/include/updates.class.php';
            $updates = new updates();
            $updates->notify_piwigo_new_versions();

            pwg_unique_exec_ends('check_for_updates');
        }
    }
}

send_piwigo_infos();

// ------------------------------------------------------------- generation time
$debug_vars = [];

if ($conf['show_queries']) {
    $debug_vars = array_merge($debug_vars, [
        'QUERIES_LIST' => $debug,
    ]);
}

if ($conf['show_gt']) {
    $count_queries = $page['count_queries'] ?? null;
    if (! is_int($count_queries)) {
        $count_queries = 0;
        $page['count_queries'] = 0;
        $page['queries_time'] = 0;
    }

    $queries_time = $page['queries_time'] ?? 0;
    $queries_time = is_numeric($queries_time) ? (float) $queries_time : 0.0;

    $time = get_elapsed_time($t2, get_moment());

    $debug_vars = array_merge(
        $debug_vars,
        [
            'TIME' => $time,
            'NB_QUERIES' => $count_queries,
            'SQL_TIME' => number_format($queries_time, 3, '.', ' ') . ' s',
        ]
    );
}

$template->assign('debug', $debug_vars);

// ------------------------------------------------------------- mobile version
if (! empty($conf['mobile_theme']) && (get_device() != 'desktop' || mobile_theme())) {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $template->assign(
        'TOGGLE_MOBILE_THEME_URL',
        add_url_params(
            htmlspecialchars(is_string($request_uri) ? $request_uri : ''),
            [
                'mobile' => mobile_theme() ? 'false' : 'true',
            ]
        )
    );
}

trigger_notify('loc_end_page_tail');
//
// Generate the page
//
$template->parse('tail');
$template->p();
