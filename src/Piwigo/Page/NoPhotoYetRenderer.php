<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocEndNoPhotoYet;
use Piwigo\Image\ImageRepository;
use Piwigo\Page\Projection\NoPhotoYetAdminPageContext;
use Piwigo\Page\Projection\NoPhotoYetGuestPageContext;
use Piwigo\Page\Request\NoPhotoYetRequest;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;

/**
 * The "No Photo Yet" feature: if the gallery has no photos yet, replaces
 * the whole page with a big box guiding the visitor/admin to add their
 * first photos. Contains real redirect()/exit() calls -- this is
 * terminal behavior, not routed around. Tests only exercise the
 * guard-condition-false and nb_photos>0 branches, since exit() would
 * kill the test process.
 *
 * ConfigService is constructor-injected directly rather than resolved
 * via CurrentConfigService::get(), since this class is only ever
 * constructed at two real call sites (Bootstrap\RequestBootstrap.php's
 * own `new NoPhotoYetRenderer(...)->render()` inline call, gated on
 * CurrentConfig::noPhotoYet() === null, plus this class's own test).
 */
final readonly class NoPhotoYetRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessLevelChecker $accessLevelChecker,
        private ImageRepository $imageRepository,
        private ConfigService $configService,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly Paths $paths,
        private readonly AdminContext $adminContext,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
        private readonly PageState $pageState,
        private readonly ErrorCollector $errorCollector,
        private readonly ProcessCache $processCache,
        private readonly CurrentConfigService $currentConfigService,
    ) {}

    public function render(): void
    {
        if (
            ! $this->adminContext->isActive()   // no message inside administration
            and PageFilterHelper::scriptBasename($this->currentConfig) !== 'identification' // keep the ability to login
            and PageFilterHelper::scriptBasename($this->currentConfig) !== 'password'       // keep the ability to reset password
            and PageFilterHelper::scriptBasename($this->currentConfig) !== 'ws'             // keep the ability to discuss with web API
            and PageFilterHelper::scriptBasename($this->currentConfig) !== 'popuphelp'      // keep the ability to display help popups
            and ($this->accessLevelChecker->isAGuest() or $this->accessLevelChecker->isAdmin())          // normal users are not concerned by no_photo_yet
            and ! isset($_SESSION['no_photo_yet'])     // temporary hide
        ) {
            $nb_photos = $this->imageRepository->countAllImages();

            if ($nb_photos === 0) {
                // make sure we don't use the mobile theme, which is not compatible with
                // the "no photo yet" feature
                //
                // User::$theme is ThemeId-typed and can never be empty (its
                // constructor guarantees a real value, falling back to
                // AppInfo::DEFAULT_TEMPLATE), so a getDefaultTheme() fallback
                // for an empty theme here would be unreachable -- that
                // fallback only ever checked for emptiness too, never
                // filesystem installation.
                $user_theme = $this->currentUser->get()
                    ->theme->value;
                $template = new Template($this->currentConfig, $this->lang, $this->adminContext, $this->eventDispatcher, $this->pageState, $this->errorCollector, $this->processCache, $this->currentConfigService, $this->paths, $this->accessLevelChecker, $this->sessionService, $this->paths->root . 'themes', $user_theme);
                $this->currentTemplate->set($template);

                $noPhotoYetAction = NoPhotoYetRequest::fromGlobals()->action;
                if ($noPhotoYetAction === 'browse') {
                    $_SESSION['no_photo_yet'] = 'browse';
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                if ($noPhotoYetAction === 'deactivate') {
                    $this->configService->confUpdateParam('no_photo_yet', 'false');
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                header('Content-Type: text/html; charset=utf-8');
                $template->set_filenames([
                    'no_photo_yet' => 'no_photo_yet.tpl',
                ]);

                if ($this->accessLevelChecker->isAdmin()) {
                    $url = $this->currentConfig->noPhotoYetUrl;
                    if (! str_starts_with($url, 'http')) {
                        $url = $this->urlService->getRootUrl() . $url;
                    }

                    $template->assignContext(new NoPhotoYetAdminPageContext(
                        step: 2,
                        intro: $this->lang->t(
                            'Hello %s, your Piwigo photo gallery is empty!',
                            $this->currentUser->get()
                                ->username
                        ),
                        nextStepUrl: $url,
                        deactivateUrl: $this->urlService->getRootUrl() . '?no_photo_yet=deactivate',
                    ));
                } else {
                    $template->assignContext(new NoPhotoYetGuestPageContext(
                        step: 1,
                        loginUrl: 'identification.php',
                        deactivateUrl: $this->urlService->getRootUrl() . '?no_photo_yet=browse',
                    ));
                }

                $this->eventDispatcher->dispatchNotify(new LocEndNoPhotoYet());

                $template->pparse('no_photo_yet');
                exit();
            } else {
                $this->configService->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
