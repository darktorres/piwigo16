<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\ConfigService;
use Piwigo\Core\AdminContext;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocEndNoPhotoYet;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\Template;

/**
 * The "No Photo Yet" feature: if the gallery has no photo yet, replace
 * the whole page with a big box guiding the visitor/admin to add their
 * first photos. Contains real redirect()/exit() calls, same as
 * Html\HtmlService's own established precedent (see its
 * pageNotFound()-adjacent methods) -- not routed around, since this is
 * the original's real terminal behavior. Tests only exercise the
 * guard-condition-false and nb_photos>0 branches (never reach exit()),
 * same "don't stub what would kill the test process" reasoning as
 * fatal_error().
 *
 * Legacy Coupling Retirement Phase 8, 8d: its no_photo_yet write takes a
 * real constructor-injected ConfigService -- only 2 real construction
 * sites (Bootstrap\RequestBootstrap.php's own `new NoPhotoYetRenderer(...)
 * ->render()` inline call, gated on CurrentConfig::noPhotoYet() === null, plus
 * this class's own test), low enough to thread properly rather than reach
 * for CurrentConfigService::get().
 */
final readonly class NoPhotoYetRenderer
{
    public function __construct(
        private ImageRepository $imageRepository,
        private ConfigService $configService,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly Paths $paths,
        private readonly AdminContext $adminContext,
    ) {}

    public function render(): void
    {
        if (
            ! $this->adminContext->isActive()   // no message inside administration
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'identification' // keep the ability to login
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'password'       // keep the ability to reset password
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws'             // keep the ability to discuss with web API
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'popuphelp'      // keep the ability to display help popups
            and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isAdmin())          // normal users are not concerned by no_photo_yet
            and ! isset($_SESSION['no_photo_yet'])     // temporary hide
        ) {
            $nb_photos = $this->imageRepository->countAllImages();

            if ($nb_photos === 0) {
                // make sure we don't use the mobile theme, which is not compatible with
                // the "no photo yet" feature
                $user_theme = \Piwigo\Users\CurrentUser::get()->theme;
                $user_theme = $user_theme !== '' ? $user_theme : new \Piwigo\Users\UserService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), new HtmlService(), \Piwigo\Db\DbConnection::build())->getDefaultTheme();
                $template = new Template($this->paths->root . 'themes', $user_theme);
                \Piwigo\Template\CurrentTemplate::set($template);

                $noPhotoYetAction = Request\NoPhotoYetRequest::fromGlobals()->action;
                if ($noPhotoYetAction === 'browse') {
                    $_SESSION['no_photo_yet'] = 'browse';
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                if ($noPhotoYetAction === 'deactivate') {
                    $this->configService->confUpdateParam('no_photo_yet', 'false');
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                $template->set_filenames([
                    'no_photo_yet' => 'no_photo_yet.tpl',
                ]);

                if (\Piwigo\Auth\AccessControl::isAdmin()) {
                    $url = \Piwigo\Config\CurrentConfig::noPhotoYetUrl();
                    if (! str_starts_with($url, 'http')) {
                        $url = $this->urlService->getRootUrl() . $url;
                    }

                    $template->assign(
                        [
                            'step' => 2,
                            'intro' => Lang::t(
                                'Hello %s, your Piwigo photo gallery is empty!',
                                \Piwigo\Users\CurrentUser::get()->username
                            ),
                            'next_step_url' => $url,
                            'deactivate_url' => $this->urlService->getRootUrl() . '?no_photo_yet=deactivate',
                        ]
                    );
                } else {
                    $template->assign(
                        [
                            'step' => 1,
                            'U_LOGIN' => 'identification.php',
                            'deactivate_url' => $this->urlService->getRootUrl() . '?no_photo_yet=browse',
                        ]
                    );
                }

                \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocEndNoPhotoYet());

                $template->pparse('no_photo_yet');
                exit();
            } else {
                $this->configService->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
