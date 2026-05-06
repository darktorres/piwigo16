<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+


// The "No Photo Yet" feature: if you have no photo yet in your gallery, the
// gallery displays only a big box to show you the way for adding your first
// photos
// In the PSR-15 routing layer all requests go through index.php, so
// script_basename() always returns 'index'. Also check the extracted route path
// so that /ws requests are not blocked by the no_photo_yet splash screen.
$_no_photo_yet_route = \Piwigo\Http\PathExtractor::fromServer($_SERVER);

if (
    !(defined('IN_ADMIN') ? constant('IN_ADMIN') : false)   // no message inside administration
    and script_basename() != 'identification' // keep the ability to login
    and script_basename() != 'password'       // keep the ability to reset password
    and script_basename() != 'ws'             // keep the ability to discuss with web API (legacy script name)
    and !str_starts_with($_no_photo_yet_route, '/ws')        // keep the ability to discuss with web API (routed)
    and script_basename() != 'popuphelp'      // keep the ability to display help popups
    and (is_a_guest() or is_admin())          // normal users are not concerned by no_photo_yet
    and !isset($_SESSION['no_photo_yet'])     // temporary hide
) {
    $nb_photos = ServiceLocator::get(ImageRepository::class)->countAll();
    if (0 == $nb_photos) {
        // make sure we don't use the mobile theme, which is not compatible with
        // the "no photo yet" feature
        $template = new Template(PHPWG_ROOT_PATH.'themes', $user['theme']);
        TemplateRegistry::set($template);

        if (isset($_GET['no_photo_yet'])) {
            if ('browse' == $_GET['no_photo_yet']) {
                $_SESSION['no_photo_yet'] = 'browse';
                redirect(make_index_url());
                exit();
            }

            if ('deactivate' == $_GET['no_photo_yet']) {
                conf_update_param('no_photo_yet', 'false');
                redirect(make_index_url());
                exit();
            }
        }

        header('Content-Type: text/html; charset='.get_pwg_charset());
        $template->set_filenames(['no_photo_yet' => 'no_photo_yet.tpl']);

        if (is_admin()) {
            $url = Config::noPhotoYetUrl();
            if (str_starts_with((string) $url, 'http')) {
                // absolute URL set by admin — use as-is
            } elseif ($url === '' || $url === 'admin.php?page=photos_add') {
                // default or legacy value — use the routed URL
                $url = ServiceLocator::get(UrlGenerator::class)->admin('photos_add');
            } else {
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
                'deactivate_url' => get_root_url().'?no_photo_yet=deactivate',
                ]
            );
        } else {

            $template->assign(
                [
                'step' => 1,
                'U_LOGIN' => ServiceLocator::get(UrlGenerator::class)->identification(),
                'deactivate_url' => get_root_url().'?no_photo_yet=browse',
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
