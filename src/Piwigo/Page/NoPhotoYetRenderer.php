<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Http\PathExtractor;
use Piwigo\Image\ImageRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

final class NoPhotoYetRenderer
{
    public function render(): void
    {
        $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $_no_photo_yet_route = PathExtractor::fromServer($_SERVER);

        if (
            !(defined('IN_ADMIN') ? constant('IN_ADMIN') : false)
            and StringUtil::scriptBasename() != 'identification'
            and StringUtil::scriptBasename() != 'password'
            and StringUtil::scriptBasename() != 'ws'
            and !str_starts_with($_no_photo_yet_route, '/ws')
            and StringUtil::scriptBasename() != 'popuphelp'
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
                        Util::get()->redirect(UrlService::get()->makeIndexUrl());
                        exit();
                    }

                    if ('deactivate' == $_GET['no_photo_yet']) {
                        ServiceLocator::get(ConfigService::class)->confUpdateParam('no_photo_yet', 'false');
                        Util::get()->redirect(UrlService::get()->makeIndexUrl());
                        exit();
                    }
                }

                header('Content-Type: text/html; charset=' . ServiceLocator::get(StringUtil::class)->getPwgCharset());
                $template->setFilenames(['no_photo_yet' => 'no_photo_yet.tpl']);

                if (PermissionService::get()->isAdmin()) {
                    $url = Config::noPhotoYetUrl();
                    if (str_starts_with((string) $url, 'http')) {
                        // absolute URL set by admin — use as-is
                    } elseif ($url === '' || $url === 'admin.php?page=photos_add') {
                        $url = ServiceLocator::get(UrlGenerator::class)->admin('photos_add');
                    } else {
                        $url = UrlService::getRootUrl() . $url;
                    }

                    $template->assign([
                        'step' => 2,
                        'intro' => Lang::t('Hello %s, your Piwigo photo gallery is empty!', $user['username'] ?? ''),
                        'next_step_url' => $url,
                        'deactivate_url' => UrlService::getRootUrl() . '?no_photo_yet=deactivate',
                    ]);
                } else {
                    $template->assign([
                        'step' => 1,
                        'U_LOGIN' => ServiceLocator::get(UrlGenerator::class)->identification(),
                        'deactivate_url' => UrlService::getRootUrl() . '?no_photo_yet=browse',
                    ]);
                }

                EventDispatcher::notify('loc_end_no_photo_yet');
                $template->pparse('no_photo_yet');
                exit();
            } else {
                ServiceLocator::get(ConfigService::class)->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
