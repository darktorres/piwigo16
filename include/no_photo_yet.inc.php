<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
global $conf, $user;

// The "No Photo Yet" feature: if you have no photo yet in your gallery, the
// gallery displays only a big box to show you the way for adding your first
// photos
if (
    ! (defined('IN_ADMIN') and IN_ADMIN)   // no message inside administration
    and script_basename() != 'identification' // keep the ability to login
    and script_basename() != 'password'       // keep the ability to reset password
    and script_basename() != 'ws'             // keep the ability to discuss with web API
    and script_basename() != 'popuphelp'      // keep the ability to display help popups
    and (is_a_guest() or is_admin())          // normal users are not concerned by no_photo_yet
    and ! isset($_SESSION['no_photo_yet'])     // temporary hide
) {
    $query = '
SELECT
    COUNT(*)
  FROM ' . IMAGES_TABLE . '
;';
    [$nb_photos] = pwg_db_fetch_row(pwg_query($query));
    if ($nb_photos == 0) {
        // make sure we don't use the mobile theme, which is not compatible with
        // the "no photo yet" feature
        $template = new Template(PHPWG_ROOT_PATH . 'themes', $user['theme']);

        if (isset($_GET['no_photo_yet'])) {
            if ($_GET['no_photo_yet'] == 'browse') {
                $_SESSION['no_photo_yet'] = 'browse';
                redirect(make_index_url());
            }

            if ($_GET['no_photo_yet'] == 'deactivate') {
                conf_update_param('no_photo_yet', 'false');
                redirect(make_index_url());
            }
        }

        header('Content-Type: text/html; charset=' . get_pwg_charset());
        $template->set_filenames([
            'no_photo_yet' => 'no_photo_yet.tpl',
        ]);

        if (is_admin()) {
            $url = $conf['no_photo_yet_url'];
            if (! str_starts_with((string) $url, 'http')) {
                $url = get_root_url() . $url;
            }

            $template->assign(
                [
                    'step' => 2,
                    'intro' => l10n(
                        'Hello %s, your Piwigo photo gallery is empty!',
                        $user['username']
                    ),
                    'next_step_url' => $url,
                    'deactivate_url' => get_root_url() . '?no_photo_yet=deactivate',
                ]
            );
        } else {

            $template->assign(
                [
                    'step' => 1,
                    'U_LOGIN' => 'identification.php',
                    'deactivate_url' => get_root_url() . '?no_photo_yet=browse',
                ]
            );
        }

        trigger_notify('loc_end_no_photo_yet');

        $template->pparse('no_photo_yet');
        exit();
    } else {
        conf_update_param('no_photo_yet', 'false');
    }
}
