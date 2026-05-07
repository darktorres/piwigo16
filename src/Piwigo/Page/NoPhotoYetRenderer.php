<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\PathExtractor;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;

final class NoPhotoYetRenderer
{
    public function render(): void
    {
        $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $_no_photo_yet_route = PathExtractor::fromServer($_SERVER);

        if (
            !(defined('IN_ADMIN') ? constant('IN_ADMIN') : false)
            and script_basename() != 'identification'
            and script_basename() != 'password'
            and script_basename() != 'ws'
            and !str_starts_with($_no_photo_yet_route, '/ws')
            and script_basename() != 'popuphelp'
            and (PermissionService::get()->isAGuest() or PermissionService::get()->isAdmin())
            and !isset($_SESSION['no_photo_yet'])
        ) {
            $nb_photos = ServiceLocator::get(ImageRepository::class)->countAll();
            if (0 == $nb_photos) {
                $theme = is_string($user['theme'] ?? null) ? $user['theme'] : '_base';
                $template = new Template(PHPWG_ROOT_PATH . 'themes', $theme);
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

                header('Content-Type: text/html; charset=' . get_pwg_charset());
                $template->set_filenames(['no_photo_yet' => 'no_photo_yet.tpl']);

                if (PermissionService::get()->isAdmin()) {
                    $url = Config::noPhotoYetUrl();
                    if (str_starts_with((string) $url, 'http')) {
                        // absolute URL set by admin — use as-is
                    } elseif ($url === '' || $url === 'admin.php?page=photos_add') {
                        $url = ServiceLocator::get(UrlGenerator::class)->admin('photos_add');
                    } else {
                        $url = get_root_url() . $url;
                    }

                    $template->assign([
                        'step' => 2,
                        'intro' => l10n('Hello %s, your Piwigo photo gallery is empty!', $user['username'] ?? ''),
                        'next_step_url' => $url,
                        'deactivate_url' => get_root_url() . '?no_photo_yet=deactivate',
                    ]);
                } else {
                    $template->assign([
                        'step' => 1,
                        'U_LOGIN' => ServiceLocator::get(UrlGenerator::class)->identification(),
                        'deactivate_url' => get_root_url() . '?no_photo_yet=browse',
                    ]);
                }

                trigger_notify('loc_end_no_photo_yet');
                $template->pparse('no_photo_yet');
                exit();
            } else {
                conf_update_param('no_photo_yet', 'false');
            }
        }
    }
}
