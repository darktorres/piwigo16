<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
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

final readonly class NoPhotoYetRenderer
{
    public function __construct(
        private ConfigService $configService,
        private ImageRepository $imageRepository,
        private StringUtil $stringUtil,
        private UrlGenerator $urlGenerator,
        private Util $util,
        private UrlService $urlService,
        private PermissionService $permissionService,
    ) {
    }
    public function render(): void
    {
        $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $_no_photo_yet_route = PathExtractor::fromServer($_SERVER);

        /** @psalm-suppress RedundantCondition — IN_ADMIN is runtime-set; stub value misleads Psalm */
        if (
            !defined('IN_ADMIN')
            and StringUtil::scriptBasename() != 'identification'
            and StringUtil::scriptBasename() != 'password'
            and StringUtil::scriptBasename() != 'ws'
            and !str_starts_with($_no_photo_yet_route, '/ws')
            and StringUtil::scriptBasename() != 'popuphelp'
            and ($this->permissionService->isAGuest() or $this->permissionService->isAdmin())
            and !isset($_SESSION['no_photo_yet'])
        ) {
            $nb_photos = $this->imageRepository->countAll();
            if (0 == $nb_photos) {
                $theme = is_string($user['theme'] ?? null) ? $user['theme'] : '_base';
                $template = new Template(PHPWG_ROOT_PATH . 'themes', $theme);
                TemplateRegistry::set($template);

                if (isset($_GET['no_photo_yet'])) {
                    if ('browse' == $_GET['no_photo_yet']) {
                        $_SESSION['no_photo_yet'] = 'browse';
                        $this->util->redirect($this->urlService->makeIndexUrl());
                        exit();
                    }

                    if ('deactivate' == $_GET['no_photo_yet']) {
                        $this->configService->confUpdateParam('no_photo_yet', 'false');
                        $this->util->redirect($this->urlService->makeIndexUrl());
                        exit();
                    }
                }

                header('Content-Type: text/html; charset=' . $this->stringUtil->getPwgCharset());

                if ($this->permissionService->isAdmin()) {
                    $url = Config::noPhotoYetUrl();
                    if (str_starts_with($url, 'http')) {
                        // absolute URL set by admin — use as-is
                    } elseif ($url === '' || $url === 'admin.php?page=photos_add') {
                        $url = $this->urlGenerator->admin('photos_add');
                    } else {
                        $url = UrlService::getRootUrl() . $url;
                    }

                    $template->assign([
                        'step' => 2,
                        'intro' => Lang::t('Hello %s, your Piwigo photo gallery is empty!', is_string($user['username'] ?? null) ? $user['username'] : ''),
                        'next_step_url' => $url,
                        'deactivate_url' => UrlService::getRootUrl() . '?no_photo_yet=deactivate',
                    ]);
                } else {
                    $template->assign([
                        'step' => 1,
                        'U_LOGIN' => $this->urlGenerator->identification(),
                        'deactivate_url' => UrlService::getRootUrl() . '?no_photo_yet=browse',
                    ]);
                }

                EventDispatcher::notify('loc_end_no_photo_yet');
                $template->pparse('no_photo_yet.latte');
                exit();
            } else {
                $this->configService->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
