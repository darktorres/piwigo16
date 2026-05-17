<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Location\LocEndNoPhotoYet;
use Piwigo\Http\PathExtractor;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class NoPhotoYetRenderer
{
    public function __construct(
        private ConfigService $configService,
        private ImageRepository $imageRepository,
        private UrlGenerator $urlGenerator,
        private RedirectResponder $redirectResponder,
        private UrlService $urlService,
        private PermissionService $permissionService,
        private EventDispatcherInterface $dispatcher,
        private Paths $paths,
    ) {
    }
    public function render(): void
    {
        $user = CurrentUser::get()->rawAttributes;
        $_no_photo_yet_route = PathExtractor::fromServer($_SERVER);

        if (
            RequestContextRegistry::current() !== RequestContext::Admin
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
                $template = new Template($this->paths->root . 'themes', $theme);
                TemplateRegistry::set($template);

                if (isset($_GET['no_photo_yet'])) {
                    if ('browse' == $_GET['no_photo_yet']) {
                        $_SESSION['no_photo_yet'] = 'browse';
                        $this->redirectResponder->redirect($this->urlService->makeIndexUrl());
                        exit();
                    }

                    if ('deactivate' == $_GET['no_photo_yet']) {
                        $this->configService->confUpdateParam('no_photo_yet', 'false');
                        $this->redirectResponder->redirect($this->urlService->makeIndexUrl());
                        exit();
                    }
                }

                header('Content-Type: text/html; charset=' . StringUtil::getPwgCharset());

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

                $this->dispatcher->dispatch(new LocEndNoPhotoYet());
                $template->pparse('no_photo_yet.latte');
                exit();
            } else {
                $this->configService->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
